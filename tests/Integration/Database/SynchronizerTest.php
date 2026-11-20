<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Tests\Integration\Database;

use Nowo\CurrencyKitBundle\Domain\Audit\AuditEntry;
use Nowo\CurrencyKitBundle\Domain\Conversion\ConversionOptions;
use Nowo\CurrencyKitBundle\Domain\Event\ReferenceRateChanged;
use Nowo\CurrencyKitBundle\Domain\Event\ReferenceRatesPublished;
use Nowo\CurrencyKitBundle\Domain\Event\ReferenceRateSyncFailed;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidValueException;
use Nowo\CurrencyKitBundle\Domain\Exception\OutboundDisabledException;
use Nowo\CurrencyKitBundle\Domain\Exception\RateSourceResponseException;
use Nowo\CurrencyKitBundle\Domain\Exception\RateSourceUnavailableException;
use Nowo\CurrencyKitBundle\Domain\Exception\SyncInterruptedException;
use Nowo\CurrencyKitBundle\Domain\Manual\ActorRef;
use Nowo\CurrencyKitBundle\Domain\Port\RateFileReaderInterface;
use Nowo\CurrencyKitBundle\Domain\Port\WriteRunnerInterface;
use Nowo\CurrencyKitBundle\Domain\Rate\FetchFeed;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;
use Nowo\CurrencyKitBundle\Domain\Sync\ChangedValuePolicy;
use Nowo\CurrencyKitBundle\Domain\Sync\SyncOptions;
use Nowo\CurrencyKitBundle\Domain\Sync\SyncOutcome;
use Nowo\CurrencyKitBundle\Domain\Sync\SyncReport;
use Nowo\CurrencyKitBundle\Tests\Support\EcbXml;
use Nowo\CurrencyKitBundle\Tests\Support\FixtureHttp;
use Nowo\CurrencyKitBundle\Tests\Support\TestServices;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;

use function count;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;

final class SynchronizerTest extends DatabaseTestCase
{
    public function testFirstInstallLoadsTheLast90DaysThenDaily(): void
    {
        $report = $this->services->synchronizer->sync();
        self::assertSame(SyncOutcome::Stored, $report->outcome);
        self::assertSame(FetchFeed::Last90Days, $report->feed);
        self::assertCount(10, $report->stored);
        self::assertSame('2026-11-06', $report->stored[0], 'oldest first');
        self::assertCount(10, $this->services->events->of(ReferenceRatesPublished::class));
        self::assertStringContainsString('eurofxref-hist-90d.xml', $this->http->requests[0]['url']);
        self::assertSame(29, $this->services->reader->latest()?->currencyCount);

        $again = $this->services->synchronizer->sync();
        self::assertSame(FetchFeed::Daily, $again->feed);
        self::assertSame(SyncOutcome::Unchanged, $again->outcome);
        self::assertSame(['2026-11-19'], $again->unchanged);
        self::assertStringContainsString('eurofxref-daily.xml', $this->http->requests[1]['url']);
        $headers = $this->http->requests[1]['options']['headers'] ?? [];
        self::assertIsArray($headers);
        self::assertContains('User-Agent: nowo-currency-kit/test (+https://github.com/nowo-tech/CurrencyKitBundle)', $headers);
        self::assertSame(0, $this->http->requests[1]['options']['max_redirects']);
    }

    public function testNotYetPublishedOnABusinessDayAndOnAHoliday(): void
    {
        $this->services->synchronizer->sync();
        $morning = $this->services([], '2026-11-20 10:00:00');
        $report  = $morning->synchronizer->sync();
        self::assertSame(SyncOutcome::NotYetPublished, $report->outcome);
        self::assertTrue($report->expected);

        $this->http = new FixtureHttp(['eurofxref-daily.xml' => new MockResponse(EcbXml::of(['2026-12-24' => ['USD' => '1.1']]))]);
        $this->seed('2026-12-24', ['USD' => '1.1']);
        $holiday = $this->services([], '2026-12-25 18:00:00')->synchronizer->sync();
        self::assertSame(SyncOutcome::NotYetPublished, $holiday->outcome);
        self::assertFalse($holiday->expected);
    }

