<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Rate;

use Nowo\CurrencyKitBundle\Domain\Exception\InvalidValueException;
use Stringable;

use function preg_match;

/**
 * A rate source key, `^[a-z][a-z0-9_]{0,31}$`, exact (`ecb`, `mirror`, `manual`, host keys).
 */
final readonly class SourceKey implements Stringable
{
    /** The manual-rate source key (also the source of a result where an override applied). */
    public const MANUAL = 'manual';

    /** The source of a same-currency result (no storage read). */
    public const IDENTITY = 'identity';

    /**
     * @throws InvalidValueException
     */
    public function __construct(public string $value)
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $value) !== 1) {
            throw new InvalidValueException('The source key is not valid.');
        }
    }

    /**
     * `SourceKey::of('ecb')`.
     *
     * @throws InvalidValueException
     */
    public static function of(string $value): self
    {
        return new self($value);
    }

    /**
     * Same key (exact).
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * The key.
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
