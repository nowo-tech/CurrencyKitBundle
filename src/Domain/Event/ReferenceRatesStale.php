<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Event;

use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;

/**
 * A conversion for "now" used a publication older than `max_staleness_days` with `on_stale: flag` (at most once per hour per source). Dispatched after the commit; carries value objects and ids only.
 */
final readonly class ReferenceRatesStale
{
    /**
     * Builds the event.
     */
    public function __construct(
        public SourceKey $source,
        public RateDate $latestDate,
        public int $ageDays,
    ) {
    }
}
