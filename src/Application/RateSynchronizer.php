<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Application;

use Nowo\CurrencyKitBundle\Domain\Audit\AuditEntry;
use Nowo\CurrencyKitBundle\Domain\Event\ReferenceRateChanged;
use Nowo\CurrencyKitBundle\Domain\Event\ReferenceRatesPublished;
use Nowo\CurrencyKitBundle\Domain\Event\ReferenceRateSyncFailed;
use Nowo\CurrencyKitBundle\Domain\Exception\CurrencyKitException;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidValueException;
use Nowo\CurrencyKitBundle\Domain\Exception\OutboundDisabledException;
use Nowo\CurrencyKitBundle\Domain\Exception\RateSourceResponseException;
use Nowo\CurrencyKitBundle\Domain\Exception\RateSourceUnavailableException;
use Nowo\CurrencyKitBundle\Domain\Exception\SyncInterruptedException;
use Nowo\CurrencyKitBundle\Domain\Port\CurrencyKitAuditSinkInterface;
use Nowo\CurrencyKitBundle\Domain\Port\IdGeneratorInterface;
use Nowo\CurrencyKitBundle\Domain\Port\RateFileReaderInterface;
use Nowo\CurrencyKitBundle\Domain\Port\RateSourceInterface;
use Nowo\CurrencyKitBundle\Domain\Port\RateStoreInterface;
use Nowo\CurrencyKitBundle\Domain\Port\ResolvedRateCacheInterface;
use Nowo\CurrencyKitBundle\Domain\Port\RunLockInterface;
use Nowo\CurrencyKitBundle\Domain\Port\TargetCalendarInterface;
use Nowo\CurrencyKitBundle\Domain\Port\WriteRunnerInterface;
use Nowo\CurrencyKitBundle\Domain\Rate\DayRates;
use Nowo\CurrencyKitBundle\Domain\Rate\FetchFeed;
use Nowo\CurrencyKitBundle\Domain\Rate\FetchRequest;
use Nowo\CurrencyKitBundle\Domain\Rate\PublicationInfo;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use Nowo\CurrencyKitBundle\Domain\Rate\RateOrigin;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;
use Nowo\CurrencyKitBundle\Domain\Sync\DayResult;
use Nowo\CurrencyKitBundle\Domain\Sync\SyncOptions;
use Nowo\CurrencyKitBundle\Domain\Sync\SyncOutcome;
use Nowo\CurrencyKitBundle\Domain\Sync\SyncReport;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function array_filter;
use function array_key_last;
use function array_unique;
use function array_values;
use function count;
use function in_array;
use function usort;

/**
 * Sync, backfill and import (contract section 8). Fetch and parse first, outside any transaction (REQ-RUNTIME-003
 * rule 9), validate, then one write unit per publication day, oldest first; events, audit lines and cache
 * invalidation after each commit. A lease per source keeps one run at a time across workers and hosts.
 */
