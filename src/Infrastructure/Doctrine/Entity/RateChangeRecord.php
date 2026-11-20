<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Doctrine\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Read model of `<prefix>currency_rate_change`, the append-only change log (`old_rate` NULL: a currency added to a
 * published day; `new_rate` NULL: removed, never deleted from the day). Inserted through DBAL only; never updated.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'currency_rate_change')]
#[ORM\Index(name: 'currency_rate_change_source_date_idx', columns: ['source', 'rate_date'])]
#[ORM\Index(name: 'currency_rate_change_detected_idx', columns: ['detected_at'])]
class RateChangeRecord
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

    #[ORM\Column(name: 'old_rate', type: 'decimal', precision: 24, scale: 12, nullable: true)]
    public ?string $oldRate;

    #[ORM\Column(name: 'new_rate', type: 'decimal', precision: 24, scale: 12, nullable: true)]
    public ?string $newRate;

    #[ORM\Column(type: 'string', length: 16)]
    public string $origin;

    #[ORM\Column(name: 'detected_at', type: 'datetime_immutable')]
    public DateTimeImmutable $detectedAt;

    #[ORM\Column(type: 'boolean')]
    public bool $applied;

    #[ORM\Column(name: 'run_id', type: 'string', length: 36, options: ['fixed' => true])]
    public string $runId;
}
