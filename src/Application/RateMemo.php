<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Application;

use function array_key_exists;
use function array_slice;
use function count;
use function intdiv;

/**
 * Per-request memo of resolved lookups (contract section 16): public data only (source, date, currency), never per
 * person. Cleared at every request, terminate, sub-request end and consumed message, after every commit that writes
 * rates or manual rates, and on the services resetter; never relied on alone. Bounded (oldest half dropped past the
 * cap) so a long console run stays flat.
 */
final class RateMemo
{
    /** Entries kept before the memo drops its oldest half. */
    private const CAP = 2048;

    /** @var array<string, array<string, string|null>|null> */
    private array $entries = [];

    /** @var array<string, true> */
    private array $flags = [];

    /**
     * Whether the key is memoised (a null value is a memoised miss).
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->entries);
    }

    /**
     * A memoised value (null for a miss or an unknown key).
     *
     * @return array<string, string|null>|null
     */
    public function get(string $key): ?array
    {
        return $this->entries[$key] ?? null;
    }

    /**
     * Memoises a value (null: a miss).
     *
     * @param array<string, string|null>|null $value
     */
    public function set(string $key, ?array $value): void
    {
        if (count($this->entries) >= self::CAP) {
            // @igor-ignore - request memo of public lookups, cleared at every boundary and by kernel.reset
            $this->entries = array_slice($this->entries, intdiv(self::CAP, 2), null, true);
        }
        // @igor-ignore - request memo of public lookups, cleared at every boundary and by kernel.reset
        $this->entries[$key] = $value;
    }

    /**
     * Claims a once-per-request flag (the stale warning); false when already claimed.
     */
    public function once(string $key): bool
    {
        if (isset($this->flags[$key])) {
            return false;
        }
        // @igor-ignore - request memo of public lookups, cleared at every boundary and by kernel.reset
        $this->flags[$key] = true;

        return true;
    }

    /**
     * Forgets everything.
     */
    public function reset(): void
    {
        $this->entries = [];
        $this->flags   = [];
    }
}
