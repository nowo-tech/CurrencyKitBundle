<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Event;

use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;

/**
 * A published value changed, a currency was added to or removed from a published day (`oldRate` / `newRate` null); `applied` false under `on_changed_value: keep` and for removals. Dispatched after the commit; carries value objects and ids only.
 */
final readonly class ReferenceRateChanged
{
    /**
     * Builds the event.
     */
    public function __construct(
        public SourceKey $source,
        public RateDate $rateDate,
        public string $currency,
        public ?string $oldRate,
        public ?string $newRate,
        public bool $applied,
        public string $changeId,
    ) {
    }
}
