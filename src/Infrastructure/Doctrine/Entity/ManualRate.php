<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Doctrine\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Read model of `<prefix>currency_manual_rate` (versioned, never deleted). Written through DBAL only.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'currency_manual_rate')]
#[ORM\Index(name: 'currency_manual_rate_currency_status_from_idx', columns: ['currency', 'status', 'valid_from'])]
class ManualRate
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36, options: ['fixed' => true])]
    public string $id;

    #[ORM\Column(type: 'string', length: 3, options: ['fixed' => true])]
    public string $currency;

    #[ORM\Column(type: 'decimal', precision: 24, scale: 12)]
    public string $rate;

    #[ORM\Column(name: 'valid_from', type: 'date_immutable')]
    public DateTimeImmutable $validFrom;

    #[ORM\Column(name: 'valid_until', type: 'date_immutable', nullable: true)]
    public ?DateTimeImmutable $validUntil;

    #[ORM\Column(type: 'string', length: 500)]
    public string $reason;

    #[ORM\Column(type: 'string', length: 8)]
    public string $status;

    #[ORM\Column(name: 'created_by', type: 'string', length: 128, nullable: true)]
    public ?string $createdBy;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    public DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_by', type: 'string', length: 128, nullable: true)]
    public ?string $updatedBy;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    public DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'integer')]
    public int $version;
}
