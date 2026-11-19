<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Port;

use DateTimeImmutable;
use Nowo\CurrencyKitBundle\Domain\Manual\ActorRef;
use Nowo\CurrencyKitBundle\Domain\Manual\ManualRateStatus;
use Nowo\CurrencyKitBundle\Domain\Manual\ManualRateView;
use Nowo\CurrencyKitBundle\Domain\Rate\DayRates;
use Nowo\CurrencyKitBundle\Domain\Rate\PublicationInfo;
use Nowo\CurrencyKitBundle\Domain\Rate\RateChange;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use Nowo\CurrencyKitBundle\Domain\Rate\RateOrigin;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;
use Nowo\CurrencyKitBundle\Domain\Sync\ChangedValuePolicy;
use Nowo\CurrencyKitBundle\Domain\Sync\DayResult;

/**
 * Storage of rates, publications, the change log and manual rates (contract sections 6 and 7.3). The default is DBAL
 * on the configured manager's connection. Write methods run inside a {@see WriteRunnerInterface} unit; reads re-check
 * every key byte for byte.
 */
interface RateStoreInterface
{
    /**
     * The newest publication of a source.
     */
    public function latestPublication(SourceKey $source): ?PublicationInfo;

    /**
     * The oldest publication of a source.
     */
    public function earliestPublication(SourceKey $source): ?PublicationInfo;

    /**
     * The newest publication on or before `$date` and on or after `$notBefore`.
     */
    public function publicationOnOrBefore(SourceKey $source, RateDate $date, RateDate $notBefore): ?PublicationInfo;

    /**
     * The newest stored rate of a currency on or before `$date` and on or after `$notBefore` (the lookback query).
     *
     * @return array{date: RateDate, rate: string}|null
     */
    public function rateOnOrBefore(SourceKey $source, string $currency, RateDate $date, RateDate $notBefore): ?array;

    /**
     * Every stored rate of one publication day.
     *
     * @return array<string, string> code => canonical value, sorted by code
     */
    public function ratesOn(SourceKey $source, RateDate $date): array;

    /**
     * The newest publication strictly before `$date` (the plausibility baseline).
     */
    public function publicationBefore(SourceKey $source, RateDate $date): ?PublicationInfo;

    /**
     * Stores one validated day (contract section 8.3). Call inside a write unit.
     *
     * @param string $runId the run's UUID, written on change rows
     */
    public function storeDay(SourceKey $source, DayRates $day, RateOrigin $origin, ChangedValuePolicy $policy, string $runId, DateTimeImmutable $now): DayResult;

    /**
     * The change log of a period (detected rows, newest first).
     *
     * @return list<RateChange>
     */
    public function changes(?SourceKey $source, RateDate $from, RateDate $to, int $limit = 500): array;

    /**
     * Applies a kept change (`applied = false`): under `FOR UPDATE` of the publication row, writes the value and a
     * new applied change row. Call inside a write unit.
     *
     * @return RateChange|null the applied change, null when it does not exist, was applied already or is a removal
     */
    public function applyChange(string $changeId, string $runId, DateTimeImmutable $now): ?RateChange;

    /**
     * Rates of one currency in a period (≤ 366 days), oldest first.
     *
     * @return list<array{date: RateDate, rate: string}>
     */
    public function history(SourceKey $source, string $currency, RateDate $from, RateDate $to): array;

    /**
     * Deletes up to `$maxDays` publication days (and their rates) before `$before`, oldest first. Changes and manual
     * rates are never pruned. Call inside a write unit.
     *
     * @return int days deleted
     */
    public function pruneBefore(SourceKey $source, RateDate $before, int $maxDays): int;

    /**
     * Publication dates whose stored rates no longer match their `content_hash` (rows changed outside the bundle).
     *
     * @return list<string>
     */
    public function verify(SourceKey $source): array;

    /**
     * The manual rate of a currency whose window covers the date (an ended rate still covers its closed window).
     */
    public function manualRateCovering(string $currency, RateDate $date): ?ManualRateView;

    /**
     * A manual rate by id.
     */
    public function manualRate(string $id): ?ManualRateView;

    /**
     * Manual rates, newest window first.
     *
     * @return list<ManualRateView>
     */
    public function manualRates(?ManualRateStatus $status, ?string $currency, int $offset, int $limit): array;

    /**
     * Locks the manual rates of a currency (`SELECT … FOR UPDATE`, ordered by id) and returns them. Call inside a
     * write unit before an overlap check.
     *
     * @return list<ManualRateView>
     */
    public function lockManualRates(string $currency): array;

    /**
     * Inserts a manual rate. Call inside a write unit.
     */
    public function insertManualRate(ManualRateView $rate): void;

    /**
     * Updates a manual rate when its version is still `$expectedVersion` (the view carries the new version).
     *
     * @return bool false when the row changed meanwhile (stale)
     */
    public function updateManualRate(ManualRateView $rate, int $expectedVersion): bool;

    /**
     * Sets the actor reference to NULL on manual rates (`created_by`, `updated_by`).
     *
     * @return int rows changed
     */
    public function forgetActor(ActorRef $actor): int;
}
