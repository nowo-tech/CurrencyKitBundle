<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Source\Manual;

use DateTimeZone;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidValueException;
use Nowo\CurrencyKitBundle\Domain\Port\RateSourceInterface;
use Nowo\CurrencyKitBundle\Domain\Rate\FetchFeed;
use Nowo\CurrencyKitBundle\Domain\Rate\FetchRequest;
use Nowo\CurrencyKitBundle\Domain\Rate\RateBatch;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceLabel;

/**
 * The manual-rate source (key `manual`): never fetches. With `conversion.source: manual` an air-gapped install
 * converts from manual rates only (contract section 10).
 */
final readonly class ManualRateSource implements RateSourceInterface
{
    /**
     * Builds the source.
     */
    public function __construct(private SourceLabel $label, private DateTimeZone $timezone = new DateTimeZone('Europe/Berlin'))
    {
    }

    /**
     * {@inheritDoc}
     */
    public function key(): SourceKey
    {
        return new SourceKey(SourceKey::MANUAL);
    }

    /**
     * {@inheritDoc}
     */
    public function label(): SourceLabel
    {
        return $this->label;
    }

    /**
     * {@inheritDoc}
     */
    public function supports(FetchFeed $feed): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function fetch(FetchRequest $request): RateBatch
    {
        throw new InvalidValueException('The manual source never fetches.');
    }

    /**
     * {@inheritDoc}
     */
    public function earliestDate(): ?RateDate
    {
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function timezone(): DateTimeZone
    {
        return $this->timezone;
    }
}
