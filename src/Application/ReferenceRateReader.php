<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Application;

use Nowo\CurrencyKitBundle\Domain\Exception\InvalidValueException;
use Nowo\CurrencyKitBundle\Domain\Port\RateStoreInterface;
use Nowo\CurrencyKitBundle\Domain\Rate\PublicationInfo;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;

/**
 * The default reader over the rate store.
 */
final readonly class ReferenceRateReader implements ReferenceRateReaderInterface
{
    /**
     * Builds the reader.
     */
    public function __construct(private RateStoreInterface $store, private CurrencyKitSettings $settings)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function latest(?SourceKey $source = null): ?PublicationInfo
    {
        return $this->store->latestPublication($source ?? $this->settings->activeSource);
    }

    /**
     * {@inheritDoc}
     */
    public function ratesOn(RateDate $date, ?SourceKey $source = null): array
    {
        return $this->store->ratesOn($source ?? $this->settings->activeSource, $date);
    }

    /**
     * {@inheritDoc}
     */
    public function history(string $currency, RateDate $from, RateDate $to, ?SourceKey $source = null): array
    {
        if ($to->isBefore($from) || $from->daysUntil($to) >= 366) {
            throw new InvalidValueException('The history range must be 1 to 366 days.');
        }

        return $this->store->history($source ?? $this->settings->activeSource, $currency, $from, $to);
    }

    /**
     * {@inheritDoc}
     */
    public function changes(RateDate $from, RateDate $to, ?SourceKey $source = null): array
    {
        return $this->store->changes($source, $from, $to);
    }
}
