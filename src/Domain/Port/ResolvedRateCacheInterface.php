<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Port;

/**
 * The optional shared cache of resolved lookups (contract section 16), behind a version key bumped after every commit
 * that writes rates or manual rates: a manual rate reaches every worker at once. Keys hold public data only.
 */
interface ResolvedRateCacheInterface
{
    /**
     * A cached lookup, or null on a miss.
     *
     * @return array<string, string|null>|null
     */
    public function get(string $key): ?array;

    /**
     * Stores a lookup.
     *
     * @param array<string, string|null> $value
     */
    public function set(string $key, array $value): void;

    /**
     * Invalidates every cached lookup (bumps the version).
     */
    public function invalidate(): void;

    /**
     * Claims a once-per-period flag (the stale event at most hourly); false when already claimed.
     */
    public function claimOnce(string $key, int $ttlSeconds): bool;
}
