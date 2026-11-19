<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Application;

use DateTimeInterface;
use Nowo\CurrencyKitBundle\Domain\Conversion\ConversionOptions;
use Nowo\CurrencyKitBundle\Domain\Conversion\ConvertedAmount;
use Nowo\CurrencyKitBundle\Domain\Conversion\RateLeg;
use Nowo\CurrencyKitBundle\Domain\Conversion\ResolvedRate;
use Nowo\CurrencyKitBundle\Domain\Currency\CurrencyCode;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidAmountException;
use Nowo\CurrencyKitBundle\Domain\Money\Decimal;
use Nowo\CurrencyKitBundle\Domain\Money\Money;
use Nowo\CurrencyKitBundle\Domain\Money\RoundingMode;
use Nowo\CurrencyKitBundle\Domain\Port\CurrencyCatalogueInterface;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;

/**
 * The default converter (contract section 9.1): legs through EUR, `rate = r(EUR→to) / r(EUR→from)` at scale 12
 * HALF_EVEN, `exact = amount × rate` at scale 12, the rounded amount at the target's minor units (or the requested
 * scale) with the configured or requested rounding mode. Storage only.
 */
final readonly class CurrencyConverter implements CurrencyConverterInterface
{
    /** The identity rate at scale 12. */
    private const ONE = '1.000000000000';

    /**
     * Builds the converter.
     */
    public function __construct(
        private RateResolver $resolver,
        private CurrencyCatalogueInterface $catalogue,
        private CurrencyKitSettings $settings,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function convert(Money|string $amount, CurrencyCode|string|null $currency, CurrencyCode|string $target, ?DateTimeInterface $on = null, ?ConversionOptions $options = null): ConvertedAmount
    {
        if ($amount instanceof Money) {
            if ($currency !== null) {
                throw new InvalidAmountException('Pass the currency inside the Money, not as the second argument.');
            }
            $money = $amount;
        } else {
            if ($currency === null) {
                throw new InvalidAmountException('A decimal-string amount needs its currency.');
            }
            $money = new Money($amount, $this->code($currency));
        }

        return $this->convertMoney($money, $this->rate($money->currency, $target, $on, $options), $options);
    }

    /**
     * {@inheritDoc}
     */
    public function rate(CurrencyCode|string $from, CurrencyCode|string $to, ?DateTimeInterface $on = null, ?ConversionOptions $options = null): ResolvedRate
    {
        $from      = $this->code($from);
        $to        = $this->code($to);
        $requested = $this->resolver->requestedDate($on, $options);
        $label     = $this->resolver->activeSource()->label();
        if ($from->equals($to)) {
            return new ResolvedRate($from, $to, self::ONE, $requested, $requested, new SourceKey(SourceKey::IDENTITY), $label, false, null, []);
        }

        $legs  = [];
        $stale = false;
        $rFrom = null;
        $rTo   = null;
        if (!$from->isEur()) {
            $resolved = $this->resolver->leg($from, $requested, $options?->allowStale);
            $legs[]   = $resolved['leg'];
            $stale    = $resolved['stale'];
            $rFrom    = $resolved['leg']->rate;
        }
        if (!$to->isEur()) {
            $resolved = $this->resolver->leg($to, $requested, $options?->allowStale);
            $legs[]   = $resolved['leg'];
            $stale    = $stale || $resolved['stale'];
            $rTo      = $resolved['leg']->rate;
        }

        [$rateDate, $source, $manualId] = $this->summary($legs, $requested);

        return new ResolvedRate($from, $to, Decimal::divide($rTo ?? '1', $rFrom ?? '1'), $rateDate, $requested, $source, $label, $stale, $manualId, $legs);
    }

    /**
     * {@inheritDoc}
     */
    public function convertMany(array $amounts, CurrencyCode|string $target, ?DateTimeInterface $on = null, ?ConversionOptions $options = null): array
    {
        $rates   = [];
        $results = [];
        foreach ($amounts as $money) {
            $rates[$money->currency->code] ??= $this->rate($money->currency, $target, $on, $options);
            $results[] = $this->convertMoney($money, $rates[$money->currency->code], $options);
        }

        return $results;
    }

    /**
     * Applies a resolved rate to an amount.
     *
     * @throws InvalidAmountException when the result has more than 18 integer digits
     */
    private function convertMoney(Money $money, ResolvedRate $rate, ?ConversionOptions $options): ConvertedAmount
    {
        $exact = $rate->from->equals($rate->to)
            ? Decimal::round($money->amount, Decimal::SCALE, RoundingMode::HalfEven)
            : Decimal::multiply($money->amount, $rate->rate);
        $scale   = $options->scale ?? $rate->to->minorUnits();
        $rounded = Decimal::round($exact, $scale, $options->roundingMode ?? $this->settings->roundingMode);

        return new ConvertedAmount(
            $money,
            new Money($rounded, $rate->to),
            $exact,
            $rate->rate,
            $rate->rateDate,
            $rate->requestedDate,
            $rate->source,
            $rate->sourceLabel,
            $rate->stale,
            $rate->manualRateId,
            $rate->legs,
        );
    }

    /**
     * The code, through the catalogue when given as a string.
     */
    private function code(CurrencyCode|string $code): CurrencyCode
    {
        return $code instanceof CurrencyCode ? $code : $this->catalogue->get($code);
    }

    /**
     * The reported rate date (the older leg's), the source (`manual` when an override applied to either leg) and the
     * manual rate id of the first leg that used one.
     *
     * @param list<RateLeg> $legs
     *
     * @return array{0: RateDate, 1: SourceKey, 2: string|null}
     */
    private function summary(array $legs, RateDate $requested): array
    {
        $oldest = null;
        $source = null;
        $manual = null;
        foreach ($legs as $leg) {
            $oldest = $oldest === null || $leg->rateDate->isBefore($oldest) ? $leg->rateDate : $oldest;
            $source ??= $leg->source;
            if ($leg->manualRateId !== null && $manual === null) {
                $manual = $leg->manualRateId;
                $source = $leg->source;
            }
        }

        return [$oldest ?? $requested, $source ?? $this->settings->activeSource, $manual];
    }
}
