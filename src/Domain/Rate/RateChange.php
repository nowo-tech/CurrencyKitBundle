<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Rate;

use DateTimeImmutable;

/**
 * A row of the append-only change log: a published value that changed (`old` → `new`), a currency added to a published
 * day (`old` null) or removed from it (`new` null; never deleted from the stored day).
 */
final readonly class RateChange
{
    /**
     * Builds the change view.
     */
    public function __construct(
        public string $id,
        public SourceKey $source,
        public RateDate $rateDate,
        public string $currency,
        public ?string $oldRate,
        public ?string $newRate,
        public RateOrigin $origin,
        public DateTimeImmutable $detectedAt,
        public bool $applied,
        public string $runId,
    ) {
    }
}
