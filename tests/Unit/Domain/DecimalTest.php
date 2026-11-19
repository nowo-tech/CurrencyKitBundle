<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Tests\Unit\Domain;

use Nowo\CurrencyKitBundle\Domain\Exception\InvalidAmountException;
use Nowo\CurrencyKitBundle\Domain\Money\Decimal;
use Nowo\CurrencyKitBundle\Domain\Money\RoundingMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecimalTest extends TestCase
{
    /**
     * Golden table at ties and near ties (scale 0).
     *
     * @return iterable<string, array{RoundingMode, array<string, string>}>
     */
    public static function modes(): iterable
    {
        yield 'half up' => [RoundingMode::HalfUp, ['2.5' => '3', '-2.5' => '-3', '1.5' => '2', '2.4' => '2', '-2.6' => '-3']];
        yield 'half even' => [RoundingMode::HalfEven, ['2.5' => '2', '-2.5' => '-2', '1.5' => '2', '2.4' => '2', '-2.6' => '-3']];
        yield 'half down' => [RoundingMode::HalfDown, ['2.5' => '2', '-2.5' => '-2', '1.5' => '1', '2.6' => '3', '-2.6' => '-3']];
        yield 'down' => [RoundingMode::Down, ['2.5' => '2', '-2.5' => '-2', '2.9' => '2', '-2.9' => '-2']];
        yield 'up' => [RoundingMode::Up, ['2.1' => '3', '-2.1' => '-3', '2.0' => '2']];
        yield 'ceiling' => [RoundingMode::Ceiling, ['2.1' => '3', '-2.1' => '-2']];
        yield 'floor' => [RoundingMode::Floor, ['2.1' => '2', '-2.1' => '-3']];
    }

    /**
     * @param array<string, string> $cases
     */
    #[DataProvider('modes')]
    public function testRoundingModes(RoundingMode $mode, array $cases): void
    {
        foreach ($cases as $input => $expected) {
            self::assertSame($expected, Decimal::round((string) $input, 0, $mode), $mode->value . ' ' . $input);
        }
    }

    public function testArithmetic(): void
    {
        self::assertSame('0.000002729133', Decimal::multiply('0.000003', '0.909711')); // sub-cent cost cap
        self::assertSame('0.909752547307', Decimal::divide('1', '1.0992'));
        self::assertSame(0, Decimal::compare('1.0812', '1.08120'));
        self::assertSame(-1, Decimal::compare('1.08', '1.0812'));
        self::assertTrue(Decimal::movedMoreThan('1', '1.6', 50));
        self::assertFalse(Decimal::movedMoreThan('1', '1.5', 50));
        self::assertTrue(Decimal::movedMoreThan('1', '0.4', 50));
        self::assertSame('1234', Decimal::shiftRight('12.34', 2, RoundingMode::HalfUp));
        self::assertSame('12.34', Decimal::shiftLeft('1234', 2));
        self::assertTrue(Decimal::isPlain('-1.5'));
        self::assertFalse(Decimal::isPlain('1E-5'));
        self::assertSame('5', Decimal::canonical('5'));
    }

    public function testDivisionByZero(): void
    {
        $this->expectException(InvalidAmountException::class);
        Decimal::divide('1', '0');
    }
}
