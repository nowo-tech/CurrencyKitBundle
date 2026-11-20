<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Manual;

/**
 * State of a manual rate: never deleted, only ended.
 */
enum ManualRateStatus: string
{
    case Active = 'active';
    case Ended  = 'ended';
}
