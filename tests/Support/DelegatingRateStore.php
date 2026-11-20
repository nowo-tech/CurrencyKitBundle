<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Tests\Support;

use DateTimeImmutable;
use Nowo\CurrencyKitBundle\Domain\Manual\ActorRef;
use Nowo\CurrencyKitBundle\Domain\Manual\ManualRateStatus;
use Nowo\CurrencyKitBundle\Domain\Manual\ManualRateView;
use Nowo\CurrencyKitBundle\Domain\Port\RateStoreInterface;
use Nowo\CurrencyKitBundle\Domain\Rate\DayRates;
use Nowo\CurrencyKitBundle\Domain\Rate\PublicationInfo;
use Nowo\CurrencyKitBundle\Domain\Rate\RateChange;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use Nowo\CurrencyKitBundle\Domain\Rate\RateOrigin;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;
use Nowo\CurrencyKitBundle\Domain\Sync\ChangedValuePolicy;
use Nowo\CurrencyKitBundle\Domain\Sync\DayResult;

/**
 * Delegates to a real store; `loseUpdates` makes every manual-rate update lose the race (another writer bumped the
 * version between the read and the write).
 */
final class DelegatingRateStore implements RateStoreInterface
{
    public function __construct(private readonly RateStoreInterface $inner, public bool $loseUpdates = false)
    {
    }

    public function latestPublication(SourceKey $source): ?PublicationInfo
    {
        return $this->inner->latestPublication($source);
    }

    public function earliestPublication(SourceKey $source): ?PublicationInfo
    {
        return $this->inner->earliestPublication($source);
    }

    public function publicationOnOrBefore(SourceKey $source, RateDate $date, RateDate $notBefore): ?PublicationInfo
    {
        return $this->inner->publicationOnOrBefore($source, $date, $notBefore);
    }

    public function rateOnOrBefore(SourceKey $source, string $currency, RateDate $date, RateDate $notBefore): ?array
    {
        return $this->inner->rateOnOrBefore($source, $currency, $date, $notBefore);
    }

    public function ratesOn(SourceKey $source, RateDate $date): array
    {
        return $this->inner->ratesOn($source, $date);
    }

    public function publicationBefore(SourceKey $source, RateDate $date): ?PublicationInfo
    {
        return $this->inner->publicationBefore($source, $date);
    }

    public function storeDay(SourceKey $source, DayRates $day, RateOrigin $origin, ChangedValuePolicy $policy, string $runId, DateTimeImmutable $now): DayResult
    {
        return $this->inner->storeDay($source, $day, $origin, $policy, $runId, $now);
    }

    public function changes(?SourceKey $source, RateDate $from, RateDate $to, int $limit = 500): array
    {
        return $this->inner->changes($source, $from, $to, $limit);
    }

    public function applyChange(string $changeId, string $runId, DateTimeImmutable $now): ?RateChange
    {
        return $this->inner->applyChange($changeId, $runId, $now);
    }

    public function history(SourceKey $source, string $currency, RateDate $from, RateDate $to): array
    {
        return $this->inner->history($source, $currency, $from, $to);
    }

    public function pruneBefore(SourceKey $source, RateDate $before, int $maxDays): int
    {
        return $this->inner->pruneBefore($source, $before, $maxDays);
    }

    public function verify(SourceKey $source): array
    {
        return $this->inner->verify($source);
    }

    public function manualRateCovering(string $currency, RateDate $date): ?ManualRateView
    {
        return $this->inner->manualRateCovering($currency, $date);
    }

    public function manualRate(string $id): ?ManualRateView
    {
        return $this->inner->manualRate($id);
    }

    public function manualRates(?ManualRateStatus $status, ?string $currency, int $offset, int $limit): array
    {
        return $this->inner->manualRates($status, $currency, $offset, $limit);
    }

    public function lockManualRates(string $currency): array
    {
        return $this->inner->lockManualRates($currency);
    }

    public function insertManualRate(ManualRateView $rate): void
    {
        $this->inner->insertManualRate($rate);
    }

    public function updateManualRate(ManualRateView $rate, int $expectedVersion): bool
    {
        return !$this->loseUpdates && $this->inner->updateManualRate($rate, $expectedVersion);
    }

    public function forgetActor(ActorRef $actor): int
    {
        return $this->inner->forgetActor($actor);
    }
}
