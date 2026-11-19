<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Tests\Unit\Domain;

use Nowo\CurrencyKitBundle\Domain\Currency\CurrencyCode;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidAmountException;
use Nowo\CurrencyKitBundle\Domain\Money\Money;
use Nowo\CurrencyKitBundle\Domain\Money\RoundingMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function canonical(): iterable
    {
        yield 'trailing zeros' => ['1.50', '1.5'];
        yield 'integer fraction zeros' => ['12.000', '12'];
        yield 'negative zero' => ['-0', '0'];
        yield 'negative zero fraction' => ['-0.000', '0'];
        yield 'sub-cent price' => ['0.000003', '0.000003'];
        yield 'max digits' => ['999999999999999999.999999999999', '999999999999999999.999999999999'];
        yield 'negative' => ['-12.30', '-12.3'];
    }

    #[DataProvider('canonical')]
    public function testCanonicalForm(string $input, string $expected): void
    {
        self::assertSame($expected, Money::of($input, CurrencyCode::of('EUR'))->amount);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        foreach (['', '+1', '1e3', '1E3', '01', '1.', '.5', '1,5', '1 000', '1.0000000000001', '1000000000000000000', ' 1', '1 ', "1\n", 'abc', '--1', '0x10', 'NaN', 'INF'] as $value) {
            yield $value => [$value];
        }
    }

    #[DataProvider('refused')]
    public function testRefusesBadGrammar(string $input): void
    {
        $this->expectException(InvalidAmountException::class);
        Money::of($input, CurrencyCode::of('EUR'));
    }

    public function testMinorUnits(): void
    {
        self::assertSame('12.34', Money::ofMinor(1234, CurrencyCode::of('EUR'))->amount);
        self::assertSame('1234', Money::ofMinor(1234, CurrencyCode::of('JPY'))->amount);
        self::assertSame('1.234', Money::ofMinor(1234, CurrencyCode::of('KWD'))->amount);
        self::assertSame('-0.05', Money::ofMinor(-5, CurrencyCode::of('EUR'))->amount);
        self::assertSame(1235, Money::of('12.345', CurrencyCode::of('EUR'))->toMinor());
        self::assertSame(1234, Money::of('12.345', CurrencyCode::of('EUR'))->toMinor(RoundingMode::Down));
        self::assertSame(12345, Money::of('12.345', CurrencyCode::of('KWD'))->toMinor());
        self::assertSame(13, Money::of('12.5', CurrencyCode::of('JPY'))->toMinor());
        self::assertSame(-1235, Money::of('-12.345', CurrencyCode::of('EUR'))->toMinor());
        self::assertSame(922337203685477580, Money::of('9223372036854775.80', CurrencyCode::of('EUR'))->toMinor());
    }

    public function testMinorUnitsOverflow(): void
    {
        $this->expectException(InvalidAmountException::class);
        Money::of('999999999999999999', CurrencyCode::of('KWD'))->toMinor();
    }

    public function testMinorUnitsOverflowAtNineteenDigits(): void
    {
        $this->expectException(InvalidAmountException::class);
        Money::of('92233720368547758.08', CurrencyCode::of('EUR'))->toMinor();
    }

    public function testEqualityAndSign(): void
    {
        $eur = CurrencyCode::of('EUR');
        self::assertTrue(Money::of('1.50', $eur)->equals(Money::of('1.5', $eur)));
        self::assertFalse(Money::of('1.5', $eur)->equals(Money::of('1.5', CurrencyCode::of('USD'))));
        self::assertTrue(Money::of('-1', $eur)->isNegative());
        self::assertFalse(Money::of('1', $eur)->isNegative());
    }
}
