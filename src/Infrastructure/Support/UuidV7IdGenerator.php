<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Support;

use Nowo\CurrencyKitBundle\Domain\Port\IdGeneratorInterface;
use Symfony\Component\Uid\Uuid;

/**
 * UUIDv7 ids (RFC 4122 strings, 36 characters) from `symfony/uid`.
 */
final readonly class UuidV7IdGenerator implements IdGeneratorInterface
{
    /**
     * {@inheritDoc}
     */
    public function generate(): string
    {
        return Uuid::v7()->toRfc4122();
    }
}
