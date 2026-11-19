<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Port;

/**
 * Area access of the currency kit (contract section 13.1, house access pattern REQ-UI-002). The default checker maps
 * `security.dashboard_roles` / `manage_roles` (holders of `access_roles` only); a host plugs its own (permission sets)
 * with `security.access_checker`. Per-record decisions then go through the `*_ACCESS_CHECK` events.
 *
 * Deny by default: a checker that does not recognise the user answers false.
 */
interface CurrencyKitAccessCheckerInterface
{
    /**
     * View rates, the change log and manual rates.
     */
    public function canViewRates(object $user): bool;

    /**
     * Create, edit and end manual rates; apply kept changes; prune; forget an actor.
     */
    public function canManageManualRates(object $user): bool;

    /**
     * Trigger a sync, a backfill or an import.
     */
    public function canTriggerSync(object $user): bool;
}
