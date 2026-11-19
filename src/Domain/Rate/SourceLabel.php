<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Rate;

use Nowo\CurrencyKitBundle\Domain\Exception\InvalidValueException;
use Stringable;

use function mb_strlen;
use function preg_match;

/**
 * The text users see for a source (`ECB`): 1–40 characters, valid UTF-8, no control or format characters. Always
 * escaped on output.
 */
final readonly class SourceLabel implements Stringable
{
    /**
     * @throws InvalidValueException
     */
    public function __construct(public string $value)
    {
        if (preg_match('//u', $value) !== 1 || $value === '' || mb_strlen($value, 'UTF-8') > 40 || preg_match('/\p{C}/u', $value) === 1) {
            throw new InvalidValueException('The source label is not valid.');
        }
    }

    /**
     * The label.
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
