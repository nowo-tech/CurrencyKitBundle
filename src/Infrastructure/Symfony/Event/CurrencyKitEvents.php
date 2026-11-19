<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Symfony\Event;

/**
 * Access-check event names of the house access pattern (REQ-UI-002, contract section 13.1). Every decision of the
 * currency kit guard dispatches {@see self::CURRENCY_ACCESS_CHECK}; manual-rate actions also dispatch
 * {@see self::MANUAL_RATE_ACCESS_CHECK} and sync actions {@see self::SYNC_ACCESS_CHECK}. A listener may `grant()` or
 * `deny()`; without one the default decision stands (deny by default on the web).
 */
final class CurrencyKitEvents
{
    /** Area decision ({@see CurrencyAccessCheckEvent}): view rates, manage manual rates, trigger a sync. */
    public const CURRENCY_ACCESS_CHECK = 'nowo_currency_kit.currency_access_check';

    /** Per manual rate ({@see ManualRateAccessCheckEvent}): view, create, edit, end. */
    public const MANUAL_RATE_ACCESS_CHECK = 'nowo_currency_kit.manual_rate_access_check';

    /** Per source ({@see SyncAccessCheckEvent}): sync, backfill, import, apply a change, prune. */
    public const SYNC_ACCESS_CHECK = 'nowo_currency_kit.sync_access_check';
}
