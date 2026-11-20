<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Event;

use Nowo\CurrencyKitBundle\Domain\Currency\CurrencyCode;
use Nowo\CurrencyKitBundle\Domain\Manual\ActorRef;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;

/**
 * A manual rate was updated. Dispatched after the commit; carries value objects and ids only.
 */
final readonly class ManualRateUpdated
{
    /**
     * Builds the event.
     */
    public function __construct(
        public string $id,
        public CurrencyCode $currency,
        public string $rate,
        public RateDate $validFrom,
        public ?RateDate $validUntil,
        public ?ActorRef $actor,
        public int $version,
    ) {
    }
}
