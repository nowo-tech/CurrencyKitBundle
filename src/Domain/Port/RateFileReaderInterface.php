<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Port;

use Nowo\CurrencyKitBundle\Domain\Exception\RateSourceResponseException;
use Nowo\CurrencyKitBundle\Domain\Rate\DayRates;

/**
 * Reads a local rate file for an air-gapped import (contract section 8.4): ECB XML or `date,currency,rate` CSV, with
 * the same parser and limits as the feeds. Days are yielded as they are read (memory stays flat; the caller writes a
 * day before the next one is read).
 */
interface RateFileReaderInterface
{
    /** ECB eurofxref XML (daily, 90-day or full-history file). */
    public const FORMAT_ECB_XML = 'ecb-xml';

    /** RFC 4180 CSV with the header `date,currency,rate`. */
    public const FORMAT_CSV = 'csv';

    /**
     * Reads the file.
     *
     * @param string $format one of the `FORMAT_*` constants
     *
     * @throws RateSourceResponseException unreadable file, size cap, malformed content, too many days
     *
     * @return iterable<DayRates>
     */
    public function read(string $path, string $format, int $maxDays): iterable;
}
