<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Tests\Unit\Infrastructure;

use DateTimeZone;
use Doctrine\DBAL\DriverManager;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use InvalidArgumentException;
use Nowo\CurrencyKitBundle\Domain\Audit\AuditEntry;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidValueException;
use Nowo\CurrencyKitBundle\Domain\Port\OutboundFetcherInterface;
use Nowo\CurrencyKitBundle\Domain\Rate\FetchedBody;
use Nowo\CurrencyKitBundle\Domain\Rate\FetchFeed;
use Nowo\CurrencyKitBundle\Domain\Rate\FetchLimits;
use Nowo\CurrencyKitBundle\Domain\Rate\FetchRequest;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use Nowo\CurrencyKitBundle\Domain\Rate\RateOrigin;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceLabel;
use Nowo\CurrencyKitBundle\Infrastructure\Cache\Psr6ResolvedRateCache;
use Nowo\CurrencyKitBundle\Infrastructure\Dbal\ConnectionOnlyRegistry;
use Nowo\CurrencyKitBundle\Infrastructure\Source\Ecb\EcbXmlParser;
use Nowo\CurrencyKitBundle\Infrastructure\Source\Ecb\EcbXmlSource;
use Nowo\CurrencyKitBundle\Infrastructure\Source\Frankfurter\FrankfurterCompatibleSource;
use Nowo\CurrencyKitBundle\Infrastructure\Source\Frankfurter\FrankfurterJsonParser;
use Nowo\CurrencyKitBundle\Infrastructure\Source\Manual\ManualRateSource;
use Nowo\CurrencyKitBundle\Infrastructure\Support\PsrLogAuditSink;
use Nowo\CurrencyKitBundle\Infrastructure\Support\UuidV7IdGenerator;
use Nowo\CurrencyKitBundle\Tests\Support\FixtureHttp;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use stdClass;
use Stringable;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

use function hash;
use function iterator_to_array;

final class SourcesAndSupportTest extends TestCase
{
    /** @var list<array{string, FetchLimits, bool, int}> */
    private array $gets = [];

    private function fetcher(string $body): OutboundFetcherInterface
    {
        $gets = &$this->gets;

        return new class($body, $gets) implements OutboundFetcherInterface {
            /**
             * @param list<mixed> $gets
             */
            public function __construct(private string $body, private array &$gets) // @phpstan-ignore property.onlyWritten
            {
            }

            public function get(string $url, FetchLimits $limits, bool $allowPrivate = false, int $attempts = 1): FetchedBody
            {
                $this->gets[] = [$url, $limits, $allowPrivate, $attempts];

                return new FetchedBody($this->body);
            }
        };
    }

    public function testEcbSourceFeeds(): void
    {
        $source = new EcbXmlSource($this->fetcher(FixtureHttp::fixture('ecb/eurofxref-daily.xml')), new EcbXmlParser(), new SourceLabel('ECB'), 'https://d', 'https://n', 'https://h', ['daily' => 1000, 'last_90_days' => 2000, 'history' => 3000], 10.0, 30.0, 100);
        self::assertSame('ecb', $source->key()->value);
        self::assertSame('ECB', $source->label()->value);
        self::assertSame('1999-01-04', $source->earliestDate()->value);
        self::assertSame('Europe/Berlin', $source->timezone()->getName());
        self::assertFalse($source->supports(FetchFeed::Range));
        self::assertTrue($source->supports(FetchFeed::FullHistory));
        foreach ([[FetchFeed::Daily, 'https://d', 1000, 30.0, RateOrigin::Daily], [FetchFeed::Last90Days, 'https://n', 2000, 30.0, RateOrigin::History90], [FetchFeed::FullHistory, 'https://h', 3000, 120.0, RateOrigin::History]] as $i => [$feed, $url, $bytes, $duration, $origin]) {
            $batch = $source->fetch(new FetchRequest($feed, null, null, 3));
            self::assertSame($origin, $batch->origin);
            self::assertCount(1, iterator_to_array($batch->days, false));
            self::assertSame([$url, $bytes, $duration, false, 3], [$this->gets[$i][0], $this->gets[$i][1]->maxBytes, $this->gets[$i][1]->maxDuration, $this->gets[$i][2], $this->gets[$i][3]]);
        }
        $this->expectException(InvalidValueException::class);
        $source->fetch(new FetchRequest(FetchFeed::Range, RateDate::of('2026-11-01'), RateDate::of('2026-11-02')));
    }