    public function testGapsPickTheFeed(): void
    {
        $this->seed('2026-11-10', ['USD' => '1.1214']);
        $report = $this->services->synchronizer->sync();
        self::assertSame(FetchFeed::Last90Days, $report->feed);
        self::assertSame([], $report->warnings);

        $this->seed('2026-01-02', ['USD' => '1.1'], 'mirror');
        $this->http = new FixtureHttp(['/latest' => 'frankfurter/latest.json', '..' => 'frankfurter/range-2026-11-10..2026-11-14.json']);
        $mirror     = $this->services(['activeSource' => new SourceKey('mirror')], '2026-11-19 17:00:00', true);
        $gap        = $mirror->synchronizer->sync();
        self::assertSame([SyncReport::WARNING_GAP_EXCEEDS_90_DAYS], $gap->warnings);
        self::assertSame(FetchFeed::Range, $gap->feed);
        self::assertStringContainsString('/v1/2026-08-21..2026-11-19?base=EUR', $this->http->requests[0]['url']);
        self::assertSame(['2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13'], $gap->stored);
        $daily = $mirror->synchronizer->sync();
        self::assertSame(FetchFeed::Range, $daily->feed, 'the gap since 11-13 is a range for an API source');
        self::assertStringContainsString('/v1/2026-11-14..2026-11-19?base=EUR', $this->http->requests[1]['url']);
    }

    public function testInitialNoneAndForcedFeed(): void
    {
        $none = $this->services(['initialLast90Days' => false])->synchronizer->sync();
        self::assertSame(SyncOutcome::Unchanged, $none->outcome);
        self::assertNull($none->feed);
        $forced = $this->services->synchronizer->sync(null, new SyncOptions(FetchFeed::Daily));
        self::assertSame(['2026-11-19'], $forced->stored);
    }

    public function testChangedValueReplaceKeepAndApply(): void
    {
        $this->services->synchronizer->sync();
        $this->http = new FixtureHttp(['eurofxref-daily.xml' => new MockResponse(EcbXml::of(['2026-11-19' => ['USD' => '1.1000', 'JPY' => '175.84', 'NOK' => '10.6475']]))]);
        $replace    = $this->services();
        $report     = $replace->synchronizer->sync();
        self::assertSame(SyncOutcome::Changed, $report->outcome);
        self::assertSame(['2026-11-19'], $report->changed);
        $changes = $replace->events->of(ReferenceRateChanged::class);
        self::assertSame('USD', $changes[0]->currency);
        self::assertSame('1.0992', $changes[0]->oldRate);
        self::assertSame('1.1', $changes[0]->newRate);
        self::assertTrue($changes[0]->applied);
        self::assertCount(27, $changes, 'USD changed; 26 currencies missing from the feed are recorded, never deleted');
        self::assertSame('1.1', $replace->reader->ratesOn(RateDate::of('2026-11-19'))['USD']);
        self::assertSame('0.83581', $replace->reader->ratesOn(RateDate::of('2026-11-19'))['GBP'], 'a removed currency stays');
        self::assertContains(AuditEntry::RATE_CHANGED, $replace->audit->actions());

        $repeat = $this->services()->synchronizer->sync();
        self::assertSame(SyncOutcome::Unchanged, $repeat->outcome, 'the same change is never recorded twice');

        $this->http = new FixtureHttp(['eurofxref-daily.xml' => new MockResponse(EcbXml::of(['2026-11-19' => ['USD' => '1.2000', 'JPY' => '175.84', 'NOK' => '10.6475']]))]);
        $keep       = $this->services(['onChangedValue' => ChangedValuePolicy::Keep, 'maxDailyChangePercent' => 1000]);
        $kept       = $keep->synchronizer->sync();
        self::assertSame(SyncOutcome::Changed, $kept->outcome);
        self::assertSame('1.1', $keep->reader->ratesOn(RateDate::of('2026-11-19'))['USD']);
        $change = $keep->events->of(ReferenceRateChanged::class)[0];
        self::assertFalse($change->applied);
        self::assertSame(SyncOutcome::Unchanged, $keep->synchronizer->sync()->outcome);

        $applied = $keep->maintenance->applyChange($change->changeId, new ActorRef('ops'));
        self::assertNotNull($applied);
        self::assertTrue($applied->applied);
        self::assertSame('1.2', $keep->reader->ratesOn(RateDate::of('2026-11-19'))['USD']);
        self::assertNull($keep->maintenance->applyChange($change->changeId, null), 'already applied');
        self::assertNull($keep->maintenance->applyChange('00000000-0000-0000-0000-000000000000', null));
        $removal = null;
        foreach ($replace->events->of(ReferenceRateChanged::class) as $event) {
            $removal = $event->newRate === null ? $event : $removal;
        }
        self::assertNotNull($removal);
        self::assertNull($keep->maintenance->applyChange($removal->changeId, null), 'a removal is never applied');
        self::assertContains(AuditEntry::CHANGE_APPLIED, $keep->audit->actions());
        self::assertGreaterThan(0, count($keep->reader->changes(RateDate::of('2026-11-01'), RateDate::of('2026-11-30'))));
        self::assertSame([], $keep->maintenance->verify(SourceKey::of('ecb')));
    }

