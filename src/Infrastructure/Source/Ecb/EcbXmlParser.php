<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Source\Ecb;

use Generator;
use Nowo\CurrencyKitBundle\Application\BatchValidator;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidValueException;
use Nowo\CurrencyKitBundle\Domain\Exception\RateSourceResponseException;
use Nowo\CurrencyKitBundle\Domain\Rate\DayRates;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use XMLReader;

use function count;
use function filesize;
use function is_file;
use function is_readable;
use function libxml_clear_errors;
use function libxml_get_errors;
use function libxml_use_internal_errors;
use function strlen;

use const LIBXML_COMPACT;
use const LIBXML_NONET;

/**
 * Streaming parser of the ECB eurofxref XML (daily, 90-day and full-history files; contract section 13.3).
 *
 * `XMLReader` with `LIBXML_NONET | LIBXML_COMPACT` only: no entity substitution, no DTD loading, and any DOCTYPE node
 * is refused, so XXE and entity expansion cannot happen. Nesting depth ≤ 8, ≤ 400 currencies a day, a day cap,
 * attribute values ≤ 32 bytes, and only `Cube` elements of the eurofxref namespace are read. Values stay the attribute
 * text (never a float). Errors carry a reason only, never the body. Days are yielded as they are read.
 */
final readonly class EcbXmlParser
{
    /** The eurofxref vocabulary. */
    public const NAMESPACE = 'http://www.ecb.int/vocabulary/2002-08-01/eurofxref';

    /** Maximum element depth. */
    private const MAX_DEPTH = 8;

    /** Maximum attribute value length in bytes. */
    private const MAX_ATTRIBUTE_BYTES = 32;

    /**
     * Parses an already size-capped body.
     *
     * @throws RateSourceResponseException
     *
     * @return Generator<int, DayRates>
     */
    public function parseString(string $xml, int $maxDays): Generator
    {
        $reader = new XMLReader();
        if ($xml === '' || !$reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RateSourceResponseException(RateSourceResponseException::REASON_MALFORMED);
        }

        return $this->read($reader, $maxDays);
    }

    /**
     * Parses a local file (air-gapped import) of at most `$maxBytes`.
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
        $reader = new XMLReader();
        if ($size === 0 || !$reader->open($path, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RateSourceResponseException(RateSourceResponseException::REASON_MALFORMED);
        }

        return $this->read($reader, $maxDays);
    }

    /**
     * Reads the days.
     *
     * @return Generator<int, DayRates>
     */
    private function read(XMLReader $reader, int $maxDays): Generator
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $date     = null;
            $rates    = [];
            $days     = 0;
            $dayDepth = null;
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::DOC_TYPE) {
                    throw new RateSourceResponseException(RateSourceResponseException::REASON_DOCTYPE);
                }
                if ($reader->depth > self::MAX_DEPTH) {
                    throw new RateSourceResponseException(RateSourceResponseException::REASON_LIMIT);
                }
                if ($reader->nodeType === XMLReader::END_ELEMENT && $date instanceof RateDate && $reader->depth === $dayDepth) {
                    if (++$days > $maxDays) {
                        throw new RateSourceResponseException(RateSourceResponseException::REASON_LIMIT);
                    }

                    yield new DayRates($date, $rates);
                    $date  = null;
                    $rates = [];

                    continue;
                }
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'Cube' || $reader->namespaceURI !== self::NAMESPACE) {
                    continue;
                }
                $time = $this->attribute($reader, 'time');
                if ($time !== null) {
                    if ($date instanceof RateDate) {
                        throw new RateSourceResponseException(RateSourceResponseException::REASON_MALFORMED);
                    }
                    $date     = $this->date($time);
                    $dayDepth = $reader->depth;
                    if ($reader->isEmptyElement) {
                        throw new RateSourceResponseException(RateSourceResponseException::REASON_MALFORMED);
                    }

                    continue;
                }
                $currency = $this->attribute($reader, 'currency');
                $rate     = $this->attribute($reader, 'rate');
                if ($currency === null && $rate === null) {
                    continue;
                }
                if (!$date instanceof RateDate || $currency === null || $rate === null || isset($rates[$currency])) {
                    throw new RateSourceResponseException(RateSourceResponseException::REASON_MALFORMED);
                }
                if (count($rates) >= BatchValidator::MAX_CURRENCIES) {
                    throw new RateSourceResponseException(RateSourceResponseException::REASON_LIMIT);
                }
                $rates[$currency] = $rate;
            }
            if (libxml_get_errors() !== [] || $date instanceof RateDate || $days === 0) {
                throw new RateSourceResponseException(RateSourceResponseException::REASON_MALFORMED);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $reader->close();
        }
    }

    /**
     * An attribute value of at most 32 bytes, or null when absent.
     *
     * @throws RateSourceResponseException
     */
    private function attribute(XMLReader $reader, string $name): ?string
    {
        $value = $reader->getAttribute($name);
        if ($value !== null && strlen($value) > self::MAX_ATTRIBUTE_BYTES) {
            throw new RateSourceResponseException(RateSourceResponseException::REASON_LIMIT);
        }

        return $value;
    }

    /**
     * The `time` attribute as a date.
     *
     * @throws RateSourceResponseException
     */
    private function date(string $time): RateDate
    {
        try {
            return RateDate::of($time);
        } catch (InvalidValueException $e) {
            throw new RateSourceResponseException(RateSourceResponseException::REASON_INVALID_VALUE, null, $e);
        }
    }
}
