<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Conversion;

use Nowo\CurrencyKitBundle\Domain\Exception\InvalidValueException;
use Nowo\CurrencyKitBundle\Domain\Money\RoundingMode;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;

/**
 * Per-call options of a conversion (contract section 5.3): the scale of the rounded amount (0–12, default the target's
 * ISO minor units), its rounding mode (default `conversion.rounding_mode`), whether a stale rate is allowed (overrides
 * `conversion.on_stale` for this call) and a date-only `onDate` that wins over the `$on` instant (QC-11).
 */
final readonly class ConversionOptions
{
    /**
     * @throws InvalidValueException when the scale is outside 0..12
     */
    public function __construct(
        public ?int $scale = null,
        public ?RoundingMode $roundingMode = null,
        public ?bool $allowStale = null,
        public ?RateDate $onDate = null,
    ) {
        if ($scale !== null && ($scale < 0 || $scale > 12)) {
            throw new InvalidValueException('The scale must be between 0 and 12.');
        }
    }
}
