<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Rate;

/**
 * A feed a source can serve (contract section 5.4): the synchronizer picks the smallest one that covers the gap.
 */
enum FetchFeed: string
{
    case Daily       = 'daily';
    case Last90Days  = 'last_90_days';
    case FullHistory = 'history';
    case Range       = 'range';
}
