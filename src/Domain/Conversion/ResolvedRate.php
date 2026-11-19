<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Conversion;

use Nowo\CurrencyKitBundle\Domain\Currency\CurrencyCode;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceLabel;

/**
 * The effective `from → to` reference rate of a date (contract section 9.1), without an amount.
 */
final readonly class ResolvedRate
{
    /** Always true: these are reference rates, never payment or accounting rates. */
    public bool $isReference;

    /**
     * @param string $rate effective rate, scale 12 (cross through EUR)
     * @param RateDate $rateDate the publication date used (the older leg's date)
     * @param list<RateLeg> $legs 0 (same currency), 1 (EUR ↔ X) or 2 (X → EUR → Y)
     */
    public function __construct(
        public CurrencyCode $from,
        public CurrencyCode $to,
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
