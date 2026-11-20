<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Application;

use Nowo\CurrencyKitBundle\Domain\Port\ResolvedRateCacheInterface;

/**
 * No shared cache (`cache.pool: null`): every lookup reads the database once per request (the memo covers repeats).
 * The once-per-period flag is then always granted, so the stale event is dispatched at most once per request.
 */
final readonly class NullResolvedRateCache implements ResolvedRateCacheInterface
{
    /**
     * {@inheritDoc}
     */
    public function version(): string
    {
        return '0';
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $key, string $version): ?array
    {
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function set(string $key, string $version, array $value): void
    {
    }

    /**
     * {@inheritDoc}
     */
    public function invalidate(): void
    {
    }

    /**
     * {@inheritDoc}
     */
    public function claimOnce(string $key, int $ttlSeconds): bool
    {
        return true;
    }
}
