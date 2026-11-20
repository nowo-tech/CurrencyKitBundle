<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Exception;

use Throwable;

/**
 * A manual rate edited on an old version (`CURRENCY_008`, 409; a domain conflict, no `X-Conflict-Code`).
 */
final class StaleManualRateException extends CurrencyKitException
{
    /** Host-facing code. */
    public const CODE = 'CURRENCY_008';

    /**
     * Builds the exception with its static message.
     */
    public function __construct(string $message = 'The manual rate changed since it was read. Reload it and try again.', ?Throwable $previous = null)
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
