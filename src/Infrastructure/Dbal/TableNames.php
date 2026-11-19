<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Dbal;

/**
 * The bundle's table names with the configured prefix (`doctrine.table_prefix`, default `nowo_`).
 */
final readonly class TableNames
{
    /** Base names, without the prefix. */
    public const PUBLICATION = 'currency_rate_publication';
    public const RATE        = 'currency_rate';
    public const CHANGE      = 'currency_rate_change';
    public const MANUAL_RATE = 'currency_manual_rate';
    public const SYNC_LOCK   = 'currency_sync_lock';

    /**
     * @param string $prefix `^[a-z][a-z0-9_]{0,15}$` or empty (checked by the configuration)
     */
    public function __construct(public string $prefix = 'nowo_')
    {
    }

    /**
     * The prefixed publication table.
     */
    public function publication(): string
    {
        return $this->prefix . self::PUBLICATION;
    }

    /**
     * The prefixed rate table.
     */
    public function rate(): string
    {
        return $this->prefix . self::RATE;
    }

    /**
     * The prefixed change-log table.
     */
    public function change(): string
    {
        return $this->prefix . self::CHANGE;
    }

    /**
     * The prefixed manual-rate table.
     */
    public function manualRate(): string
    {
        return $this->prefix . self::MANUAL_RATE;
    }

    /**
     * The prefixed sync-lock table.
     */
    public function syncLock(): string
    {
        return $this->prefix . self::SYNC_LOCK;
    }
}
