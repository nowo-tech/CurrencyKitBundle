<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Conversion;

use Nowo\CurrencyKitBundle\Domain\Money\Money;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceLabel;

/**
 * A converted amount with everything needed to show it honestly (contract section 5.3).
 */
final readonly class ConvertedAmount
{
    /** Always true: a reference conversion, never a payment, settlement or accounting rate. */
    public bool $isReference;

    /**
     * @param Money $amount rounded to the scale (default: the target's ISO minor units) with the rounding mode
     * @param string $exactAmount scale 12, HALF_EVEN, never rounded to the currency (for sums and caps)
     * @param string $rate effective original → target rate, scale 12
     * @param RateDate $rateDate the publication date actually used (may be earlier than the requested date)
     * @param SourceKey $source `manual` when an override applied to either leg, `identity` for the same currency
     * @param bool $stale true only with `on_stale: flag` (or `allowStale`) and data older than the threshold
     * @param string|null $manualRateId UUID of the manual rate used on any leg
     * @param list<RateLeg> $legs 0, 1 or 2 legs, each with its own date and source
     */
    public function __construct(
        public Money $original,
        public Money $amount,
        public string $exactAmount,
        public string $rate,
        public RateDate $rateDate,
        public RateDate $requestedDate,
        public SourceKey $source,
        public SourceLabel $sourceLabel,
        public bool $stale,
        public ?string $manualRateId,
        public array $legs,
    ) {
        $this->isReference = true;
    }
}
