<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Tests\Unit\Infrastructure;

use DateTimeImmutable;
use DateTimeInterface;
use LogicException;
use Nowo\CurrencyKitBundle\Application\CurrencyConverterInterface;
use Nowo\CurrencyKitBundle\Domain\Conversion\ConversionOptions;
use Nowo\CurrencyKitBundle\Domain\Conversion\ConvertedAmount;
use Nowo\CurrencyKitBundle\Domain\Conversion\ResolvedRate;
use Nowo\CurrencyKitBundle\Domain\Currency\CurrencyCode;
use Nowo\CurrencyKitBundle\Domain\Exception\RateNotAvailableException;
use Nowo\CurrencyKitBundle\Domain\Money\Money;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceLabel;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Twig\ReferenceMoneyExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use TypeError;

use function dirname;
use function html_entity_decode;
use function substr;

use const ENT_HTML5;
use const ENT_QUOTES;

final class ReferenceMoneyExtensionTest extends TestCase
{
    /** @var list<array{amount: Money|string, currency: CurrencyCode|string|null, on: ?DateTimeInterface, options: ?ConversionOptions}> */
    private array $calls = [];

    private function converter(string $label = 'ECB', bool $stale = false, ?string $manualId = null, bool $missing = false): CurrencyConverterInterface
    {
        $calls = &$this->calls;

        return new class($label, $stale, $manualId, $missing, $calls) implements CurrencyConverterInterface {
            /**
             * @param list<mixed> $calls
             */
            public function __construct(private string $label, private bool $stale, private ?string $manualId, private bool $missing, private array &$calls) // @phpstan-ignore property.onlyWritten
            {
            }

            public function convert(Money|string $amount, CurrencyCode|string|null $currency, CurrencyCode|string $target, ?DateTimeInterface $on = null, ?ConversionOptions $options = null): ConvertedAmount
            {
                $this->calls[] = ['amount' => $amount, 'currency' => $currency, 'on' => $on, 'options' => $options];
                if ($this->missing) {
                    throw new RateNotAvailableException(RateNotAvailableException::REASON_UNKNOWN_FOR_SOURCE);
                }
                $to = CurrencyCode::of((string) $target);

                return new ConvertedAmount(Money::of('11.19', CurrencyCode::of('USD')), Money::of($options?->scale === 4 ? '12.3046' : '12.3', $to), '12.304600000000', '1.1', RateDate::of('2026-11-19'), RateDate::of('2026-11-19'), new SourceKey($this->manualId === null ? 'ecb' : 'manual'), new SourceLabel($this->label), $this->stale, $this->manualId, []);
            }

            public function rate(CurrencyCode|string $from, CurrencyCode|string $to, ?DateTimeInterface $on = null, ?ConversionOptions $options = null): ResolvedRate
            {
                throw new LogicException('unused');
            }

            public function convertMany(array $amounts, CurrencyCode|string $target, ?DateTimeInterface $on = null, ?ConversionOptions $options = null): array
            {
                return [];
            }
        };
    }

    /**
     * @return array{amount: Money|string, currency: CurrencyCode|string|null, on: ?DateTimeInterface, options: ?ConversionOptions}
     */
    private function call(int $index): array
    {
        return $this->calls[$index] ?? throw new LogicException('no call');
    }

    private function extension(CurrencyConverterInterface $converter, string $locale = 'en', string $onMissing = 'note', string $dateFormat = 'iso', bool $debug = false, bool $request = true): ReferenceMoneyExtension
    {
        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        foreach (['en', 'es', 'ca', 'eu', 'gl', 'fr', 'pt', 'it', 'de', 'nl'] as $code) {
            $translator->addResource('yaml', dirname(__DIR__, 3) . '/src/Resources/translations/NowoCurrencyKitBundle.' . $code . '.yaml', $code, 'NowoCurrencyKitBundle');
        }
        $translator->setLocale($locale);
        $requests = new RequestStack();
        if ($request) {
            $current = new Request();
            $current->setLocale($locale);
            $requests->push($current);
        }

        return new ReferenceMoneyExtension($converter, $translator, $requests, new NullLogger(), $onMissing, $dateFormat, $debug);
    }

