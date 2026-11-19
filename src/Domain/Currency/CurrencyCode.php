<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Currency;

use Nowo\CurrencyKitBundle\Domain\Exception\InvalidCurrencyCodeException;
use Stringable;

use function in_array;
use function preg_match;

/**
 * An ISO 4217 currency code with its minor units (contract section 5.1).
 *
 * The code is exact: `^[A-Z]{3}$`, no trim and no case folding (`usd` is refused, so a typo never silently converts).
 * The refused X-codes (precious metals, SDR, units of account, test codes; QC-5) are refused whatever the table says.
 * {@see self::of()} reads the bundled table; the constructor is for a host's catalogue that adds a code the bundle
 * does not know yet.
 */
final readonly class CurrencyCode implements Stringable
{
    /** Codes refused in 0.x (QC-5). */
    public const REFUSED_X_CODES = ['XAU', 'XAG', 'XPT', 'XPD', 'XDR', 'XSU', 'XUA', 'XBA', 'XBB', 'XBC', 'XBD', 'XTS', 'XXX'];

    /** The pivot of every stored rate in 0.x. */
    public const EUR = 'EUR';

    /**
     * @param string $code three upper-case letters
     * @param int $minorUnits the ISO exponent (JPY 0, EUR 2, KWD 3), 0..12
     * @param bool $active false for a withdrawn (historic) code
     *
     * @throws InvalidCurrencyCodeException
     */
    public function __construct(public string $code, private int $minorUnits, private bool $active = true)
    {
        if (preg_match('/^[A-Z]{3}$/D', $code) !== 1 || in_array($code, self::REFUSED_X_CODES, true) || $minorUnits < 0 || $minorUnits > 12) {
            throw new InvalidCurrencyCodeException();
        }
    }

    /**
     * The code from the bundled ISO 4217 table (current and historic codes).
     *
     * @throws InvalidCurrencyCodeException when the code is malformed, unknown or refused
     */
    public static function of(string $code): self
    {
        $row = Iso4217Table::CURRENCIES[$code] ?? null;
        if ($row === null) {
            throw new InvalidCurrencyCodeException();
        }

        return new self($code, $row[1], $row[2]);
    }

    /**
     * The euro.
     */
    public static function eur(): self
    {
        return self::of(self::EUR);
    }

    /**
     * The ISO exponent: how many fraction digits a rounded amount has.
     */
    public function minorUnits(): int
    {
        return $this->minorUnits;
    }

    /**
     * False for a withdrawn code (it still converts on past dates the stored source covers).
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Whether this is the euro (the pivot).
     */
    public function isEur(): bool
    {
        return $this->code === self::EUR;
    }

    /**
     * Same code (exact comparison).
     */
    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }

    /**
     * The three-letter code.
     */
    public function __toString(): string
    {
        return $this->code;
    }
}
