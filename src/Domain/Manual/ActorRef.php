<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Manual;

use Nowo\CurrencyKitBundle\Domain\Exception\InvalidValueException;
use Stringable;

use function preg_match;
use function strlen;

/**
 * Who wrote a manual rate or triggered a sync: 1–128 bytes of valid UTF-8, no control characters, exact.
 * The only personal datum the bundle stores (contract section 14).
 */
final readonly class ActorRef implements Stringable
{
    /**
     * @throws InvalidValueException
     */
    public function __construct(public string $value)
    {
        if ($value === '' || strlen($value) > 128 || preg_match('//u', $value) !== 1 || preg_match('/\p{Cc}/u', $value) === 1) {
            throw new InvalidValueException('The actor reference is not valid.');
        }
    }

    /**
     * The reference.
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