    public function testAddedCurrencyIsRecorded(): void
    {
        $this->seed('2026-11-19', ['USD' => '1.0992']);
        $report = $this->services->synchronizer->sync(null, new SyncOptions(FetchFeed::Daily));
        self::assertSame(SyncOutcome::Changed, $report->outcome);
        self::assertCount(28, $this->services->events->of(ReferenceRateChanged::class));
        self::assertNull($this->services->events->of(ReferenceRateChanged::class)[0]->oldRate);
    }

    public function testImplausibleChangeRefusedThenAccepted(): void
    {
        $this->seed('2026-11-18', ['USD' => '0.5']);
        try {
            $this->services->synchronizer->sync(null, new SyncOptions(FetchFeed::Daily));
            self::fail('implausible stored');
        } catch (RateSourceResponseException $e) {
            self::assertSame(RateSourceResponseException::REASON_IMPLAUSIBLE_CHANGE, $e->reason);
        }
        self::assertCount(1, $this->services->events->of(ReferenceRateSyncFailed::class));
        self::assertFalse($this->services->events->of(ReferenceRateSyncFailed::class)[0]->transient);
        $report = $this->services->synchronizer->sync(null, new SyncOptions(FetchFeed::Daily, ['USD'], 1, new ActorRef('ops'), true));
        self::assertSame(['2026-11-19'], $report->stored);
        self::assertContains(AuditEntry::IMPLAUSIBLE_ACCEPTED, $this->services->audit->actions());
        self::assertContains(AuditEntry::SYNC_MANUAL_TRIGGER, $this->services->audit->actions());
    }

    public function testFeedValidation(): void
    {
        foreach ([
            'future'    => [['2026-11-20' => ['USD' => '1.1']], RateSourceResponseException::REASON_INVALID_VALUE],
            'bad value' => [['2026-11-19' => ['USD' => '-1']], RateSourceResponseException::REASON_INVALID_VALUE],
            'exponent'  => [['2026-11-19' => ['USD' => '1e3']], RateSourceResponseException::REASON_INVALID_VALUE],
            'too old'   => [['1998-12-31' => ['USD' => '1.1']], RateSourceResponseException::REASON_INVALID_VALUE],
        ] as $case => [$days, $reason]) {
            $this->http = new FixtureHttp(['eurofxref-hist-90d.xml' => new MockResponse(EcbXml::of($days))]);
            try {
                $this->services()->synchronizer->sync();
                self::fail('accepted ' . $case);
            } catch (RateSourceResponseException $e) {
                self::assertSame($reason, $e->reason, $case);
            }
        }
        self::assertNull($this->services->reader->latest(), 'a permanent failure stores nothing');

        $this->http = new FixtureHttp(['eurofxref-hist-90d.xml' => new MockResponse(EcbXml::of(['2026-11-18' => ['USD' => '1.1'], '2026-11-19' => ['USD' => '1.1', 'ABC' => '2', 'EUR' => '1', 'XAU' => '3']]))]);
        $report     = $this->services()->synchronizer->sync();
        self::assertSame([SyncReport::WARNING_UNKNOWN_CURRENCY], $report->warnings);
        self::assertSame(['USD' => '1.1'], $this->services->reader->ratesOn(RateDate::of('2026-11-19')));

        $this->http = new FixtureHttp(['eurofxref-daily.xml' => new MockResponse(EcbXml::of(['2026-11-17' => ['USD' => '1.1']]))]);
        $behind     = $this->services()->synchronizer->sync();
        self::assertContains(SyncReport::WARNING_FEED_BEHIND, $behind->warnings);
        self::assertSame(SyncOutcome::NotYetPublished, $behind->outcome);

        $this->http = new FixtureHttp(['eurofxref-daily.xml' => new MockResponse(EcbXml::of(['2026-11-19' => ['ABC' => '1']]))]);
        self::assertSame(SyncOutcome::NotYetPublished, $this->services()->synchronizer->sync()->outcome, 'a day with no usable currency is not stored');
    }

