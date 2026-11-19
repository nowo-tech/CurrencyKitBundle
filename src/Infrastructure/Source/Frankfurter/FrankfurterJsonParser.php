<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Source\Frankfurter;

use JsonException;
use Nowo\CurrencyKitBundle\Application\BatchValidator;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidValueException;
use Nowo\CurrencyKitBundle\Domain\Exception\RateSourceResponseException;
use Nowo\CurrencyKitBundle\Domain\Money\Decimal;
use Nowo\CurrencyKitBundle\Domain\Rate\DayRates;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;

use function count;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Parser of the Frankfurter-compatible JSON wire format (contract section 7.1): `{amount, base, date, rates}` for one
 * day, `{amount, base, start_date, end_date, rates: {date: rates}}` for a range. `json_decode` with depth 8 on a
 * capped body; the shape is checked key by key; `amount` must be 1 and `base` EUR (or rebased to EUR when allowed).
 * JSON numbers become their shortest round-trip decimal string, then go through the rate grammar.
 */
final readonly class FrankfurterJsonParser
{
    /**
     * Parses a body.
     *
     * @throws RateSourceResponseException
     *
     * @return list<DayRates>
     */
    public function parse(string $json, bool $rebase, int $maxDays): array
    {
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RateSourceResponseException(RateSourceResponseException::REASON_MALFORMED, null, $e);
        }
        if (!is_array($data) || !isset($data['amount'], $data['base'], $data['rates']) || !is_array($data['rates']) || !is_string($data['base'])) {
            throw new RateSourceResponseException(RateSourceResponseException::REASON_MALFORMED);
        }
        if ($data['amount'] !== 1 && $data['amount'] !== 1.0) {
            throw new RateSourceResponseException(RateSourceResponseException::REASON_WRONG_BASE);
        }
        $base = $data['base'];
        if ($base !== 'EUR' && !$rebase) {
            throw new RateSourceResponseException(RateSourceResponseException::REASON_WRONG_BASE);
        }

        $days = [];
        if (isset($data['date'])) {
            $days[] = $this->day($data['date'], $data['rates'], $base);
        } else {
            if (count($data['rates']) > $maxDays) {
                throw new RateSourceResponseException(RateSourceResponseException::REASON_LIMIT);
            }
            foreach ($data['rates'] as $date => $rates) {
                $days[] = $this->day($date, $rates, $base);
            }
        }

        return $days;
    }

    /**
     * One day, rebased to EUR when the base is another currency.
     *
     * @throws RateSourceResponseException
     */
    private function day(mixed $date, mixed $rates, string $base): DayRates
    {
        if (!is_string($date) || !is_array($rates) || count($rates) > BatchValidator::MAX_CURRENCIES) {
            throw new RateSourceResponseException(RateSourceResponseException::REASON_MALFORMED);
        }
        try {
            $day = RateDate::of($date);
        } catch (InvalidValueException $e) {
            throw new RateSourceResponseException(RateSourceResponseException::REASON_INVALID_VALUE, null, $e);
        }
        $values = [];
        foreach ($rates as $code => $value) {
            $values[(string) $code] = $this->number($value);
        }
        if ($base === 'EUR') {
            return new DayRates($day, $values);
        }

        $eur = $values['EUR'] ?? throw new RateSourceResponseException(RateSourceResponseException::REASON_WRONG_BASE);
        if (Decimal::compare($eur, '0') <= 0) {
            throw new RateSourceResponseException(RateSourceResponseException::REASON_INVALID_VALUE);
        }
        $rebased = [$base => Decimal::canonical(Decimal::divide('1', $eur))];
        foreach ($values as $code => $value) {
            if ($code !== 'EUR') {
                $rebased[$code] = Decimal::canonical(Decimal::divide($value, $eur));
            }
        }

        return new DayRates($day, $rebased);
    }

    /**
     * The shortest decimal string that reads back as the same JSON number (no exponent accepted later).
     *
     * @throws RateSourceResponseException
     */
    private function number(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (!is_float($value)) {
            throw new RateSourceResponseException(RateSourceResponseException::REASON_INVALID_VALUE);
        }
        $text = sprintf('%.17G', $value);
        for ($precision = 1; $precision < 17; ++$precision) {
            $candidate = sprintf('%.' . $precision . 'G', $value);
            if ((float) $candidate === $value) {
                $text = $candidate;

                break;
            }
        }

        return Decimal::isPlain($text) ? $text : throw new RateSourceResponseException(RateSourceResponseException::REASON_INVALID_VALUE);
    }
}
