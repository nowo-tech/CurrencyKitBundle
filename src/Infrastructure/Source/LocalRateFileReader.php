<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Source;

use Nowo\CurrencyKitBundle\Domain\Exception\RateSourceResponseException;
use Nowo\CurrencyKitBundle\Domain\Port\RateFileReaderInterface;
use Nowo\CurrencyKitBundle\Infrastructure\Source\Csv\CsvRateParser;
use Nowo\CurrencyKitBundle\Infrastructure\Source\Ecb\EcbXmlParser;

/**
 * The default file reader: ECB XML or CSV, capped at `outbound.max_bytes.history` (64 MiB by default).
 */
final readonly class LocalRateFileReader implements RateFileReaderInterface
{
    /**
     * Builds the reader.
     */
    public function __construct(private EcbXmlParser $xml, private CsvRateParser $csv, private int $maxBytes)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function read(string $path, string $format, int $maxDays): iterable
    {
        return match ($format) {
            self::FORMAT_ECB_XML => $this->xml->parseFile($path, $this->maxBytes, $maxDays),
            self::FORMAT_CSV     => $this->csv->parseFile($path, $this->maxBytes, $maxDays),
            default              => throw new RateSourceResponseException(RateSourceResponseException::REASON_UNREADABLE),
        };
    }
}
