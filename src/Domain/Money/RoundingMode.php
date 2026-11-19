<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Money;

/**
 * Rounding of a converted amount (contract section 5.3). The default for the rounded amount is {@see self::HalfUp}
 * (QC-1); exact amounts and rates always use {@see self::HalfEven} at scale 12.
 */
enum RoundingMode: string
{
    case HalfUp   = 'half_up';
    case HalfEven = 'half_even';
    case HalfDown = 'half_down';
    case Down     = 'down';
    case Up       = 'up';
    case Ceiling  = 'ceiling';
    case Floor    = 'floor';
}
