<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Tests\Unit\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\CurrencyKitBundle\Domain\Conversion\ConversionOptions;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidValueException;
use Nowo\CurrencyKitBundle\Domain\Manual\ActorRef;
use Nowo\CurrencyKitBundle\Domain\Rate\FetchFeed;
use Nowo\CurrencyKitBundle\Domain\Rate\FetchLimits;
use Nowo\CurrencyKitBundle\Domain\Rate\FetchRequest;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use Nowo\CurrencyKitBundle\Domain\Rate\RateValue;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceLabel;
use PHPUnit\Framework\TestCase;

use function str_repeat;

use const DATE_ATOM;

final class ValueObjectsTest extends TestCase
{
    public function testRateDate(): void
    {
        $date = RateDate::of('2026-11-19');
        self::assertSame('2026-11-19', (string) $date);
        self::assertSame('2026-11-20', $date->plusDays(1)->value);
        self::assertSame('2026-11-12', $date->plusDays(-7)->value);
        self::assertSame(4, $date->dayOfWeek());
        self::assertSame(3, RateDate::of('2026-11-16')->daysUntil($date));
        self::assertSame(-3, $date->daysUntil(RateDate::of('2026-11-16')));
        self::assertTrue($date->isAfter(RateDate::of('2026-11-18')));
        self::assertTrue($date->isBefore(RateDate::of('2026-11-20')));
        self::assertTrue($date->equals(RateDate::of('2026-11-19')));
        self::assertSame(0, $date->compare(RateDate::of('2026-11-19')));
        self::assertSame('2026-03-29', RateDate::of('2026-03-28')->plusDays(1)->value, 'DST day');
        // Late evening in New York is the next day in Berlin (QC-11).
        $instant = new DateTimeImmutable('2026-11-19 23:30', new DateTimeZone('America/New_York'));
        self::assertSame('2026-11-20', RateDate::fromInstant($instant, new DateTimeZone('Europe/Berlin'))->value);
        self::assertSame('2026-11-19T00:00:00+00:00', $date->toDateTime()->format(DATE_ATOM));
        foreach (['2026-02-30', '2026-1-01', '26-01-01', '1899-12-31', '3000-01-01', '2026-11-19 ', ''] as $bad) {
            try {
                RateDate::of($bad);
                self::fail('accepted ' . $bad);
            } catch (InvalidValueException) {
            }
        }
    }

    public function testSourceKeyLabelActor(): void
    {
        self::assertSame('ecb', (string) SourceKey::of('ecb'));
        self::assertTrue(SourceKey::of('ecb')->equals(new SourceKey('ecb')));
        self::assertSame('ECB', (string) new SourceLabel('ECB'));
        self::assertSame('admin', (string) new ActorRef('admin'));
        foreach ([static fn (): SourceKey => SourceKey::of('ECB'), static fn (): SourceKey => SourceKey::of('1ecb'), static fn (): SourceKey => SourceKey::of(str_repeat('a', 33)),
            static fn (): SourceLabel => new SourceLabel(''), static fn (): SourceLabel    => new SourceLabel(str_repeat('a', 41)), static fn (): SourceLabel => new SourceLabel("E\u{200B}CB"), static fn (): SourceLabel => new SourceLabel("\xff"),
            static fn (): ActorRef => new ActorRef(''), static fn (): ActorRef             => new ActorRef(str_repeat('a', 129)), static fn (): ActorRef => new ActorRef("a\nb"), static fn (): ActorRef => new ActorRef("\xff")] as $make) {
            try {
                $make();
                self::fail('accepted');
            } catch (InvalidValueException $e) {
                self::assertSame('CURRENCY_012', $e->errorCode());
            }
        }
    }

    public function testRateValue(): void
    {
        self::assertSame('1.0992', RateValue::canonical('1.09920'));
        self::assertSame('20073.1', RateValue::canonical('20073.100000000000'));
        self::assertTrue(RateValue::isValid('0.000000000001'));
        foreach (['0', '0.000', '-1', '1e3', '1.0000000000001', '1000000000000', '', 'abc'] as $bad) {
            self::assertFalse(RateValue::isValid($bad), $bad);
        }
    }

    public function testFetchAndOptions(): void
    {
        $range = new FetchRequest(FetchFeed::Range, RateDate::of('2026-11-10'), RateDate::of('2026-11-14'), 3);
        self::assertSame(3, $range->attempts);
        self::assertSame('json', (new FetchLimits(10, 1.0, 1.0, 'json'))->format);
        self::assertSame(2, (new ConversionOptions(2))->scale);
        foreach ([
            static fn (): FetchRequest => new FetchRequest(FetchFeed::Range),
            static fn (): FetchRequest => new FetchRequest(FetchFeed::Range, RateDate::of('2026-11-14'), RateDate::of('2026-11-10')),
            static fn (): FetchRequest => new FetchRequest(FetchFeed::Daily, null, null, 0),
            static fn (): FetchLimits => new FetchLimits(0, 1.0, 1.0),
            static fn (): FetchLimits => new FetchLimits(1, 0.0, 1.0),
            static fn (): FetchLimits => new FetchLimits(1, 1.0, 0.0),
            static fn (): ConversionOptions => new ConversionOptions(13),
            static fn (): ConversionOptions => new ConversionOptions(-1),
        ] as $make) {
            try {
                $make();
                self::fail('accepted');
            } catch (InvalidValueException) {
            }
        }
    }
}
