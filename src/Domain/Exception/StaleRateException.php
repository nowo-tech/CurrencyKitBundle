<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Exception;

/**
 * The latest usable publication is older than `conversion.max_staleness_days` and `conversion.on_stale` is `fail`
 * (`CURRENCY_004`, 503 in a UI): the sync has stopped.
 */
final class StaleRateException extends CurrencyKitException
{
    /** Host-facing code. */
    public const CODE = 'CURRENCY_004';

    /**
     * @param string $latestDate the latest usable publication date (Y-m-d)
     * @param int $ageDays calendar days between that date and the requested date
     */
    public function __construct(public readonly string $latestDate, public readonly int $ageDays)
    {
        parent::__construct('The reference rates are stale: the latest publication is older than conversion.max_staleness_days.');
    }

    /**
     * {@inheritDoc}
     */
    public function errorCode(): string
    {
        return self::CODE;
    }

    /**
     * {@inheritDoc}
     */
    public function httpStatus(): int
    {
        return 503;
    }
}
