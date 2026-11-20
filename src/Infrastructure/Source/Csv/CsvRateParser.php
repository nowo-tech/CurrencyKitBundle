<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Source\Csv;

use Generator;
use Nowo\CurrencyKitBundle\Application\BatchValidator;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidValueException;
use Nowo\CurrencyKitBundle\Domain\Exception\RateSourceResponseException;
use Nowo\CurrencyKitBundle\Domain\Rate\DayRates;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use SplFileObject;

use function count;
use function filesize;
use function is_file;
use function is_readable;
use function is_string;
use function strlen;

/**
 * RFC 4180 CSV import (`date,currency,rate` with that header, contract section 8.4). Rows of one date must be
 * consecutive (a date seen again later is a duplicate). It only reads: no formula is ever evaluated. Values stay text.
 */
final readonly class CsvRateParser
{
    /**
     * Parses a local file of at most `$maxBytes`.
     *
     * @throws RateSourceResponseException
     *
     * @return Generator<int, DayRates>
     */
    public function parseFile(string $path, int $maxBytes, int $maxDays): Generator
    {
        $size = is_file($path) && is_readable($path) ? filesize($path) : false;
        if ($size === false) {
            throw new RateSourceResponseException(RateSourceResponseException::REASON_UNREADABLE);
        }
        if ($size > $maxBytes) {
            throw new RateSourceResponseException(RateSourceResponseException::REASON_TOO_LARGE, $size);
        }

        return $this->read(new SplFileObject($path, 'r'), $maxDays);
    }

    /**
     * Reads the days.
     *
     * @return Generator<int, DayRates>
     */
    private function read(SplFileObject $file, int $maxDays): Generator
    {
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);
        $file->setCsvControl(',', '"', '');
        $header = true;
        $date   = null;
        $rates  = [];
        $seen   = [];
        $days   = 0;
        /** @var list<string|null> $row READ_CSV with SKIP_EMPTY: every row is a list of cells */
        foreach ($file as $row) {
            if ($header) {
                if ($row !== ['date', 'currency', 'rate']) {
                    throw new RateSourceResponseException(RateSourceResponseException::REASON_MALFORMED);
                }
                $header = false;

                continue;
            }
            [$day, $currency, $rate] = [$this->cell($row[0] ?? null), $this->cell($row[1] ?? null), $this->cell($row[2] ?? null)];
            if (count($row) !== 3 || strlen($currency) > 32 || strlen($rate) > 32) {
                throw new RateSourceResponseException(RateSourceResponseException::REASON_MALFORMED);
            }
            if (!$date instanceof RateDate || $date->value !== $day) {
                if ($date instanceof RateDate) {
                    yield new DayRates($date, $rates);
                }
                $date = $this->date($day);
                if (isset($seen[$day]) || ++$days > $maxDays) {
                    throw new RateSourceResponseException(isset($seen[$day]) ? RateSourceResponseException::REASON_DUPLICATE_DATE : RateSourceResponseException::REASON_LIMIT);
                }
                $seen[$day] = true;
                $rates      = [];
            }
            if (isset($rates[$currency]) || count($rates) >= BatchValidator::MAX_CURRENCIES) {
                throw new RateSourceResponseException(isset($rates[$currency]) ? RateSourceResponseException::REASON_MALFORMED : RateSourceResponseException::REASON_LIMIT);
            }
            $rates[$currency] = $rate;
        }
        if (!$date instanceof RateDate) {
            throw new RateSourceResponseException(RateSourceResponseException::REASON_MALFORMED);
        }

        yield new DayRates($date, $rates);
    }

    /**
     * A cell as text.
     */
    private function cell(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * A date cell.
     *
     * @throws RateSourceResponseException
     */
    private function date(string $value): RateDate
    {
        try {
            return RateDate::of($value);
        } catch (InvalidValueException $e) {
            throw new RateSourceResponseException(RateSourceResponseException::REASON_INVALID_VALUE, null, $e);
        }
    }
}
