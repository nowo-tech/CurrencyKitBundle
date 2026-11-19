<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Doctrine\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Read model of `<prefix>currency_rate_publication` (one row per source and publication day). Mapped read-only so
 * schema tools see the table and a standard host can query it; the bundle writes it through DBAL only.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'currency_rate_publication')]
#[ORM\UniqueConstraint(name: 'currency_rate_publication_source_date_uniq', columns: ['source', 'rate_date'])]
class RatePublication
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36, options: ['fixed' => true])]
    public string $id;

    #[ORM\Column(type: 'string', length: 32)]
    public string $source;

    #[ORM\Column(name: 'rate_date', type: 'date_immutable')]
    public DateTimeImmutable $rateDate;

    #[ORM\Column(type: 'string', length: 16)]
    public string $origin;

    #[ORM\Column(name: 'currency_count', type: 'smallint')]
    public int $currencyCount;

    #[ORM\Column(name: 'content_hash', type: 'string', length: 64, options: ['fixed' => true])]
    public string $contentHash;

    #[ORM\Column(name: 'first_stored_at', type: 'datetime_immutable')]
    public DateTimeImmutable $firstStoredAt;

    #[ORM\Column(name: 'last_seen_at', type: 'datetime_immutable')]
    public DateTimeImmutable $lastSeenAt;

    #[ORM\Column(type: 'integer')]
    public int $version;
}
