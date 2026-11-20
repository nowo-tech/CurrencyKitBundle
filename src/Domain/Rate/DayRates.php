<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Rate;

/**
 * One publication day of a source: EUR-based values keyed by the currency code as the feed gave it (validated by the
 * synchronizer before any write, contract section 8.5).
 */
final readonly class DayRates
{
    /**
     * @param array<string, string> $rates currency code => decimal string, as parsed (never through float)
     */
    public function __construct(public RateDate $date, public array $rates)
    {
    }
}
