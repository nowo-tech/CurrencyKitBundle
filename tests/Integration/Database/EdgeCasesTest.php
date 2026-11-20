<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Tests\Integration\Database;

use Nowo\CurrencyKitBundle\Application\ManualRateService;
use Nowo\CurrencyKitBundle\Application\RateMaintenance;
use Nowo\CurrencyKitBundle\Domain\Currency\CurrencyCode;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidValueException;
use Nowo\CurrencyKitBundle\Domain\Exception\RateSourceResponseException;
use Nowo\CurrencyKitBundle\Domain\Exception\StaleManualRateException;
use Nowo\CurrencyKitBundle\Domain\Exception\SyncInterruptedException;
use Nowo\CurrencyKitBundle\Domain\Manual\ManualRateInput;
use Nowo\CurrencyKitBundle\Domain\Manual\ManualRateView;
use Nowo\CurrencyKitBundle\Domain\Port\RateFileReaderInterface;
use Nowo\CurrencyKitBundle\Domain\Port\RunLockInterface;
use Nowo\CurrencyKitBundle\Domain\Port\WriteRunnerInterface;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;
use Nowo\CurrencyKitBundle\Infrastructure\Support\UuidV7IdGenerator;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Security\SecurityActorResolver;
use Nowo\CurrencyKitBundle\Tests\Support\DelegatingRateStore;
use Nowo\CurrencyKitBundle\Tests\Support\FixtureHttp;
use Nowo\CurrencyKitBundle\Tests\Support\TestServices;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_map;
use function file_put_contents;
use function str_replace;
use function sys_get_temp_dir;
use function tempnam;

/**
 * Paths a normal run rarely takes: racing writers, duplicate days, a lost lease, the reader's history.
 */
final class EdgeCasesTest extends DatabaseTestCase
{
    public function testAManualRateChangedBetweenReadAndWriteIsStale(): void
    {
        $created  = $this->services->manual->create(new ManualRateInput(CurrencyCode::of('USD'), '1.1', RateDate::of('2026-11-20'), null, 'Racing edit'));
        $services = $this->services;
        $racing   = new DelegatingRateStore($services->store, true);
        $manual   = new ManualRateService($racing, $services->runner, new UuidV7IdGenerator(), new SecurityActorResolver(), $services->audit, $services->cache, $services->memo, $services->resolver, $services->settings, $services->clock);
        foreach ([
            static fn (): ManualRateView => $manual->update($created->id, new ManualRateInput(CurrencyCode::of('USD'), '1.2', RateDate::of('2026-11-20'), null, 'Racing edit'), 1),
            static fn (): ManualRateView => $manual->end($created->id, RateDate::of('2026-11-25'), 1),
        ] as $call) {
            try {
                $call();
                self::fail('a lost update');
            } catch (StaleManualRateException $e) {
                self::assertGreaterThan(105, $e->getLine(), 'thrown by the versioned UPDATE, line ' . $e->getLine() . ' of ' . $e->getFile());
            }
        }
    }

    public function testDuplicateDaysInAFeedOrAFile(): void
    {
        $xml        = FixtureHttp::fixture('ecb/eurofxref-daily.xml');
        $twice      = str_replace('</Cube>' . "\n\t" . '</Cube>', '</Cube>' . "\n\t" . '<Cube time=\'2026-11-19\'><Cube currency=\'USD\' rate=\'1.0992\'/></Cube></Cube>', $xml);
        $this->http = new FixtureHttp(['eurofxref-hist-90d.xml' => new MockResponse($twice)]);
        try {
            $this->services()->synchronizer->sync();
            self::fail('a duplicate day was accepted');
        } catch (RateSourceResponseException $e) {
            self::assertSame(RateSourceResponseException::REASON_DUPLICATE_DATE, $e->reason);
        }
        $file = (string) tempnam(sys_get_temp_dir(), 'cyk');
        file_put_contents($file, $twice);
        try {
            $this->services()->synchronizer->import($file, null, RateFileReaderInterface::FORMAT_ECB_XML);
            self::fail('a duplicate day was accepted');
        } catch (SyncInterruptedException $e) {
            self::assertSame(['2026-11-19'], $e->report->stored);
        }
    }

