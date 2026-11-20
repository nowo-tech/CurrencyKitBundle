<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Tests\Unit\Bridge;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\CurrencyKitBundle\Application\CurrencyKitSettings;
use Nowo\CurrencyKitBundle\Application\RateMemo;
use Nowo\CurrencyKitBundle\Application\RateSynchronizerInterface;
use Nowo\CurrencyKitBundle\Application\ReferenceRateReaderInterface;
use Nowo\CurrencyKitBundle\Bridge\Scheduler\ColdStartTrigger;
use Nowo\CurrencyKitBundle\Bridge\Scheduler\CurrencyKitScheduleProvider;
use Nowo\CurrencyKitBundle\Domain\Exception\OutboundDisabledException;
use Nowo\CurrencyKitBundle\Domain\Exception\RateSourceResponseException;
use Nowo\CurrencyKitBundle\Domain\Exception\RateSourceUnavailableException;
use Nowo\CurrencyKitBundle\Domain\Rate\PublicationInfo;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use Nowo\CurrencyKitBundle\Domain\Rate\RateOrigin;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;
use Nowo\CurrencyKitBundle\Domain\Sync\SyncOptions;
use Nowo\CurrencyKitBundle\Domain\Sync\SyncOutcome;
use Nowo\CurrencyKitBundle\Domain\Sync\SyncReport;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\EventListener\RateMemoResetListener;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Messenger\SyncReferenceRatesHandler;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Messenger\SyncReferenceRatesMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Trigger\CronExpressionTrigger;
use Throwable;

use function iterator_to_array;

final class BridgesTest extends TestCase
{
    private function reader(bool $hasRates): ReferenceRateReaderInterface
    {
        return new class($hasRates) implements ReferenceRateReaderInterface {
            public function __construct(public bool $hasRates)
            {
            }

            public function latest(?SourceKey $source = null): ?PublicationInfo
            {
                $at = new DateTimeImmutable('2026-11-19 15:00:00');

                return $this->hasRates ? new PublicationInfo('id', SourceKey::of('ecb'), RateDate::of('2026-11-19'), RateOrigin::Daily, 29, 'h', $at, $at, 1) : null;
            }

            public function ratesOn(RateDate $date, ?SourceKey $source = null): array
            {
                return [];
            }

            public function history(string $currency, RateDate $from, RateDate $to, ?SourceKey $source = null): array
            {
                return [];
            }

            public function changes(RateDate $from, RateDate $to, ?SourceKey $source = null): array
            {
                return [];
            }
        };
    }

