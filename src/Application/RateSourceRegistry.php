<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Application;

use Nowo\CurrencyKitBundle\Domain\Exception\InvalidValueException;
use Nowo\CurrencyKitBundle\Domain\Port\RateSourceInterface;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;

use function array_keys;
use function sort;

/**
 * The configured sources by key (services tagged `nowo_currency_kit.rate_source`).
 */
final readonly class RateSourceRegistry
{
    /** @var array<string, RateSourceInterface> */
    private array $sources;

    /**
     * @param iterable<RateSourceInterface> $sources
     *
     * @throws InvalidValueException when two sources share a key
     */
    public function __construct(iterable $sources)
    {
        $byKey = [];
        foreach ($sources as $source) {
            $key = $source->key()->value;
            if (isset($byKey[$key])) {
                throw new InvalidValueException('Two rate sources share a key.');
            }
            $byKey[$key] = $source;
        }
        $this->sources = $byKey;
    }

    /**
     * The source of a key.
     *
     * @throws InvalidValueException when no source has the key
     */
    public function get(SourceKey $key): RateSourceInterface
    {
        return $this->sources[$key->value] ?? throw new InvalidValueException('No rate source has that key.');
    }

    /**
     * Whether a source has the key.
     */
    public function has(SourceKey $key): bool
    {
        return isset($this->sources[$key->value]);
    }

    /**
     * The keys, sorted.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = array_keys($this->sources);
        sort($keys);

        return $keys;
    }
}
