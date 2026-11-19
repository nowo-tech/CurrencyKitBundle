<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Manual;

use Nowo\CurrencyKitBundle\Domain\Currency\CurrencyCode;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidValueException;
use Nowo\CurrencyKitBundle\Domain\Exception\ManualRateRefusedException;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use Nowo\CurrencyKitBundle\Domain\Rate\RateValue;

use function mb_strlen;
use function preg_match;
use function trim;

/**
 * A manual rate to create or the new values of one (contract section 10): EUR → currency, a window
 * `[validFrom, validUntil]` (until null = open), a reason of 5–500 characters and the deviation `force` flag.
 */
final readonly class ManualRateInput
{
    /** The canonical rate. */
    public string $rate;

    /** The trimmed reason. */
    public string $reason;

    /**
     * @throws ManualRateRefusedException when the currency is EUR, the window is reversed or the reason is invalid
     * @throws InvalidValueException when the rate is not a valid value
     */
    public function __construct(
        public CurrencyCode $currency,
        string $rate,
        public RateDate $validFrom,
        public ?RateDate $validUntil,
        string $reason,
        public bool $force = false,
    ) {
        $this->rate = RateValue::canonical($rate);
        $reason     = trim($reason);
        if ($currency->isEur()) {
            throw new ManualRateRefusedException('A manual rate cannot be set for the pivot currency EUR.');
        }
        if ($validUntil instanceof RateDate && $validUntil->isBefore($validFrom)) {
            throw new ManualRateRefusedException('The window ends before it starts.');
        }
        $length = mb_strlen($reason, 'UTF-8');
        if ($length < 5 || $length > 500 || preg_match('//u', $reason) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason) === 1) {
            throw new ManualRateRefusedException('The reason must be 5 to 500 characters of plain text.');
        }
        $this->reason = $reason;
    }
}
