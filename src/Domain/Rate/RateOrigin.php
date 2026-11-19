<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Rate;

/**
 * Which feed stored a publication (contract section 5.4).
 */
enum RateOrigin: string
{
    case Daily      = 'daily';
    case History90  = 'history_90';
    case History    = 'history';
    case Api        = 'api';
    case ManualRate = 'manual';
    case Import     = 'import';
}
