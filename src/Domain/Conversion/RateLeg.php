<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Conversion;

use Nowo\CurrencyKitBundle\Domain\Currency\CurrencyCode;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;

/**
 * One EUR → currency leg of a conversion, with its own date and source (a manual rate on one leg only is visible).
 */
final readonly class RateLeg
{
    /**
     * @param string $rate the EUR → currency value used (canonical)
     * @param string|null $manualRateId the manual rate's UUID when one applied
     */
    public function __construct(
        public CurrencyCode $currency,
        public string $rate,
        public RateDate $rateDate,
        public SourceKey $source,
        public ?string $manualRateId = null,
    ) {
    }
}
