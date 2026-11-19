<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Tests\Unit\Domain;

use DateTimeImmutable;
use Nowo\CurrencyKitBundle\Domain\Currency\CurrencyCode;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidValueException;
use Nowo\CurrencyKitBundle\Domain\Exception\ManualRateRefusedException;
use Nowo\CurrencyKitBundle\Domain\Manual\ManualRateInput;
use Nowo\CurrencyKitBundle\Domain\Manual\ManualRateStatus;
use Nowo\CurrencyKitBundle\Domain\Manual\ManualRateView;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use PHPUnit\Framework\TestCase;

use function str_repeat;

final class ManualRateTest extends TestCase
{
    public function testInputIsNormalised(): void
    {
        $input = new ManualRateInput(CurrencyCode::of('USD'), '1.10000', RateDate::of('2026-11-20'), null, '  Board decision  ', true);
        self::assertSame('1.1', $input->rate);
        self::assertSame('Board decision', $input->reason);
        self::assertTrue($input->force);
    }

    public function testInputIsRefused(): void
    {
        $usd = CurrencyCode::of('USD');
        $day = RateDate::of('2026-11-20');
        foreach ([
            static fn (): ManualRateInput => new ManualRateInput(CurrencyCode::eur(), '1', $day, null, 'reason'),
            static fn (): ManualRateInput => new ManualRateInput($usd, '1', $day, $day->plusDays(-1), 'reason'),
            static fn (): ManualRateInput => new ManualRateInput($usd, '1', $day, null, 'four'),
            static fn (): ManualRateInput => new ManualRateInput($usd, '1', $day, null, str_repeat('a', 501)),
            static fn (): ManualRateInput => new ManualRateInput($usd, '1', $day, null, "bad\x01reason"),
            static fn (): ManualRateInput => new ManualRateInput($usd, '1', $day, null, "bad \xff reason"),
        ] as $make) {
            try {
                $make();
                self::fail('accepted');
            } catch (ManualRateRefusedException $e) {
                self::assertSame('CURRENCY_011', $e->errorCode());
            }
        }
        $this->expectException(InvalidValueException::class);
        new ManualRateInput($usd, '0', $day, null, 'reason');
    }

    public function testCoverageAndOverlap(): void
    {
        $view = $this->view('2026-11-10', '2026-11-20');
        self::assertTrue($view->covers(RateDate::of('2026-11-10')));
        self::assertTrue($view->covers(RateDate::of('2026-11-20')));
        self::assertFalse($view->covers(RateDate::of('2026-11-21')));
        self::assertFalse($view->covers(RateDate::of('2026-11-09')));
        self::assertTrue($this->view('2026-11-10', null)->covers(RateDate::of('2030-01-01')));
        self::assertTrue($view->overlaps(RateDate::of('2026-11-20'), null));
        self::assertTrue($view->overlaps(RateDate::of('2026-11-01'), RateDate::of('2026-11-10')));
        self::assertFalse($view->overlaps(RateDate::of('2026-11-21'), null));
        self::assertFalse($view->overlaps(RateDate::of('2026-11-01'), RateDate::of('2026-11-09')));
        self::assertTrue($this->view('2026-11-10', null)->overlaps(RateDate::of('2030-01-01'), RateDate::of('2030-01-02')));
        $retracted = $this->view('2026-11-10', '2026-11-09');
        self::assertFalse($retracted->covers(RateDate::of('2026-11-10')));
        self::assertFalse($retracted->overlaps(RateDate::of('2026-11-01'), null));
        self::assertFalse($view->overlaps(RateDate::of('2026-11-15'), RateDate::of('2026-11-14')));
    }

    private function view(string $from, ?string $until): ManualRateView
    {
        $now = new DateTimeImmutable('2026-11-19 10:00:00');

        return new ManualRateView('id', CurrencyCode::of('USD'), '1.1', RateDate::of($from), $until === null ? null : RateDate::of($until), 'reason', ManualRateStatus::Active, null, $now, null, $now, 1);
    }
}