    public function testScheduleAndColdStart(): void
    {
        $schedule = (new CurrencyKitScheduleProvider($this->reader(false), new CurrencyKitSettings(SourceKey::of('ecb')), ['16:15', '17:00', '18:30'], '08:00'))->getSchedule();
        $messages = $schedule->getRecurringMessages();
        self::assertCount(5, $messages);
        $trigger = $messages[0]->getTrigger();
        self::assertInstanceOf(CronExpressionTrigger::class, $trigger);
        self::assertSame('2026-11-20 16:15', $trigger->getNextRunDate(new DateTimeImmutable('2026-11-19 17:00', new DateTimeZone('Europe/Berlin')))?->format('Y-m-d H:i'));
        self::assertSame('2026-11-23 16:15', $trigger->getNextRunDate(new DateTimeImmutable('2026-11-20 17:00', new DateTimeZone('Europe/Berlin')))?->format('Y-m-d H:i'), 'Monday to Friday');
        self::assertInstanceOf(SyncReferenceRatesMessage::class, iterator_to_array($messages[0]->getMessages(new MessageContext('n', 'id', $trigger, new DateTimeImmutable())))[0]);

        $empty = new ColdStartTrigger($this->reader(false), 30);
        $start = new DateTimeImmutable('2026-11-19 09:00');
        self::assertSame($start, $empty->getNextRunDate($start), 'fires at once on a cold start');
        $retry = $empty->getNextRunDate($start);
        self::assertSame('2026-11-19 09:30', $retry->format('Y-m-d H:i'), 'then every 30 minutes while empty');
        self::assertNull((new ColdStartTrigger($this->reader(true)))->getNextRunDate($start), 'never once a rate exists');
        self::assertStringContainsString('cold start', (string) $empty);

        // No cold-start run when a sync cannot load the first days (audit VR-14).
        foreach ([new CurrencyKitSettings(SourceKey::of('ecb'), initialLast90Days: false), new CurrencyKitSettings(SourceKey::of('ecb'), outboundEnabled: false), new CurrencyKitSettings(SourceKey::of('manual'))] as $settings) {
            $provider = new CurrencyKitScheduleProvider($this->reader(false), $settings, ['16:15'], '08:00');
            self::assertCount(2, $provider->getSchedule()->getRecurringMessages());
        }
        // A database outage never crashes the scheduler worker.
        $down = new class implements ReferenceRateReaderInterface {
            public function latest(?SourceKey $source = null): ?PublicationInfo
            {
                throw new RuntimeException('database down');
            }

            public function ratesOn(RateDate $date, ?SourceKey $source = null): array
            {
                return [];
            }

            public function history(string $currency, RateDate $from, RateDate $to, ?SourceKey $source = null): array
            {
                return [];
            }

            public function changes(RateDate $from, RateDate $to, ?SourceKey $source = null): array
            {
                return [];
            }
        };
        self::assertSame('2026-11-19 09:30', (new ColdStartTrigger($down, 30))->getNextRunDate($start)?->format('Y-m-d H:i'));
    }

    public function testHandler(): void
    {
        $settings = new CurrencyKitSettings(SourceKey::of('ecb'), retries: 2);
        $sync     = new class implements RateSynchronizerInterface {
            public ?Throwable $throw = null;

            public ?SyncOptions $options = null;

            public ?SourceKey $source = null;

            public function source(): ?SourceKey
            {
                return $this->source;
            }

            public function sync(?SourceKey $source = null, SyncOptions $options = new SyncOptions()): SyncReport
            {
                $this->source  = $source;
                $this->options = $options;
                if ($this->throw instanceof Throwable) {
                    throw $this->throw;
                }

                return new SyncReport(SourceKey::of('ecb'), SyncOutcome::Stored);
            }

            public function backfill(?SourceKey $source, RateDate $from, ?RateDate $to = null, SyncOptions $options = new SyncOptions()): SyncReport
            {
                return new SyncReport(SourceKey::of('ecb'), SyncOutcome::Stored);
            }

            public function import(string $path, ?SourceKey $source = null, string $format = 'ecb-xml', SyncOptions $options = new SyncOptions()): SyncReport
            {
                return new SyncReport(SourceKey::of('ecb'), SyncOutcome::Stored);
            }
        };
        $handler = new SyncReferenceRatesHandler($sync, $settings, new NullLogger());
        self::assertSame(SyncOutcome::Stored, $handler(new SyncReferenceRatesMessage())?->outcome);
        self::assertSame(3, $sync->options?->attempts);
        self::assertNull($sync->source);
        $handler(new SyncReferenceRatesMessage('mirror'));
        self::assertSame('mirror', $sync->source()?->value);
        $sync->throw = new OutboundDisabledException();
        self::assertNull($handler(new SyncReferenceRatesMessage()));
        $sync->throw = new RateSourceResponseException(RateSourceResponseException::REASON_MALFORMED);
        try {
            $handler(new SyncReferenceRatesMessage());
            self::fail('retried');
        } catch (UnrecoverableMessageHandlingException) {
        }
        $sync->throw = new RateSourceUnavailableException(RateSourceUnavailableException::REASON_TRANSPORT);
        $this->expectException(RateSourceUnavailableException::class);
        $handler(new SyncReferenceRatesMessage());
    }

    public function testMemoListener(): void
    {
        $memo = new RateMemo();
        $memo->set('k', null);
        (new RateMemoResetListener($memo))();
        self::assertFalse($memo->has('k'));
    }
}
