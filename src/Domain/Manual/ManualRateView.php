<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Manual;

use DateTimeImmutable;
use Nowo\CurrencyKitBundle\Domain\Currency\CurrencyCode;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;

/**
 * A stored manual rate.
 */
final readonly class ManualRateView
{
    /**
     * Builds the view.
     */
    public function __construct(
        public string $id,
        public CurrencyCode $currency,
        public string $rate,
        public RateDate $validFrom,
        public ?RateDate $validUntil,
        public string $reason,
        public ManualRateStatus $status,
        public ?ActorRef $createdBy,
        public DateTimeImmutable $createdAt,
        public ?ActorRef $updatedBy,
        public DateTimeImmutable $updatedAt,
        public int $version,
    ) {
    }

    /**
     * Whether the window covers the date. An ended rate keeps covering its closed window, so conversions of those
     * dates stay explainable; a rate ended the day before it starts covers nothing.
     */
    public function covers(RateDate $date): bool
    {
        return !$date->isBefore($this->validFrom) && (!$this->validUntil instanceof RateDate || !$date->isAfter($this->validUntil));
    }

    /**
     * Whether the window `[$from, $until]` (until null = open) shares a date with this rate's window.
     */
    public function overlaps(RateDate $from, ?RateDate $until): bool
    {
        if ($this->validUntil instanceof RateDate && $this->validUntil->isBefore($this->validFrom)) {
            return false;
        }
        if ($until instanceof RateDate && $until->isBefore($from)) {
            return false;
        }

        return (!$until instanceof RateDate || !$this->validFrom->isAfter($until))
            && (!$this->validUntil instanceof RateDate || !$from->isAfter($this->validUntil));
    }
}
