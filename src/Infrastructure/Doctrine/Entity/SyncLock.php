<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Doctrine\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Read model of `<prefix>currency_sync_lock`, the sync run lease (`sync.lock.store: database`).
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'currency_sync_lock')]
class SyncLock
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 64)]
    public string $name;

    #[ORM\Column(name: 'locked_until', type: 'datetime_immutable')]
    public DateTimeImmutable $lockedUntil;

    #[ORM\Column(type: 'string', length: 36)]
    public string $holder;
}
