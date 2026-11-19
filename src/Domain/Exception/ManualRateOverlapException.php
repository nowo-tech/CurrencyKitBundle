<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Exception;

use Throwable;

/**
 * Two active manual rates for the same currency would cover the same date (`CURRENCY_009`, 409).
 */
final class ManualRateOverlapException extends CurrencyKitException
{
    /** Host-facing code. */
    public const CODE = 'CURRENCY_009';

    /**
     * Builds the exception with its static message.
     */
    public function __construct(string $message = 'Another active manual rate for this currency already covers part of that window.', ?Throwable $previous = null)
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
        return 409;
    }
}
