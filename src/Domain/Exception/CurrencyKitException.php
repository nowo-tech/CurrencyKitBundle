<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Exception;

use RuntimeException;
use Throwable;

/**
 * Base of every bundle exception (contract section 5.5). Messages are static English strings with no amount, id or
 * input in them; {@see self::errorCode()} is the host-facing code (`CURRENCY_001`…).
 */
abstract class CurrencyKitException extends RuntimeException
{
    /**
     * @param string $message a static message (never user input)
     */
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Host-facing error code, shape `^[A-Z][A-Z0-9]*_[0-9]{3}$`.
     */
    abstract public function errorCode(): string;

    /**
     * HTTP status a UI or API answers with.
     */
    abstract public function httpStatus(): int;
}
