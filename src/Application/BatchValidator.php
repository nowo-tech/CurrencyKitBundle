<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Application;

use Nowo\CurrencyKitBundle\Domain\Currency\CurrencyCode;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidValueException;
use Nowo\CurrencyKitBundle\Domain\Exception\RateSourceResponseException;
use Nowo\CurrencyKitBundle\Domain\Money\Decimal;
use Nowo\CurrencyKitBundle\Domain\Port\CurrencyCatalogueInterface;
use Nowo\CurrencyKitBundle\Domain\Port\RateStoreInterface;
use Nowo\CurrencyKitBundle\Domain\Rate\DayRates;
use Nowo\CurrencyKitBundle\Domain\Rate\PublicationInfo;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use Nowo\CurrencyKitBundle\Domain\Rate\RateValue;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;

use function count;
use function in_array;
use function ksort;

/**
 * Validates one fetched day before any write (contract section 8.5): codes in the ISO table (unknown codes and EUR
 * skipped with a warning, never stored), values by the rate grammar, dates not after today nor before the source's
 * earliest date, at most 400 currencies, and the plausibility gate against the previous stored day (within 31 days).
 */
final readonly class BatchValidator
{
    /** Currencies per day a feed may carry. */
    public const MAX_CURRENCIES = 400;

    /** How far back the previous stored day may be to serve as the plausibility baseline. */
    public const BASELINE_DAYS = 31;

    /**
     * Builds the validator.
     */
    public function __construct(
        private CurrencyCatalogueInterface $catalogue,
        private RateStoreInterface $store,
    ) {
    }

    /**
     * The validated day (canonical values, sorted by code) and the codes it skipped.
     *
     * @param list<string> $acceptImplausible codes whose implausible change is accepted
     * @param array<string, string>|null $baseline the previous day's values (null: read the previous stored day)
     *
     * @throws RateSourceResponseException
     *
     * @return array{day: DayRates, skipped: list<string>, accepted: list<string>}
     */
    public function validate(SourceKey $source, DayRates $day, RateDate $today, ?RateDate $earliest, int $maxChangePercent, array $acceptImplausible = [], ?array $baseline = null): array
    {
        if ($day->date->isAfter($today) || ($earliest instanceof RateDate && $day->date->isBefore($earliest))) {
            throw new RateSourceResponseException(RateSourceResponseException::REASON_INVALID_VALUE);
        }
        if (count($day->rates) > self::MAX_CURRENCIES) {
            throw new RateSourceResponseException(RateSourceResponseException::REASON_LIMIT);
        }

        $rates   = [];
        $skipped = [];
        foreach ($day->rates as $code => $value) {
            $code = (string) $code;
            if ($code === CurrencyCode::EUR || !$this->catalogue->exists($code)) {
                $skipped[] = $code;

                continue;
            }
            try {
                $rates[$code] = RateValue::canonical($value);
            } catch (InvalidValueException $e) {
                throw new RateSourceResponseException(RateSourceResponseException::REASON_INVALID_VALUE, null, $e);
            }
        }
        ksort($rates);

        $accepted = [];
        $baseline ??= $this->previousStored($source, $day->date);
        foreach ($rates as $code => $value) {
            $old = $baseline[$code] ?? null;
            if ($old === null || !Decimal::movedMoreThan($old, $value, $maxChangePercent)) {
                continue;
            }
            if (!in_array($code, $acceptImplausible, true)) {
                throw new RateSourceResponseException(RateSourceResponseException::REASON_IMPLAUSIBLE_CHANGE);
            }
            $accepted[] = $code;
        }

        return ['day' => new DayRates($day->date, $rates), 'skipped' => $skipped, 'accepted' => $accepted];
    }

    /**
     * The values of the newest stored day before the date.
     *
     * @return array<string, string>
     */
    private function previousStored(SourceKey $source, RateDate $date): array
    {
        $previous = $this->store->publicationBefore($source, $date);

        // Only a neighbouring publication is a baseline: years apart (a backfill) values legitimately differ.
        return !$previous instanceof PublicationInfo || $previous->rateDate->daysUntil($date) > self::BASELINE_DAYS ? [] : $this->store->ratesOn($source, $previous->rateDate);
    }
}
