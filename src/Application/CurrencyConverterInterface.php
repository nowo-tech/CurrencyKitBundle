<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Application;

use DateTimeInterface;
use Nowo\CurrencyKitBundle\Domain\Conversion\ConversionOptions;
use Nowo\CurrencyKitBundle\Domain\Conversion\ConvertedAmount;
use Nowo\CurrencyKitBundle\Domain\Conversion\ResolvedRate;
use Nowo\CurrencyKitBundle\Domain\Currency\CurrencyCode;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidAmountException;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidCurrencyCodeException;
use Nowo\CurrencyKitBundle\Domain\Exception\RateNotAvailableException;
use Nowo\CurrencyKitBundle\Domain\Exception\StaleRateException;
use Nowo\CurrencyKitBundle\Domain\Money\Money;

/**
 * Converts amounts at reference rates (contract sections 9 and 12.1). Storage only: never a network call, never a
 * write, never a lock.
 */
interface CurrencyConverterInterface
{
    /**
     * Converts an amount. When `$amount` is a {@see Money}, `$currency` is null; with a decimal string it is the
     * amount's currency. `$on` is converted to the source calendar's date (null: today there).
     *
     * @throws InvalidAmountException
     * @throws InvalidCurrencyCodeException
     * @throws RateNotAvailableException
     * @throws StaleRateException
     */
    public function convert(Money|string $amount, CurrencyCode|string|null $currency, CurrencyCode|string $target, ?DateTimeInterface $on = null, ?ConversionOptions $options = null): ConvertedAmount;

    /**
     * The effective rate without an amount.
     *
     * @throws InvalidCurrencyCodeException
     * @throws RateNotAvailableException
     * @throws StaleRateException
     */
    public function rate(CurrencyCode|string $from, CurrencyCode|string $to, ?DateTimeInterface $on = null, ?ConversionOptions $options = null): ResolvedRate;

    /**
     * Converts several amounts to one target: one resolution per distinct currency and date.
     *
     * @param list<Money> $amounts
     *
     * @return list<ConvertedAmount>
     */
    public function convertMany(array $amounts, CurrencyCode|string $target, ?DateTimeInterface $on = null, ?ConversionOptions $options = null): array;
}
