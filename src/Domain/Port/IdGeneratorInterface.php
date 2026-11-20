<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Port;

/**
 * Row ids (UUIDv7 strings, 36 characters, by default).
 */
interface IdGeneratorInterface
{
    /**
     * A new id.
     */
    public function generate(): string;
}
