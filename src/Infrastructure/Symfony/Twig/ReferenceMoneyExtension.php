<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Symfony\Twig;

use DateTimeInterface;
use IntlDateFormatter;
use Nowo\CurrencyKitBundle\Application\CurrencyConverterInterface;
use Nowo\CurrencyKitBundle\Domain\Conversion\ConversionOptions;
use Nowo\CurrencyKitBundle\Domain\Conversion\ConvertedAmount;
use Nowo\CurrencyKitBundle\Domain\Exception\CurrencyKitException;
use Nowo\CurrencyKitBundle\Domain\Money\Decimal;
use Nowo\CurrencyKitBundle\Domain\Money\Money;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use NumberFormatter;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;
use TypeError;

use function get_debug_type;
use function is_float;
use function is_int;
use function is_string;

/**
 * Twig output of reference conversions (contract section 12.2), plain text that Twig autoescapes:
 * `{{ cost|reference_money('EUR', usage.at) }}` prints `≈ €12.30 (reference rate ECB 2026-11-19)`.
 *
 * The locale is read from the current request on every call (never stored); a NumberFormatter is created per call.
 * Floats are refused (a TypeError when `debug`, the missing-rate note otherwise).
 */
final class ReferenceMoneyExtension extends AbstractExtension
{
    /** Translation domain. */
    private const DOMAIN = 'NowoCurrencyKitBundle';

    /**
     * @param string $onMissingRate `note` | `original` | `exception`
     * @param string $dateFormat `iso` | `locale`
     */
    public function __construct(
        private readonly CurrencyConverterInterface $converter,
        private readonly TranslatorInterface&LocaleAwareInterface $translator,
        private readonly RequestStack $requests,
        private readonly LoggerInterface $logger,
        private readonly string $onMissingRate = 'note',
        private readonly string $dateFormat = 'iso',
        private readonly bool $debug = false,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function getFilters(): array
    {
        return [new TwigFilter('reference_money', $this->referenceMoney(...))];
    }

    /**
     * {@inheritDoc}
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('reference_money_parts', $this->parts(...)),
            new TwigFunction('reference_rate_note', $this->note(...)),
        ];
    }

    /**
     * `≈ {formatted} ({label} {date})`, `(stale)` appended when flagged; the missing-rate behaviour otherwise.
     */
    public function referenceMoney(mixed $value, string $target, DateTimeInterface|string|null $on = null, ?string $from = null, ?int $scale = null): string
    {
        $converted = $this->convert($value, $target, $on, $from, $scale);
        if (!$converted instanceof ConvertedAmount) {
            return $this->onMissingRate === 'original' ? '' : $this->translator->trans('twig.no_reference_rate', [], self::DOMAIN, $this->locale());
        }
        $text = $this->translator->trans('twig.reference_money', [
            '%amount%' => $this->format($converted, $scale),
            '%source%' => $converted->sourceLabel->value,
            '%date%'   => $this->date($converted->rateDate),
        ], self::DOMAIN, $this->locale());

        return $converted->stale ? $text . ' ' . $this->translator->trans('twig.stale', [], self::DOMAIN, $this->locale()) : $text;
    }

    /**
     * The parts for hosts that build their own markup; null when no rate (with `on_missing_rate` not `exception`).
     *
     * @return array{amount: string, formatted: string, currency: string, rate: string, rate_date: string, source_label: string, stale: bool, manual: bool}|null
     */
    public function parts(mixed $value, string $target, DateTimeInterface|string|null $on = null, ?string $from = null, ?int $scale = null): ?array
    {
        $converted = $this->convert($value, $target, $on, $from, $scale);
        if (!$converted instanceof ConvertedAmount) {
            return null;
        }

        return [
            'amount'       => $converted->amount->amount,
            'formatted'    => $this->format($converted, $scale),
            'currency'     => $converted->amount->currency->code,
            'rate'         => $converted->rate,
            'rate_date'    => $converted->rateDate->value,
            'source_label' => $converted->sourceLabel->value,
            'stale'        => $converted->stale,
            'manual'       => $converted->manualRateId !== null,
        ];
    }

    /**
     * The translated "reference rates, for information only" sentence.
     */
    public function note(): string
    {
        return $this->translator->trans('twig.reference_note', [], self::DOMAIN, $this->locale());
    }

    /**
     * Converts, or returns null (logged) when no rate can be used and the mode is not `exception`.
     *
     * @throws TypeError for a float or an unsupported value when `debug` is on
     * @throws CurrencyKitException with `on_missing_rate: exception`
     */
    private function convert(mixed $value, string $target, DateTimeInterface|string|null $on, ?string $from, ?int $scale): ?ConvertedAmount
    {
        try {
            if (is_float($value) || !($value instanceof Money || is_string($value) || is_int($value))) {
                throw new TypeError('reference_money accepts a Money, a decimal string or an int, not ' . get_debug_type($value) . '.');
            }
            $options = new ConversionOptions($scale, null, null, is_string($on) ? RateDate::of($on) : null);
            $instant = $on instanceof DateTimeInterface ? $on : null;

            return $value instanceof Money
                ? $this->converter->convert($value, null, $target, $instant, $options)
                : $this->converter->convert((string) $value, $from, $target, $instant, $options);
        } catch (TypeError $e) {
            if ($this->debug || $this->onMissingRate === 'exception') {
                throw $e;
            }
            $this->logger->warning('Currency kit: reference_money received an unsupported value.', ['type' => get_debug_type($value)]);

            return null;
        } catch (CurrencyKitException $e) {
            if ($this->onMissingRate === 'exception') {
                throw $e;
            }
            $this->logger->warning('Currency kit: no reference rate for a Twig conversion.', ['code' => $e->errorCode(), 'target' => $target]);

            return null;
        }
    }

    /**
     * The amount with symbol in the request locale (the currency's minor units unless a scale is given).
     */
    private function format(ConvertedAmount $converted, ?int $scale): string
    {
        // NumberFormatter takes a float: beyond 15 significant digits the plain decimal is printed instead (audit VR-6).
        if (Decimal::significantDigits($converted->amount->amount) > 15) {
            return $converted->amount->amount . ' ' . $converted->amount->currency->code;
        }
        $formatter = new NumberFormatter($this->locale(), NumberFormatter::CURRENCY);
        $digits    = $scale ?? $converted->amount->currency->minorUnits();
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $digits);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $digits);
        $formatted = $formatter->formatCurrency((float) $converted->amount->amount, $converted->amount->currency->code);

        return $formatted === false ? $converted->amount->amount . ' ' . $converted->amount->currency->code : $formatted;
    }

    /**
     * The rate date: ISO (default, unambiguous) or the locale's medium format.
     */
    private function date(RateDate $date): string
    {
        if ($this->dateFormat !== 'locale') {
            return $date->value;
        }
        $formatter = new IntlDateFormatter($this->locale(), IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, 'UTC');
        $formatted = $formatter->format($date->toDateTime());

        return $formatted === false ? $date->value : $formatted;
    }

    /**
     * The request locale, else the translator's.
     */
    private function locale(): string
    {
        return $this->requests->getCurrentRequest()?->getLocale() ?? $this->translator->getLocale();
    }
}
