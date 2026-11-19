<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Money;

use Nowo\CurrencyKitBundle\Domain\Currency\CurrencyCode;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidAmountException;

use function preg_match;
use function strlen;
use function substr;

use const PHP_INT_MAX;

/**
 * An amount as a canonical decimal string with its currency (contract section 5.2).
 *
 * Grammar `^-?(0|[1-9]\d{0,17})(\.\d{1,12})?$`: up to 18 integer and 12 fraction digits, no exponent, no `+`, no
 * separators and no locale input. Trailing fraction zeros are stripped (`1.50` is `1.5`) and `-0` is `0`; equality is
 * on the canonical string. There is no float input anywhere: a float argument is a `TypeError`.
 */
final readonly class Money
{
    /** The amount grammar. */
    public const GRAMMAR = '/^-?(0|[1-9]\d{0,17})(\.\d{1,12})?$/D';

    /** The canonical decimal string. */
    public string $amount;

    /**
     * @throws InvalidAmountException
     */
    public function __construct(string $amount, public CurrencyCode $currency)
    {
        if (preg_match(self::GRAMMAR, $amount) !== 1) {
            throw new InvalidAmountException();
        }
        $this->amount = Decimal::canonical($amount);
    }

    /**
     * `Money::of('12.30', CurrencyCode::of('EUR'))`.
     *
     * @throws InvalidAmountException
     */
    public static function of(string $amount, CurrencyCode $currency): self
    {
        return new self($amount, $currency);
    }

    /**
     * From minor units, using the currency's ISO exponent (`1234` EUR cents is `12.34`).
     *
     * @throws InvalidAmountException
     */
    public static function ofMinor(int $minor, CurrencyCode $currency): self
    {
        return new self(Decimal::shiftLeft((string) $minor, $currency->minorUnits()), $currency);
    }

    /**
     * To minor units with the given rounding (sub-minor fractions are rounded).
     *
     * @throws InvalidAmountException when the result does not fit in an int
     */
    public function toMinor(RoundingMode $mode = RoundingMode::HalfUp): int
    {
        $minor  = Decimal::shiftRight($this->amount, $this->currency->minorUnits(), $mode);
        $digits = $minor[0] === '-' ? substr($minor, 1) : $minor;
        if (strlen($digits) > 19 || (strlen($digits) === 19 && Decimal::compare($digits, (string) PHP_INT_MAX) > 0)) {
            throw new InvalidAmountException('The amount does not fit in minor units.');
        }

        return (int) $minor;
    }

    /**
     * Same canonical amount and currency.
     */
    public function equals(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency->equals($other->currency);
    }

    /**
     * Whether the amount is negative.
     */
    public function isNegative(): bool
    {
        return $this->amount[0] === '-';
    }
}