    private function render(ReferenceMoneyExtension $extension, string $template): string
    {
        $twig = new Environment(new ArrayLoader(['t' => $template]), ['strict_variables' => true, 'autoescape' => 'html']);
        $twig->addExtension($extension);

        return $twig->render('t', ['cost' => Money::of('11.19', CurrencyCode::of('USD')), 'at' => new DateTimeImmutable('2026-11-19 10:00')]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function locales(): iterable
    {
        yield 'en' => ['en', '≈ €12.30 (reference rate ECB 2026-11-19)'];
        yield 'es' => ['es', "≈ 12,30\u{a0}€ (tipo de referencia ECB 2026-11-19)"];
        yield 'ca' => ['ca', "≈ 12,30\u{a0}€ (tipus de referència ECB 2026-11-19)"];
        yield 'eu' => ['eu', "≈ 12,30\u{a0}€ (erreferentzia-tasa ECB 2026-11-19)"];
        yield 'gl' => ['gl', "≈ 12,30\u{a0}€ (tipo de referencia ECB 2026-11-19)"];
        yield 'fr' => ['fr', "≈ 12,30\u{a0}€ (taux de référence ECB 2026-11-19)"];
        yield 'pt' => ['pt', "≈ €\u{a0}12,30 (taxa de referência ECB 2026-11-19)"];
        yield 'it' => ['it', "≈ 12,30\u{a0}€ (tasso di riferimento ECB 2026-11-19)"];
        yield 'de' => ['de', "≈ 12,30\u{a0}€ (Referenzkurs ECB 2026-11-19)"];
        yield 'nl' => ['nl', "≈ €\u{a0}12,30 (referentiekoers ECB 2026-11-19)"];
    }

    #[DataProvider('locales')]
    public function testGoldenOutputPerLocale(string $locale, string $expected): void
    {
        $out = $this->render($this->extension($this->converter(), $locale), "{{ cost|reference_money('EUR', at) }}");
        self::assertSame($expected, html_entity_decode($out, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public function testEscapingStaleNoteAndParts(): void
    {
        $out = $this->render($this->extension($this->converter('<b>X</b>', true)), "{{ cost|reference_money('EUR') }}");
        self::assertStringContainsString('&lt;b&gt;X&lt;/b&gt;', $out);
        self::assertStringEndsWith('(stale)', $out);
        self::assertSame('Reference rates, for information only; not for payments or accounting.', $this->render($this->extension($this->converter()), '{{ reference_rate_note() }}'));
        $this->calls = [];
        $parts       = $this->extension($this->converter('ECB', false, 'manual-id'))->parts('11.19', 'EUR', '2026-11-19', 'USD', 4);
        self::assertSame(['amount' => '12.3046', 'formatted' => '€12.3046', 'currency' => 'EUR', 'rate' => '1.1', 'rate_date' => '2026-11-19', 'source_label' => 'ECB', 'stale' => false, 'manual' => true], $parts);
        self::assertSame('2026-11-19', $this->call(0)['options']?->onDate?->value, 'a date string is a date-only conversion');
        self::assertSame('USD', $this->call(0)['currency']);
        $this->extension($this->converter())->referenceMoney(1230, 'EUR', null, 'USD');
        self::assertSame('1230', $this->call(1)['amount']);
        self::assertSame('Nov 19, 2026', substr($this->extension($this->converter(), 'en', 'note', 'locale', false, false)->referenceMoney('1', 'EUR', null, 'USD'), -13, 12));
    }

    public function testMissingRateModes(): void
    {
        self::assertSame('(no reference rate)', $this->extension($this->converter('ECB', false, null, true))->referenceMoney('1', 'EUR', null, 'USD'));
        self::assertSame('(sin tipo de referencia)', $this->extension($this->converter('ECB', false, null, true), 'es')->referenceMoney('1', 'EUR', null, 'USD'));
        self::assertSame('', $this->extension($this->converter('ECB', false, null, true), 'en', 'original')->referenceMoney('1', 'EUR', null, 'USD'));
        self::assertNull($this->extension($this->converter('ECB', false, null, true))->parts('1', 'EUR', null, 'USD'));
        self::assertSame('(no reference rate)', $this->extension($this->converter())->referenceMoney(1.5, 'EUR', null, 'USD'), 'a float in production prints the note');
        try {
            $this->extension($this->converter('ECB', false, null, true), 'en', 'exception')->referenceMoney('1', 'EUR', null, 'USD');
            self::fail('no exception');
        } catch (RateNotAvailableException) {
        }
        try {
            $this->extension($this->converter(), 'en', 'note', 'iso', true)->referenceMoney(1.5, 'EUR');
            self::fail('a float was accepted');
        } catch (TypeError) {
        }
        $this->expectException(TypeError::class);
        $this->extension($this->converter(), 'en', 'exception')->referenceMoney([], 'EUR');
    }
}
