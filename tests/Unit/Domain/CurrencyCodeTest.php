<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Tests\Unit\Domain;

use Nowo\CurrencyKitBundle\Domain\Currency\CurrencyCode;
use Nowo\CurrencyKitBundle\Domain\Currency\Iso4217Table;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidCurrencyCodeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CurrencyCodeTest extends TestCase
{
    public function testIsoExponentsAndState(): void
    {
        self::assertSame(0, CurrencyCode::of('JPY')->minorUnits());
        self::assertSame(2, CurrencyCode::of('EUR')->minorUnits());
        self::assertSame(3, CurrencyCode::of('KWD')->minorUnits());
        self::assertTrue(CurrencyCode::of('USD')->isActive());
        self::assertFalse(CurrencyCode::of('CYP')->isActive());
        self::assertTrue(CurrencyCode::eur()->isEur());
        self::assertFalse(CurrencyCode::of('USD')->isEur());
        self::assertSame('USD', (string) CurrencyCode::of('USD'));
        self::assertTrue(CurrencyCode::of('USD')->equals(new CurrencyCode('USD', 2)));
        self::assertTrue(CurrencyCode::of('XOF')->isActive(), 'currencies in the X range stay');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'lower case' => ['usd'];
        yield 'padded' => [' USD'];
        yield 'trailing newline' => ["USD\n"];
        yield 'unknown' => ['ABC'];
        yield 'gold' => ['XAU'];
        yield 'test code' => ['XTS'];
        yield 'no currency' => ['XXX'];
        yield 'empty' => [''];
    }

    #[DataProvider('refused')]
    public function testRefusesCodes(string $code): void
    {
        $this->expectException(InvalidCurrencyCodeException::class);
        CurrencyCode::of($code);
    }

    public function testConstructorRefusesBadMinorUnitsAndXCodes(): void
    {
        foreach ([['ABC', -1], ['ABC', 13], ['XDR', 2], ['abc', 2]] as [$code, $units]) {
            try {
                new CurrencyCode($code, $units);
                self::fail('accepted ' . $code);
            } catch (InvalidCurrencyCodeException $e) {
                self::assertSame('CURRENCY_001', $e->errorCode());
            }
        }
        self::assertSame(4, (new CurrencyCode('ZZZ', 4))->minorUnits(), 'a host catalogue may add a code');
    }

    public function testTableIsPinnedAndHasNoRefusedCodes(): void
    {
        // The generation is pinned: a table update changes these dates and is noted in the CHANGELOG.
        self::assertSame(['2026-09-17', '2026-01-01'], [Iso4217Table::CURRENT_LIST_PUBLISHED, Iso4217Table::HISTORIC_LIST_PUBLISHED]); // @phpstan-ignore staticMethod.alreadyNarrowedType
        foreach (CurrencyCode::REFUSED_X_CODES as $code) {
            self::assertArrayNotHasKey($code, Iso4217Table::CURRENCIES);
        }
        foreach (['EUR', 'USD', 'GBP', 'JPY', 'CHF', 'CYP', 'MTL', 'EEK', 'LTL', 'LVL', 'SIT', 'SKK', 'ROL', 'TRL', 'HRK', 'RUB'] as $code) {
            self::assertArrayHasKey($code, Iso4217Table::CURRENCIES, $code);
        }
    }
}
