<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Exception;

use Throwable;

/**
 * A currency code that is not `^[A-Z]{3}$`, not in the ISO 4217 table, or an X-code (`CURRENCY_001`, 422).
 */
final class InvalidCurrencyCodeException extends CurrencyKitException
{
    /** Host-facing code. */
    public const CODE = 'CURRENCY_001';

    /**
     * Builds the exception with its static message.
     */
    public function __construct(string $message = 'The currency code is not a supported ISO 4217 code.', ?Throwable $previous = null)
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
