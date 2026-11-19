<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Cache;

use Nowo\CurrencyKitBundle\Domain\Port\ResolvedRateCacheInterface;
use Psr\Cache\CacheItemPoolInterface;

use function bin2hex;
use function hash;
use function is_array;
use function is_string;
use function random_bytes;

/**
 * The shared cache of resolved lookups on a PSR-6 pool (`cache.pool`, contract section 16). Every key is namespaced
 * by a version token stored in the pool (`currency_kit.rates_version`), replaced after every commit that writes rates
 * or manual rates: one extra read per lookup, and a manual rate reaches every worker at once. Entries expire after
 * `cache.ttl` (≤ 300 s) as a bound. Keys and values are public data only.
 */
final readonly class Psr6ResolvedRateCache implements ResolvedRateCacheInterface
{
    /** Pool key of the version token. */
    private const VERSION_KEY = 'nowo_currency_kit.rates_version';

    /**
     * Builds the cache.
     */
    public function __construct(private CacheItemPoolInterface $pool, private int $ttl = 300)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $key): ?array
    {
        $item = $this->pool->getItem($this->key($key));
        if (!$item->isHit()) {
            return null;
        }
        $value = $item->get();
        if (!is_array($value)) {
            return null;
        }
        $clean = [];
        foreach ($value as $name => $entry) {
            if (is_string($name) && (is_string($entry) || $entry === null)) {
                $clean[$name] = $entry;
            }
        }

        return $clean;
    }

    /**
     * {@inheritDoc}
     */
    public function set(string $key, array $value): void
    {
        $item = $this->pool->getItem($this->key($key));
        $item->set($value);
        $item->expiresAfter($this->ttl);
        $this->pool->save($item);
    }

    /**
     * {@inheritDoc}
     */
    public function invalidate(): void
    {
        $item = $this->pool->getItem(self::VERSION_KEY);
        $item->set(bin2hex(random_bytes(8)));
        $this->pool->save($item);
    }

    /**
     * {@inheritDoc}
     */
    public function claimOnce(string $key, int $ttlSeconds): bool
    {
        $item = $this->pool->getItem('nowo_currency_kit.once.' . hash('xxh128', $key));
        if ($item->isHit()) {
            return false;
        }
        $item->set(true);
        $item->expiresAfter($ttlSeconds);
        $this->pool->save($item);

        return true;
    }

    /**
     * The versioned pool key of a lookup.
     */
    private function key(string $key): string
    {
        $version = $this->pool->getItem(self::VERSION_KEY);
        $token   = $version->isHit() && is_string($version->get()) ? $version->get() : '0';

        return 'nowo_currency_kit.' . hash('xxh128', $token . '|' . $key);
    }
}