    public function testAStorageFailureDuringAnImportIsAnInterruption(): void
    {
        $calls   = 0;
        $runner  = $this->services->runner;
        $failing = new class($runner, $calls) implements WriteRunnerInterface {
            public function __construct(private readonly WriteRunnerInterface $inner, private int &$calls)
            {
            }

            public function write(callable $work): mixed
            {
                if (++$this->calls === 2) {
                    throw new RuntimeException('storage down');
                }

                return $this->inner->write($work);
            }
        };
        $file = (string) tempnam(sys_get_temp_dir(), 'cyk');
        file_put_contents($file, FixtureHttp::fixture('ecb/eurofxref-hist-90d.xml'));
        $services = new TestServices($this->connection, new MockClock('2026-11-19 17:00:00', 'Europe/Berlin'), $this->http->client, [], false, runner: $failing);
        $this->expectException(SyncInterruptedException::class);
        $services->synchronizer->import($file);
    }

    public function testALostLeaseIsLogged(): void
    {
        $lock = new class implements RunLockInterface {
            public function acquire(string $name, string $holder, int $ttlSeconds): int
            {
                return 0;
            }

            public function renew(string $name, string $holder, int $ttlSeconds): bool
            {
                return false;
            }

            public function release(string $name, string $holder): void
            {
            }
        };
        $services = new TestServices($this->connection, new MockClock('2026-11-19 17:00:00', 'Europe/Berlin'), $this->http->client, [], false, lock: $lock);
        self::assertCount(10, $services->synchronizer->sync()->stored, 'the run finishes; the next one is serialised by the store');
    }

    public function testAPruneFailingAfterACommittedBatchIsAnInterruption(): void
    {
        $this->seed('2025-01-01', ['USD' => '1.1']);
        $this->seed('2025-01-02', ['USD' => '1.1']);
        $calls   = 0;
        $runner  = $this->services->runner;
        $failing = new class($runner, $calls) implements WriteRunnerInterface {
            public function __construct(private readonly WriteRunnerInterface $inner, private int &$calls)
            {
            }

            public function write(callable $work): mixed
            {
                if (++$this->calls === 2) {
                    throw new RuntimeException('lock conflict');
                }

                return $this->inner->write($work);
            }
        };
        $s           = $this->services;
        $maintenance = new RateMaintenance($s->store, $failing, new UuidV7IdGenerator(), $s->audit, $s->cache, $s->memo, $s->resolver, $s->settings, $s->clock, null, 1);
        try {
            $maintenance->prune(SourceKey::of('ecb'), RateDate::of('2026-01-01'), null);
            self::fail('no interruption');
        } catch (SyncInterruptedException $e) {
            self::assertInstanceOf(RuntimeException::class, $e->getPrevious());
        }
        self::assertSame('2025-01-02', $s->reader->latest()?->rateDate->value, 'the first batch stays committed');
    }

    public function testHistoryAndFilteredChanges(): void
    {
        $this->services->synchronizer->sync();
        $history = $this->services->reader->history('USD', RateDate::of('2026-11-10'), RateDate::of('2026-11-12'));
        self::assertSame(['2026-11-10', '2026-11-11', '2026-11-12'], array_map(static fn (array $row): string => $row['date']->value, $history));
        self::assertSame('1.1214', $history[0]['rate']);
        self::assertSame([], $this->services->store->changes(SourceKey::of('ecb'), RateDate::of('2026-11-01'), RateDate::of('2026-11-30')));
        foreach ([['2026-11-12', '2026-11-10'], ['2025-01-01', '2026-11-19']] as [$from, $to]) {
            try {
                $this->services->reader->history('USD', RateDate::of($from), RateDate::of($to));
                self::fail('accepted');
            } catch (InvalidValueException) {
            }
        }
    }
}
