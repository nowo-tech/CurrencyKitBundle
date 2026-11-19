<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Rate;

/**
 * The parsed result of one fetch: publication days in any order (the synchronizer sorts them).
 */
final readonly class RateBatch
{
    /**
     * @param iterable<DayRates> $days a list, or a generator that yields days as they are parsed (streamed backfill)
     */
    public function __construct(public RateOrigin $origin, public iterable $days)
    {
    }
}
