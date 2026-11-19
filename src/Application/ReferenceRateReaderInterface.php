<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Application;

use Nowo\CurrencyKitBundle\Domain\Exception\InvalidValueException;
use Nowo\CurrencyKitBundle\Domain\Rate\PublicationInfo;
use Nowo\CurrencyKitBundle\Domain\Rate\RateChange;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;

/**
 * Read access to the stored reference rates for hosts (both host kinds read through this, never the tables).
 */
interface ReferenceRateReaderInterface
{
    /**
     * The newest publication of a source (null: `conversion.source`).
     */
    public function latest(?SourceKey $source = null): ?PublicationInfo;

    /**
     * Every rate of one publication day.
     *
     * @return array<string, string> code => EUR → code value
     */
    public function ratesOn(RateDate $date, ?SourceKey $source = null): array;

    /**
     * Rates of one currency over at most 366 days.
     *
     * @throws InvalidValueException when the range is reversed or longer than 366 days
     *
     * @return list<array{date: RateDate, rate: string}>
     */
    public function history(string $currency, RateDate $from, RateDate $to, ?SourceKey $source = null): array;

    /**
     * The change log of a period.
     *
     * @return list<RateChange>
     */
    public function changes(RateDate $from, RateDate $to, ?SourceKey $source = null): array;
}
