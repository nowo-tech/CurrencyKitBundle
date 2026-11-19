<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Doctrine\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Read model of `<prefix>currency_rate`: one EUR → currency value per source, publication day and currency
 * (`DECIMAL(24,12)`, read as a string). Written through DBAL only.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'currency_rate')]
#[ORM\UniqueConstraint(name: 'currency_rate_source_date_currency_uniq', columns: ['source', 'rate_date', 'currency'])]
#[ORM\Index(name: 'currency_rate_source_currency_date_idx', columns: ['source', 'currency', 'rate_date'])]
class ReferenceRate
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36, options: ['fixed' => true])]
    public string $id;

    #[ORM\Column(type: 'string', length: 32)]
    public string $source;

    #[ORM\Column(name: 'rate_date', type: 'date_immutable')]
    public DateTimeImmutable $rateDate;

    #[ORM\Column(type: 'string', length: 3, options: ['fixed' => true])]
    public string $currency;

    #[ORM\Column(type: 'decimal', precision: 24, scale: 12)]
    public string $rate;

    #[ORM\Column(name: 'publication_id', type: 'string', length: 36, options: ['fixed' => true])]
    public string $publicationId;
}
