<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Exception;

use Throwable;

/**
 * A permanent source failure (`CURRENCY_007`, 502): a 4xx answer, malformed XML or JSON, a size cap, a wrong base, a
 * refused value or an implausible change (contract sections 8.5 and 13.3). The message never echoes the body; only the
 * reason (and, for size caps, the byte count) is kept.
 */
final class RateSourceResponseException extends CurrencyKitException
{
    /** Host-facing code. */
    public const CODE = 'CURRENCY_007';

    /** A 4xx answer. */
    public const REASON_CLIENT_ERROR = 'client_error';

    /** The body is larger than the feed's cap. */
    public const REASON_TOO_LARGE = 'too_large';

    /** The body is not well-formed XML / JSON, or has an unexpected shape. */
    public const REASON_MALFORMED = 'malformed';

    /** A DOCTYPE (XXE, entity expansion) was found. */
    public const REASON_DOCTYPE = 'doctype';

    /** The feed is not EUR-based. */
    public const REASON_WRONG_BASE = 'wrong_base';

    /** A value or a date is outside the grammar or range. */
    public const REASON_INVALID_VALUE = 'invalid_value';

    /** A value moved more than `sync.max_daily_change_percent` from the previous stored day. */
    public const REASON_IMPLAUSIBLE_CHANGE = 'implausible_change';

    /** The same date twice in one batch. */
    public const REASON_DUPLICATE_DATE = 'duplicate_date';

    /** Too many days, currencies or nesting levels. */
    public const REASON_LIMIT = 'limit';

    /** The local file to import cannot be read. */
    public const REASON_UNREADABLE = 'unreadable';

    /**
     * @param string $reason one of the `REASON_*` constants
     * @param int|null $bytes the byte count when relevant (size caps)
     */
    public function __construct(public readonly string $reason, public readonly ?int $bytes = null, ?Throwable $previous = null)
    {
        parent::__construct('The rate source returned a response that cannot be used.', $previous);
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
        return 502;
    }
}
