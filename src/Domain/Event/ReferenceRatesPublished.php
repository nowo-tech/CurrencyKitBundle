<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Event;

use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use Nowo\CurrencyKitBundle\Domain\Rate\RateOrigin;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;

/**
 * A publication day stored for the first time. Dispatched after the commit; carries value objects and ids only.
 */
final readonly class ReferenceRatesPublished
{
    /**
     * Builds the event.
     */
    public function __construct(
        public SourceKey $source,
        public RateDate $rateDate,
        public int $currencyCount,
        public RateOrigin $origin,
    ) {
    }
}
