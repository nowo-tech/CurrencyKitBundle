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
     * The current version token. A lookup reads it once and passes it to both get() and set(), so a value loaded
     * before a concurrent invalidation is never stored under the new version (audit VR-8).
     */
    public function version(): string;

    /**
     * A cached lookup of that version, or null on a miss.
     *
     * @return array<string, string|null>|null
     */
    public function get(string $key, string $version): ?array;

    /**
     * Stores a lookup under that version.
     *
     * @param array<string, string|null> $value
     */
    public function set(string $key, string $version, array $value): void;

    /**
     * Invalidates every cached lookup (bumps the version).
     */
    public function invalidate(): void;

    /**
     * Claims a once-per-period flag (the stale event at most hourly); false when already claimed.
     */
    public function claimOnce(string $key, int $ttlSeconds): bool;
}
