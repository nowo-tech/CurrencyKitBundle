<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Money;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode as BrickRoundingMode;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidAmountException;

use function max;
use function preg_match;
use function rtrim;
use function str_contains;
use function str_replace;
use function strlen;
use function trim;

/**
 * Decimal-string arithmetic on `brick/math` (contract section 5.2). Every argument and result is a plain decimal
 * string: no `brick/math` type leaves this class, so public signatures stay free of it. Inputs are validated by the
 * caller's grammar before they reach brick, which never sees an exponent or a float.
 *
 * @internal
 */
final class Decimal
{
    /** Scale of exact amounts and rates. */
    public const SCALE = 12;

    /**
     * Canonical form: no trailing fraction zeros, no bare point, `-0` becomes `0`.
     */
    public static function canonical(string $value): string
    {
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return $value === '-0' ? '0' : $value;
    }

    /**
     * `$a × $b` rounded to `$scale` fraction digits (fixed scale, zeros kept).
     */
    public static function multiply(string $a, string $b, int $scale = self::SCALE, RoundingMode $mode = RoundingMode::HalfEven): string
    {
        return BigDecimal::of($a)->multipliedBy($b)->toScale(max(0, $scale), self::brick($mode))->toString();
    }

    /**
     * `$a ÷ $b` rounded to `$scale` fraction digits (fixed scale). `$b` must not be zero (rates are > 0).
     *
     * @throws InvalidAmountException when `$b` is zero
     */
    public static function divide(string $a, string $b, int $scale = self::SCALE, RoundingMode $mode = RoundingMode::HalfEven): string
    {
        try {
            return BigDecimal::of($a)->dividedBy($b, max(0, $scale), self::brick($mode))->toString();
        } catch (MathException $e) {
            throw new InvalidAmountException('Division by zero.', $e);
        }
    }

    /**
     * `$a × $b ÷ $c` in one step, rounded once to `$scale` fraction digits (fixed scale). `$c` must not be zero.
     *
     * @throws InvalidAmountException when `$c` is zero
     */
    public static function multiplyDivide(string $a, string $b, string $c, int $scale = self::SCALE, RoundingMode $mode = RoundingMode::HalfEven): string
    {
        return self::divide(BigDecimal::of($a)->multipliedBy($b)->toString(), $c, $scale, $mode);
    }

    /**
     * A plain decimal string from any numeric text brick/math reads, scientific notation included (`1.5E+2` is
     * `150`). The input must be numeric (a JSON number's text).
     */
    public static function plain(string $numeric): string
    {
        return self::canonical(BigDecimal::of($numeric)->toString());
    }

    /**
     * Significant digits of a plain decimal string (leading zeros, sign and point excluded).
     */
    public static function significantDigits(string $value): int
    {
        return max(1, strlen(trim(str_replace(['-', '.'], '', $value), '0')));
    }

    /**
     * `$value` rounded to `$scale` fraction digits (fixed scale).
     */
    public static function round(string $value, int $scale, RoundingMode $mode): string
    {
        return BigDecimal::of($value)->toScale(max(0, $scale), self::brick($mode))->toString();
    }

    /**
     * Numeric comparison: -1, 0 or 1 (`1.0812` equals `1.08120`).
     */
    public static function compare(string $a, string $b): int
    {
        return BigDecimal::of($a)->compareTo($b);
    }

    /**
     * Whether `|$new − $old|` exceeds `$percent` % of `$old` (`$old` > 0).
     */
    public static function movedMoreThan(string $old, string $new, int $percent): bool
    {
        $delta = BigDecimal::of($new)->minus($old)->abs()->multipliedBy(100);

        return $delta->compareTo(BigDecimal::of($old)->multipliedBy($percent)) > 0;
    }

    /**
     * `$value × 10^$places` as an integer string (exact, for minor units).
     */
    public static function shiftRight(string $value, int $places, RoundingMode $mode): string
    {
        return BigDecimal::of($value)->withPointMovedRight($places)->toScale(0, self::brick($mode))->toString();
    }

    /**
     * `$value ÷ 10^$places` (exact).
     */
    public static function shiftLeft(string $value, int $places): string
    {
        return self::canonical(BigDecimal::of($value)->withPointMovedLeft($places)->toString());
    }

    /**
     * Whether the string is a plain unsigned or signed decimal (digits, optional point and fraction).
     */
    public static function isPlain(string $value): bool
    {
        return preg_match('/^-?\d+(\.\d+)?$/D', $value) === 1;
    }

    /**
     * The `brick/math` rounding mode.
     */
    private static function brick(RoundingMode $mode): BrickRoundingMode
    {
        return match ($mode) {
            RoundingMode::HalfUp   => BrickRoundingMode::HalfUp,
            RoundingMode::HalfEven => BrickRoundingMode::HalfEven,
            RoundingMode::HalfDown => BrickRoundingMode::HalfDown,
            RoundingMode::Down     => BrickRoundingMode::Down,
            RoundingMode::Up       => BrickRoundingMode::Up,
            RoundingMode::Ceiling  => BrickRoundingMode::Ceiling,
            RoundingMode::Floor    => BrickRoundingMode::Floor,
        };
    }
}
