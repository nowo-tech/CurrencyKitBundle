<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Exception;

use Throwable;

/**
 * A sync, backfill or fetch while `outbound.enabled` is false (`CURRENCY_005`).
 */
final class OutboundDisabledException extends CurrencyKitException
{
    /** Host-facing code. */
    public const CODE = 'CURRENCY_005';

    /**
     * Builds the exception with its static message.
     */
    public function __construct(string $message = 'Outbound calls are disabled (outbound.enabled: false).', ?Throwable $previous = null)
    {
        parent::__construct($message, $previous);
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
