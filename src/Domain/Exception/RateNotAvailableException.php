<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Exception;

/**
 * No usable rate for a currency and date (`CURRENCY_003`, 404 in a UI). The reason is one of the `REASON_*` constants;
 * the message is static and carries no code or date.
 */
final class RateNotAvailableException extends CurrencyKitException
{
    /** Host-facing code. */
    public const CODE = 'CURRENCY_003';

    /** The active source never offered the currency on or near that date (a suspended or unknown currency). */
    public const REASON_UNKNOWN_FOR_SOURCE = 'unknown_for_source';

    /** The date is before the source's earliest stored publication: run a backfill. */
    public const REASON_BEFORE_HISTORY = 'before_history';

    /** No publication within `conversion.max_lookback_days` of the date. */
    public const REASON_NO_PUBLICATION_IN_LOOKBACK = 'no_publication_in_lookback';

    /** The date is after today in the source calendar. */
    public const REASON_FUTURE_DATE = 'future_date';

    private const MESSAGES = [
        self::REASON_UNKNOWN_FOR_SOURCE         => 'No reference rate for this currency from the active source on or near that date.',
        self::REASON_BEFORE_HISTORY             => 'The date is before the stored rate history. Run nowo:currency-kit:rates:backfill to load older days.',
        self::REASON_NO_PUBLICATION_IN_LOOKBACK => 'No reference rate publication within the lookback window of that date.',
        self::REASON_FUTURE_DATE                => 'Reference rates for a future date do not exist.',
    ];

    /**
     * @param string $reason one of the `REASON_*` constants
     */
    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGES[$reason] ?? self::MESSAGES[self::REASON_UNKNOWN_FOR_SOURCE]);
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
        return 404;
    }
}