    public function testMirrorAndManualSources(): void
    {
        $mirror = new FrankfurterCompatibleSource($this->fetcher(FixtureHttp::fixture('frankfurter/latest.json')), new FrankfurterJsonParser(), SourceKey::of('mirror'), new SourceLabel('ECB'), 'https://mirror.private.test/v1/', new DateTimeZone('Europe/Berlin'), true, false, 1000, 10.0, 30.0, 100);
        self::assertSame(['mirror', 'ECB', '1999-01-04', 'Europe/Berlin'], [$mirror->key()->value, $mirror->label()->value, $mirror->earliestDate()->value, $mirror->timezone()->getName()]);
        self::assertTrue($mirror->supports(FetchFeed::Daily) && $mirror->supports(FetchFeed::Range) && !$mirror->supports(FetchFeed::Last90Days));
        $mirror->fetch(new FetchRequest(FetchFeed::Daily));
        self::assertSame('https://mirror.private.test/v1/latest?base=EUR', $this->gets[0][0]);
        self::assertTrue($this->gets[0][2], 'private network by flag');
        self::assertSame('json', $this->gets[0][1]->format);
        $mirror->fetch(new FetchRequest(FetchFeed::Range, RateDate::of('2026-11-10'), RateDate::of('2026-11-14')));
        self::assertSame('https://mirror.private.test/v1/2026-11-10..2026-11-14?base=EUR', $this->gets[1][0]);
        try {
            $mirror->fetch(new FetchRequest(FetchFeed::FullHistory));
            self::fail('served');
        } catch (InvalidValueException) {
        }

        $manual = new ManualRateSource(new SourceLabel('Manual'));
        self::assertSame(['manual', 'Manual', null, 'Europe/Berlin'], [$manual->key()->value, $manual->label()->value, $manual->earliestDate(), $manual->timezone()->getName()]);
        self::assertFalse($manual->supports(FetchFeed::Daily));
        $this->expectException(InvalidValueException::class);
        $manual->fetch(new FetchRequest(FetchFeed::Daily));
    }

    public function testSupportServices(): void
    {
        $ids = new UuidV7IdGenerator();
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $ids->generate());

        $logger = new class extends AbstractLogger {
            /** @var list<array{mixed, string, array<mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message, $context];
            }
        };
        (new PsrLogAuditSink($logger))->record(new AuditEntry(AuditEntry::PRUNE, 'ops', ['days' => 3]));
        self::assertSame(['info', 'Currency kit audit.', ['action' => 'currency_kit.prune', 'actor' => 'ops', 'days' => 3]], $logger->records[0]);

        $pool  = new ArrayAdapter();
        $cache = new Psr6ResolvedRateCache($pool, 300);
        self::assertNull($cache->get('k'));
        $cache->set('k', ['rate' => '1.1', 'id' => null]);
        self::assertSame(['rate' => '1.1', 'id' => null], $cache->get('k'));
        $cache->invalidate();
        self::assertNull($cache->get('k'), 'a new version hides old entries');
        self::assertTrue($cache->claimOnce('stale|ecb', 3600));
        self::assertFalse($cache->claimOnce('stale|ecb', 3600));
        $item    = $pool->getItem('nowo_currency_kit.rates_version');
        $version = $item->get();
        self::assertIsString($version);
        $raw = $pool->getItem('nowo_currency_kit.' . hash('xxh128', $version . '|bad'));
        $raw->set('not an array');
        $pool->save($raw);
        self::assertNull($cache->get('bad'));
        $raw->set(['ok' => 'v', 3 => 'int key', 'x' => 1]);
        $pool->save($raw);
        self::assertSame(['ok' => 'v'], $cache->get('bad'));

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $registry   = new ConnectionOnlyRegistry($connection);
        self::assertSame($connection, $registry->getConnection());
        self::assertSame(['nowo_currency_kit' => $connection], $registry->getConnections());
        self::assertSame(['nowo_currency_kit' => 'nowo_currency_kit'], $registry->getConnectionNames());
        self::assertSame('nowo_currency_kit', $registry->getDefaultConnectionName());
        self::assertSame('nowo_currency_kit', $registry->getDefaultManagerName());
        self::assertSame([], $registry->getManagers());
        self::assertSame([], $registry->getManagerNames());
        self::assertNull($registry->getManagerForClass(stdClass::class));
        foreach ([static fn (): ObjectManager => $registry->getManager(), static fn (): ObjectManager => $registry->resetManager(), static fn (): ObjectRepository => $registry->getRepository(stdClass::class)] as $call) {
            try {
                $call();
                self::fail('a manager');
            } catch (InvalidArgumentException) {
            }
        }
    }
}