final readonly class RateSynchronizer implements RateSynchronizerInterface
{
    /**
     * Builds the synchronizer.
     */
    public function __construct(
        private RateSourceRegistry $sources,
        private RateStoreInterface $store,
        private WriteRunnerInterface $runner,
        private RunLockInterface $lock,
        private BatchValidator $validator,
        private TargetCalendarInterface $calendar,
        private RateFileReaderInterface $files,
        private IdGeneratorInterface $ids,
        private CurrencyKitAuditSinkInterface $audit,
        private ResolvedRateCacheInterface $cache,
        private RateMemo $memo,
        private CurrencyKitSettings $settings,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private ?EventDispatcherInterface $dispatcher = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function sync(?SourceKey $source = null, SyncOptions $options = new SyncOptions()): SyncReport
    {
        $rateSource = $this->sources->get($source ?? $this->settings->activeSource);
        $key        = $rateSource->key();
        if (!$rateSource->supports(FetchFeed::Daily) && !$rateSource->supports(FetchFeed::Range)) {
            return new SyncReport($key, SyncOutcome::Disabled);
        }

        return $this->leased($rateSource, $options, function (string $runId) use ($rateSource, $key, $options): SyncReport {
            $today                = RateDate::fromInstant($this->clock->now(), $rateSource->timezone());
            $latest               = $this->store->latestPublication($key);
            [$request, $warnings] = $this->plan($rateSource, $latest?->rateDate, $today, $options);
            if ($request === null) {
                return new SyncReport($key, SyncOutcome::Unchanged, null, [], [], [], $warnings);
            }

            $batch = $rateSource->fetch($request);
            $days  = [];
            foreach ($batch->days as $day) {
                $days[] = $day;
            }
            usort($days, static fn (DayRates $a, DayRates $b): int => $a->date->compare($b->date));
            [$validated, $skipped] = $this->validateAll($rateSource, $days, $today, $options);
            if ($skipped !== []) {
                $warnings[] = SyncReport::WARNING_UNKNOWN_CURRENCY;
            }
            if ($request->feed === FetchFeed::Daily && $latest instanceof PublicationInfo) {
                // A lagging daily feed never rewrites an older day (contract section 8.5).
                $current = array_values(array_filter($validated, static fn (DayRates $day): bool => !$day->date->isBefore($latest->rateDate)));
                if ($current !== $validated) {
                    $warnings[] = SyncReport::WARNING_FEED_BEHIND;
                    $validated  = $current;
                }
            }

            $report = $this->storeAll($key, $validated, $batch->origin, $runId, $options, $request->feed, $warnings);
            if ($report->stored !== [] || $report->changed !== []) {
                return $report;
            }
            $newest = $validated === [] ? null : $validated[array_key_last($validated)]->date;
            if ($newest !== null && $newest->equals($today)) {
                return $report;
            }

            return new SyncReport($key, SyncOutcome::NotYetPublished, $request->feed, [], [], $report->unchanged, $warnings, $this->calendar->isBusinessDay($today));
        });
    }

    /**
     * {@inheritDoc}
     */
    public function backfill(?SourceKey $source, RateDate $from, ?RateDate $to = null, SyncOptions $options = new SyncOptions()): SyncReport
    {
        $rateSource = $this->sources->get($source ?? $this->settings->activeSource);
        $key        = $rateSource->key();
        $today      = RateDate::fromInstant($this->clock->now(), $rateSource->timezone());
        $earliest   = $rateSource->earliestDate();
        $from       = $earliest instanceof RateDate && $from->isBefore($earliest) ? $earliest : $from;
        $to         = !$to instanceof RateDate || $to->isAfter($today) ? $today : $to;
        if ($to->isBefore($from) || $from->daysUntil($to) + 1 > $this->settings->backfillMaxDays) {
            throw new InvalidValueException('The backfill range is empty or longer than backfill.max_days.');
        }
        if ($rateSource->supports(FetchFeed::FullHistory)) {
            $requests = [new FetchRequest(FetchFeed::FullHistory, null, null, $options->attempts)];
        } elseif ($rateSource->supports(FetchFeed::Range)) {
            $requests = [];
            for ($start = $from; !$start->isAfter($to); $start = $start->plusDays($this->settings->backfillChunkDays)) {
                $end        = $start->plusDays($this->settings->backfillChunkDays - 1);
                $requests[] = new FetchRequest(FetchFeed::Range, $start, $end->isAfter($to) ? $to : $end, $options->attempts);
            }
        } else {
            return new SyncReport($key, SyncOutcome::Disabled);
        }

        return $this->leased($rateSource, $options, function (string $runId) use ($rateSource, $key, $requests, $from, $to, $today, $options): SyncReport {
            $totals = new SyncReport($key, SyncOutcome::Unchanged, $requests[0]->feed);
            foreach ($requests as $request) {
                $batch  = $rateSource->fetch($request);
                $totals = $this->streamDays($rateSource, $batch->days, $batch->origin, $from, $to, $today, $runId, $options, $totals);
            }

            return $totals;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function import(string $path, ?SourceKey $source = null, string $format = RateFileReaderInterface::FORMAT_ECB_XML, SyncOptions $options = new SyncOptions()): SyncReport
    {
        $rateSource = $this->sources->get($source ?? $this->settings->activeSource);
        $key        = $rateSource->key();

        $report = $this->leased($rateSource, $options, function (string $runId) use ($rateSource, $key, $path, $format, $options): SyncReport {
            $today = RateDate::fromInstant($this->clock->now(), $rateSource->timezone());
            $days  = $this->files->read($path, $format, $this->settings->backfillMaxDays);

            return $this->streamDays($rateSource, $days, RateOrigin::Import, null, null, $today, $runId, $options, new SyncReport($key, SyncOutcome::Unchanged));
        }, false);
        $this->audit->record(new AuditEntry(AuditEntry::IMPORT, $options->actor?->value, [
            'source'    => $key->value,
            'format'    => $format,
            'stored'    => count($report->stored),
            'changed'   => count($report->changed),
            'unchanged' => count($report->unchanged),
        ]));

        return $report;
    }

    /**
     * Which feed covers the gap (contract section 8.2 step 2), with warnings.
     *
     * @return array{0: FetchRequest|null, 1: list<string>}
     */
    private function plan(RateSourceInterface $source, ?RateDate $latest, RateDate $today, SyncOptions $options): array
    {
        if ($options->feed instanceof FetchFeed) {
            return [$this->request($source, $options->feed, $latest ?? $today->plusDays(-90), $today, $options), []];
        }
        if (!$latest instanceof RateDate) {
            return $this->settings->initialLast90Days ? [$this->request($source, FetchFeed::Last90Days, $today->plusDays(-90), $today, $options), []] : [null, []];
        }
        if (!$latest->isBefore($this->calendar->previousBusinessDay($today))) {
            return [$this->request($source, FetchFeed::Daily, $latest, $today, $options), []];
        }
        $warnings = $latest->daysUntil($today) > 90 ? [SyncReport::WARNING_GAP_EXCEEDS_90_DAYS] : [];
        $from     = $latest->daysUntil($today) > 90 ? $today->plusDays(-90) : $latest->plusDays(1);

        return [$this->request($source, FetchFeed::Last90Days, $from, $today, $options), $warnings];
    }

    /**
     * The request for a feed: the feed itself when the source serves it, else a date range (API sources).
     */
    private function request(RateSourceInterface $source, FetchFeed $feed, RateDate $from, RateDate $today, SyncOptions $options): FetchRequest
    {
        if ($source->supports($feed)) {
            return new FetchRequest($feed, null, null, $options->attempts);
        }

        return new FetchRequest(FetchFeed::Range, $from->isAfter($today) ? $today : $from, $today, $options->attempts);
    }

    /**
     * Validates the whole batch before any write; a permanent failure stores nothing.
     *
     * @param list<DayRates> $days oldest first
     *
     * @throws RateSourceResponseException
     *
     * @return array{0: list<DayRates>, 1: list<string>}
     */
    private function validateAll(RateSourceInterface $source, array $days, RateDate $today, SyncOptions $options): array
    {
        $validated = [];
        $skipped   = [];
        $baseline  = null;
        $seen      = [];
        foreach ($days as $day) {
            if (isset($seen[$day->date->value])) {
                throw new RateSourceResponseException(RateSourceResponseException::REASON_DUPLICATE_DATE);
            }
            $seen[$day->date->value] = true;
            $result                  = $this->validator->validate($source->key(), $day, $today, $source->earliestDate(), $this->settings->maxDailyChangePercent, $options->acceptImplausible, $baseline);
            $skipped                 = [...$skipped, ...$result['skipped']];
            $this->auditAccepted($source->key(), $day->date, $result['accepted'], $options);
            if ($result['day']->rates === []) {
                continue;
            }
            $validated[] = $result['day'];
            $baseline    = $result['day']->rates;
        }

        return [$validated, array_values(array_unique($skipped))];
    }

    /**
     * Stores validated days, one write unit each, oldest first.
     *
     * @param list<DayRates> $days
     * @param list<string> $warnings
     *
     * @throws SyncInterruptedException when a day fails after earlier days were committed
     */
    private function storeAll(SourceKey $key, array $days, RateOrigin $origin, string $runId, SyncOptions $options, ?FetchFeed $feed, array $warnings): SyncReport
    {
        $report = new SyncReport($key, SyncOutcome::Unchanged, $feed, [], [], [], $warnings);
        foreach ($days as $day) {
            $report = $this->storeOne($key, $day, $origin, $runId, $options, $report);
        }

        return $report;
    }

    /**
     * Validates and stores days as they are read (backfill, import): memory stays flat.
     *
     * @param iterable<DayRates> $days
     */
    private function streamDays(RateSourceInterface $source, iterable $days, RateOrigin $origin, ?RateDate $from, ?RateDate $to, RateDate $today, string $runId, SyncOptions $options, SyncReport $report): SyncReport
    {
        $seen = [];
        try {
            foreach ($days as $day) {
                if (($from instanceof RateDate && $day->date->isBefore($from)) || ($to instanceof RateDate && $day->date->isAfter($to))) {
                    continue;
                }
                if (isset($seen[$day->date->value])) {
                    throw new RateSourceResponseException(RateSourceResponseException::REASON_DUPLICATE_DATE);
                }
                $seen[$day->date->value] = true;
                $result                  = $this->validator->validate($source->key(), $day, $today, $source->earliestDate(), $this->settings->maxDailyChangePercent, $options->acceptImplausible);
                $this->auditAccepted($source->key(), $day->date, $result['accepted'], $options);
                if ($result['skipped'] !== [] && !in_array(SyncReport::WARNING_UNKNOWN_CURRENCY, $report->warnings, true)) {
                    $report = $this->with($report, null, null, SyncReport::WARNING_UNKNOWN_CURRENCY);
                }
                if ($result['day']->rates !== []) {
                    $report = $this->storeOne($source->key(), $result['day'], $origin, $runId, $options, $report);
                }
            }
        } catch (SyncInterruptedException $e) {
            throw $e;
        } catch (Throwable $e) {
            if ($report->stored !== [] || $report->changed !== []) {
                throw new SyncInterruptedException($report, $e);
            }

            throw $e;
        }

        return $report;
    }

    /**
     * One day in one write unit, then events, audit and cache invalidation.
     *
     * @throws SyncInterruptedException when the write fails after earlier days were committed
     */
    private function storeOne(SourceKey $key, DayRates $day, RateOrigin $origin, string $runId, SyncOptions $options, SyncReport $report): SyncReport
    {
        try {
            /** @var DayResult $result */
            $result = $this->runner->write(fn (): DayResult => $this->store->storeDay($key, $day, $origin, $this->settings->onChangedValue, $runId, $this->clock->now()));
        } catch (Throwable $e) {
            if ($report->stored !== [] || $report->changed !== []) {
                throw new SyncInterruptedException($report, $e);
            }

            throw $e;
        }
        if (!$this->lock->renew($this->leaseName($key), $runId, $this->settings->lockTtlSeconds)) {
            $this->logger->warning('Currency kit: the sync lease was lost during the run.', ['source' => $key->value]);
        }
        if ($result->outcome === SyncOutcome::Unchanged) {
            return $this->with($report, null, $day->date->value);
        }

        $this->cache->invalidate();
        $this->memo->reset();
        if ($result->outcome === SyncOutcome::Stored) {
            $this->dispatcher?->dispatch(new ReferenceRatesPublished($key, $day->date, $result->currencyCount, $origin));
        }
        foreach ($result->changes as $change) {
            if ($change->oldRate !== null && $change->newRate !== null) {
                $this->logger->warning('Currency kit: a published reference rate changed.', ['source' => $key->value, 'date' => $day->date->value, 'currency' => $change->currency, 'applied' => $change->applied]);
            }
            $this->audit->record(new AuditEntry(AuditEntry::RATE_CHANGED, $options->actor?->value, [
                'source'   => $key->value,
                'date'     => $day->date->value,
                'currency' => $change->currency,
                'old_rate' => $change->oldRate,
                'new_rate' => $change->newRate,
                'applied'  => $change->applied,
                'run_id'   => $runId,
            ]));
            $this->dispatcher?->dispatch(new ReferenceRateChanged($key, $day->date, $change->currency, $change->oldRate, $change->newRate, $change->applied, $change->id));
        }

        return $result->outcome === SyncOutcome::Stored ? $this->with($report, $day->date->value) : $this->with($report, null, null, null, $day->date->value);
    }

    /**
     * Runs `$work` holding the source's lease; a held lease answers `Skipped` with the seconds left. Failures are
     * evented (ReferenceRateSyncFailed) and rethrown.
     *
     * @param callable(string): SyncReport $work
     * @param bool $outbound whether the run fetches (false for a local import)
     *
     * @throws OutboundDisabledException
     */
    private function leased(RateSourceInterface $source, SyncOptions $options, callable $work, bool $outbound = true): SyncReport
    {
        $key = $source->key();
        if ($outbound && !$this->settings->outboundEnabled) {
            $this->failed($key, new OutboundDisabledException());

            throw new OutboundDisabledException();
        }
        $runId = $this->ids->generate();
        $left  = $this->lock->acquire($this->leaseName($key), $runId, $this->settings->lockTtlSeconds);
        if ($left > 0) {
            return new SyncReport($key, SyncOutcome::Skipped, null, [], [], [], [], true, $left);
        }
        if ($options->manualTrigger) {
            $this->audit->record(new AuditEntry(AuditEntry::SYNC_MANUAL_TRIGGER, $options->actor?->value, ['source' => $key->value, 'run_id' => $runId]));
        }
        try {
            return $work($runId);
        } catch (Throwable $e) {
            $this->failed($key, $e);

            throw $e;
        } finally {
            $this->lock->release($this->leaseName($key), $runId);
        }
    }

    /**
     * Dispatches ReferenceRateSyncFailed.
     */
    private function failed(SourceKey $key, Throwable $e): void
    {
        $failure = $e instanceof SyncInterruptedException && $e->getPrevious() instanceof Throwable ? $e->getPrevious() : $e;
        $reason  = match (true) {
            $failure instanceof RateSourceUnavailableException => $failure->reason,
            $failure instanceof RateSourceResponseException    => $failure->reason,
            $failure instanceof CurrencyKitException           => $failure->errorCode(),
            default                                            => 'storage',
        };
        $transient = $failure instanceof RateSourceUnavailableException || !$failure instanceof CurrencyKitException;
        $this->logger->error('Currency kit: the rate sync failed.', ['source' => $key->value, 'reason' => $reason]);
        $this->dispatcher?->dispatch(new ReferenceRateSyncFailed($key, $reason, $transient));
    }

    /**
     * Audits accepted implausible changes (`--accept-implausible`).
     *
     * @param list<string> $accepted
     */
    private function auditAccepted(SourceKey $key, RateDate $date, array $accepted, SyncOptions $options): void
    {
        foreach ($accepted as $code) {
            $this->audit->record(new AuditEntry(AuditEntry::IMPLAUSIBLE_ACCEPTED, $options->actor?->value, ['source' => $key->value, 'date' => $date->value, 'currency' => $code]));
        }
    }

    /**
     * The report with one more stored, unchanged or changed date, or one more warning; the outcome follows.
     */
    private function with(SyncReport $report, ?string $stored = null, ?string $unchanged = null, ?string $warning = null, ?string $changed = null): SyncReport
    {
        $storedList    = $stored === null ? $report->stored : [...$report->stored, $stored];
        $changedList   = $changed === null ? $report->changed : [...$report->changed, $changed];
        $unchangedList = $unchanged === null ? $report->unchanged : [...$report->unchanged, $unchanged];
        $warnings      = $warning === null ? $report->warnings : [...$report->warnings, $warning];
        $outcome       = $changedList !== [] ? SyncOutcome::Changed : ($storedList !== [] ? SyncOutcome::Stored : SyncOutcome::Unchanged);

        return new SyncReport($report->source, $outcome, $report->feed, $storedList, $changedList, $unchangedList, $warnings, $report->expected, $report->retryAfter);
    }

    /**
     * The lease name of a source (≤ 64 characters).
     */
    private function leaseName(SourceKey $key): string
    {
        return 'sync_' . $key->value;
    }
}
