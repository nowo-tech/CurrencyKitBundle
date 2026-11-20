<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Exception;

use Throwable;

/**
 * An amount outside the decimal-string grammar of contract section 5.2 (`CURRENCY_002`, 422).
 */
final class InvalidAmountException extends CurrencyKitException
{
    /** Host-facing code. */
    public const CODE = 'CURRENCY_002';

    /**
     * Builds the exception with its static message.
     */
    public function __construct(string $message = 'The amount is not a valid decimal string.', ?Throwable $previous = null)
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
        return 422;
    }
}
