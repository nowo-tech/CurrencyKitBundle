<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Exception;

use Throwable;

/**
 * An unknown manual rate id (`CURRENCY_010`, 404).
 */
final class ManualRateNotFoundException extends CurrencyKitException
{
    /** Host-facing code. */
    public const CODE = 'CURRENCY_010';

    /**
     * Builds the exception with its static message.
     */
    public function __construct(string $message = 'The manual rate does not exist.', ?Throwable $previous = null)
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
        return 404;
    }
}