    public function testDuplicateDatesInMirrorFeedAreRefused(): void
    {
        $this->http = new FixtureHttp(['..' => new MockResponse('{"amount":1.0,"base":"EUR","start_date":"2026-11-10","end_date":"2026-11-11","rates":{"2026-11-10":{"USD":1.1},"2026-11-10 ":{"USD":1.1}}}')]);
        try {
            $this->services(['activeSource' => new SourceKey('mirror')], '2026-11-19 17:00:00', true)->synchronizer->sync();
            self::fail('accepted');
        } catch (RateSourceResponseException $e) {
            self::assertSame(RateSourceResponseException::REASON_INVALID_VALUE, $e->reason);
        }
    }

    public function testOutboundDisabledTransientAndPermanent(): void
    {
        try {
            $this->services(['outboundEnabled' => false])->synchronizer->sync();
            self::fail('synced');
        } catch (OutboundDisabledException) {
        }
        self::assertSame([], $this->http->requests, 'no request, no DNS');

        $this->http = new FixtureHttp(['eurofxref-hist-90d.xml' => [new MockResponse('', ['http_code' => 503]), new MockResponse('', ['http_code' => 502]), new MockResponse('', ['http_code' => 500])]]);
        $services   = $this->services(['retries' => 2]);
        try {
            $services->synchronizer->sync(null, new SyncOptions(null, [], 3));
            self::fail('synced');
        } catch (RateSourceUnavailableException $e) {
            self::assertSame(RateSourceUnavailableException::REASON_SERVER_ERROR, $e->reason);
        }
        self::assertCount(3, $this->http->requests);
        self::assertTrue($services->events->of(ReferenceRateSyncFailed::class)[0]->transient);

        $this->http = new FixtureHttp(['eurofxref-hist-90d.xml' => [new MockResponse('', ['http_code' => 503]), new MockResponse(FixtureHttp::fixture('ecb/eurofxref-hist-90d.xml'))]]);
        $report     = $this->services()->synchronizer->sync(null, new SyncOptions(null, [], 3));
        self::assertCount(10, $report->stored, 'retried after a transient failure');
    }

    public function testLeaseHeldSkips(): void
    {
        self::assertSame(0, $this->services->lock->acquire('sync_ecb', 'other-run', 600));
        $report = $this->services->synchronizer->sync();
        self::assertSame(SyncOutcome::Skipped, $report->outcome);
        self::assertGreaterThan(590, $report->retryAfter);
        self::assertSame([], $this->http->requests);
    }

    public function testManualSourceIsDisabledForSync(): void
    {
        $report = $this->services->synchronizer->sync(SourceKey::of('manual'));
        self::assertSame(SyncOutcome::Disabled, $report->outcome);
        self::assertSame(SyncOutcome::Disabled, $this->services->synchronizer->backfill(SourceKey::of('manual'), RateDate::of('2026-01-01'))->outcome);
    }

    public function testBackfillFromTheHistoryFile(): void
    {
        $report = $this->services->synchronizer->backfill(null, RateDate::of('1990-01-01'), RateDate::of('2022-03-01'), new SyncOptions(null, [], 1, new ActorRef('ops'), true));
        self::assertSame(FetchFeed::FullHistory, $report->feed);
        self::assertSame(['2022-03-01', '2008-01-02', '2007-12-31', '2007-12-28', '1999-01-05', '1999-01-04'], $report->stored);
        // Historic currencies convert on past dates.
        $cyp = $this->services->converter->convert('1', 'EUR', 'CYP', null, new ConversionOptions(onDate: RateDate::of('2007-12-31')));
        self::assertSame('0.59', $cyp->amount->amount);
        $again = $this->services->synchronizer->backfill(null, RateDate::of('1999-01-04'), RateDate::of('2022-03-01'));
        self::assertCount(6, $again->unchanged, 'resumable');
        try {
            $this->services->synchronizer->backfill(null, RateDate::of('2026-11-19'), RateDate::of('2026-01-01'));
            self::fail('accepted');
        } catch (InvalidValueException) {
        }
        $this->expectException(InvalidValueException::class);
        $this->services(['backfillMaxDays' => 10])->synchronizer->backfill(null, RateDate::of('2026-01-01'));
    }

