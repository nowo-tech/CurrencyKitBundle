<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Exception;

use Throwable;

/**
 * The currency kit guard refused the action (`ACCESS_002`, 403).
 */
final class AccessDeniedException extends CurrencyKitException
{
    /** Host-facing code. */
    public const CODE = 'ACCESS_002';

    /**
     * Builds the exception with its static message.
     */
    public function __construct(string $message = 'Access denied.', ?Throwable $previous = null)
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
        return 403;
    }
}
