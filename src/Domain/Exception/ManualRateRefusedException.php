<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Exception;

use Throwable;

/**
 * A manual rate refused by a rule: a past window without `manual.allow_past`, a deviation beyond `manual.max_deviation_percent` without force, a bad reason or window (`CURRENCY_011`, 422).
 */
final class ManualRateRefusedException extends CurrencyKitException
{
    /** Host-facing code. */
    public const CODE = 'CURRENCY_011';

    /**
     * Builds the exception with its static message.
     */
    public function __construct(string $message = 'The manual rate was refused.', ?Throwable $previous = null)
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