    public function testBackfillInChunksFromAMirror(): void
    {
        $this->http = new FixtureHttp(['..' => 'frankfurter/range-2026-11-10..2026-11-14.json']);
        $services   = $this->services(['activeSource' => new SourceKey('mirror'), 'backfillChunkDays' => 3], '2026-11-19 17:00:00', true);
        $report     = $services->synchronizer->backfill(null, RateDate::of('2026-11-10'), RateDate::of('2026-11-14'));
        self::assertSame(['2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13'], $report->stored);
        self::assertCount(2, $this->http->requests);
        self::assertStringContainsString('/2026-11-10..2026-11-12?base=EUR', $this->http->requests[0]['url']);
        self::assertStringContainsString('/2026-11-13..2026-11-14?base=EUR', $this->http->requests[1]['url']);
    }

    public function testImportXmlAndCsv(): void
    {
        $xml = (string) tempnam(sys_get_temp_dir(), 'cyk');
        file_put_contents($xml, FixtureHttp::fixture('ecb/eurofxref-hist-90d.xml'));
        $airGapped = $this->services(['outboundEnabled' => false]);
        $report    = $airGapped->synchronizer->import($xml, null, RateFileReaderInterface::FORMAT_ECB_XML, new SyncOptions(null, [], 1, new ActorRef('ops')));
        self::assertCount(10, $report->stored);
        self::assertSame('import', $this->services->reader->latest()?->origin->value);
        self::assertContains(AuditEntry::IMPORT, $airGapped->audit->actions());

        $csv = (string) tempnam(sys_get_temp_dir(), 'cyk');
        file_put_contents($csv, "date,currency,rate\n2026-11-20,USD,1.2\n2026-11-20,ABC,2\n");
        $late      = $this->services([], '2026-11-20 17:00:00');
        $csvReport = $late->synchronizer->import($csv, null, RateFileReaderInterface::FORMAT_CSV);
        self::assertSame(['2026-11-20'], $csvReport->stored);
        self::assertSame([SyncReport::WARNING_UNKNOWN_CURRENCY], $csvReport->warnings);

        file_put_contents($csv, "date,currency,rate\n2026-11-20,USD,1.2\n2026-11-21,USD,9\n");
        try {
            $this->services([], '2026-11-21 17:00:00')->synchronizer->import($csv, null, RateFileReaderInterface::FORMAT_CSV);
            self::fail('implausible stored');
        } catch (RateSourceResponseException $e) {
            self::assertSame(RateSourceResponseException::REASON_IMPLAUSIBLE_CHANGE, $e->reason);
        }
        file_put_contents($csv, "date,currency,rate\n2026-11-22,USD,1.2\n2026-11-23,USD,1.2\n2026-11-22,USD,1.2\n");
        try {
            $this->services([], '2026-11-23 17:00:00')->synchronizer->import($csv, null, RateFileReaderInterface::FORMAT_CSV);
            self::fail('duplicate stored');
        } catch (SyncInterruptedException $e) {
            self::assertSame(['2026-11-22', '2026-11-23'], $e->report->stored);
            self::assertInstanceOf(RateSourceResponseException::class, $e->getPrevious());
        }
    }

    public function testStorageFailureAfterACommittedDayIsAnInterruption(): void
    {
        $inner   = $this->services->runner;
        $failing = new class($inner) implements WriteRunnerInterface {
            private int $calls = 0;

            public function __construct(private readonly WriteRunnerInterface $inner)
            {
            }

            public function write(callable $work): mixed
            {
                if (++$this->calls === 3) {
                    throw new RuntimeException('storage down');
                }

                return $this->inner->write($work);
            }
        };
        $services = new TestServices($this->connection, new MockClock('2026-11-19 17:00:00', 'Europe/Berlin'), $this->http->client, [], false, runner: $failing);
        try {
            $services->synchronizer->sync();
            self::fail('no failure');
        } catch (SyncInterruptedException $e) {
            self::assertSame(['2026-11-06', '2026-11-09'], $e->report->stored);
        }
        self::assertSame('storage', $services->events->of(ReferenceRateSyncFailed::class)[0]->reason);
        $first = new class implements WriteRunnerInterface {
            public function write(callable $work): mixed
            {
                throw new RuntimeException('storage down');
            }
        };
        $this->expectException(RuntimeException::class);
        (new TestServices($this->connection, new MockClock('2026-11-19 17:00:00', 'Europe/Berlin'), $this->http->client, [], false, runner: $first))->synchronizer->sync();
    }
}
