<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Rate;

use Nowo\CurrencyKitBundle\Domain\Exception\InvalidValueException;
use Nowo\CurrencyKitBundle\Domain\Money\Decimal;

use function preg_match;

/**
 * A rate value (EUR → currency): a decimal string `> 0` with up to 12 integer and 12 fraction digits after
 * canonicalisation (contract section 5.2). Stored as `DECIMAL(24,12)`.
 */
final class RateValue
{
    /** Up to 12 integer digits and any fraction (checked again after canonicalisation). */
    private const GRAMMAR = '/^(0|[1-9]\d{0,11})(\.\d{1,32})?$/D';

    /**
     * The canonical value.
     *
     * @throws InvalidValueException when the value is malformed, zero, negative or has more than 12 fraction digits
     */
    public static function canonical(string $value): string
    {
        if (preg_match(self::GRAMMAR, $value) !== 1) {
            throw new InvalidValueException('The rate is not a valid decimal value.');
        }
        $canonical = Decimal::canonical($value);
        if ($canonical === '0' || preg_match('/\.\d{13,}$/D', $canonical) === 1) {
            throw new InvalidValueException('The rate is not a valid decimal value.');
        }

        return $canonical;
    }

    /**
     * Whether the value passes {@see self::canonical()}.
     */
    public static function isValid(string $value): bool
    {
        try {
            self::canonical($value);

            return true;
        } catch (InvalidValueException) {
            return false;
        }
    }
}
