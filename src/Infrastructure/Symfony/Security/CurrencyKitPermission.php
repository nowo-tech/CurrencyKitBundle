<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Symfony\Security;

use Nowo\CurrencyKitBundle\Domain\Port\CurrencyKitAccessCheckerInterface;

/**
 * What the currency kit guard decides (contract section 13.1): the three areas of
 * {@see CurrencyKitAccessCheckerInterface}.
 */
enum CurrencyKitPermission: string
{
    /** Rates, change log and manual-rate list. */
    case ViewRates = 'view_rates';

    /** Create, edit and end manual rates; apply kept changes; prune; forget an actor. */
    case ManageManualRates = 'manage_manual_rates';

    /** Sync, backfill and import. */
    case TriggerSync = 'trigger_sync';
}
