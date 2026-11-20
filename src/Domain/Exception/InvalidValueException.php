<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Exception;

use Throwable;

/**
 * A value object refused its input (a date, a source key, a label, an actor ref, a rate) (`CURRENCY_012`, 422).
 */
final class InvalidValueException extends CurrencyKitException
{
    /** Host-facing code. */
    public const CODE = 'CURRENCY_012';

    /**
     * Builds the exception with its static message.
     */
    public function __construct(string $message = 'A value is not valid.', ?Throwable $previous = null)
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
