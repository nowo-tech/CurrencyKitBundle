<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Conversion;

/**
 * What a conversion for "now" does when the latest publication is older than `max_staleness_days` (section 9.4).
 */
enum OnStale: string
{
    /** Throw StaleRateException (default, QC-9). */
    case Fail = 'fail';

    /** Return the result with `stale = true`, log a warning and dispatch ReferenceRatesStale (at most hourly). */
    case Flag = 'flag';
}
