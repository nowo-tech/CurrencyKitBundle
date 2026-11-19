<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Exception;

use Throwable;

/**
 * A transient source failure (`CURRENCY_006`, 502): timeout, 5xx, DNS or transport error, a guard refusal or a
 * redirect. Retried from the console and Messenger paths.
 */
final class RateSourceUnavailableException extends CurrencyKitException
{
    /** Host-facing code. */
    public const CODE = 'CURRENCY_006';

    /** The URL guard refused the URL (scheme, host or resolved address). */
    public const REASON_GUARD_REFUSED = 'guard_refused';

    /** The source answered with a redirect (never followed: the guard's pin does not cover a second URL). */
    public const REASON_REDIRECT_REFUSED = 'redirect_refused';

    /** A 5xx answer. */
    public const REASON_SERVER_ERROR = 'server_error';

    /** Timeout, DNS or transport failure. */
    public const REASON_TRANSPORT = 'transport';

    /**
     * @param string $reason one of the `REASON_*` constants
     */
    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct('The rate source is unavailable. Try again later.', $previous);
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
