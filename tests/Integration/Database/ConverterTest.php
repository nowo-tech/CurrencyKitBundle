<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Tests\Integration\Database;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\CurrencyKitBundle\Domain\Conversion\ConversionOptions;
use Nowo\CurrencyKitBundle\Domain\Conversion\OnStale;
use Nowo\CurrencyKitBundle\Domain\Currency\CurrencyCode;
use Nowo\CurrencyKitBundle\Domain\Event\ReferenceRatesStale;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidAmountException;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidCurrencyCodeException;
use Nowo\CurrencyKitBundle\Domain\Exception\RateNotAvailableException;
use Nowo\CurrencyKitBundle\Domain\Exception\StaleRateException;
use Nowo\CurrencyKitBundle\Domain\Manual\ManualRateInput;
use Nowo\CurrencyKitBundle\Domain\Money\Money;
use Nowo\CurrencyKitBundle\Domain\Money\RoundingMode;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;
use Nowo\CurrencyKitBundle\Infrastructure\Cache\Psr6ResolvedRateCache;
use Nowo\CurrencyKitBundle\Tests\Support\TestServices;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class ConverterTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->services->synchronizer->sync();
    }

    public function testOneLegEachWayAndCross(): void
    {
        $converter = $this->services->converter;
        $eurUsd    = $converter->convert('100', 'EUR', 'USD');
        self::assertSame('109.92', $eurUsd->amount->amount);
        self::assertSame('109.920000000000', $eurUsd->exactAmount);
        self::assertSame('1.099200000000', $eurUsd->rate);
        self::assertSame('2026-11-19', $eurUsd->rateDate->value);
        self::assertSame('2026-11-19', $eurUsd->requestedDate->value);
        self::assertSame('ecb', $eurUsd->source->value);
        self::assertSame('ECB', $eurUsd->sourceLabel->value);
        self::assertTrue($eurUsd->isReference);
        self::assertFalse($eurUsd->stale);
        self::assertNull($eurUsd->manualRateId);
        self::assertCount(1, $eurUsd->legs);

        $usdEur = $converter->convert(Money::of('109.92', CurrencyCode::of('USD')), null, 'EUR');
        self::assertSame('100', $usdEur->amount->amount);
        self::assertSame('0.909752547307', $usdEur->rate);

        $cross = $converter->convert('10', 'USD', CurrencyCode::of('JPY'));
        self::assertSame('159.970887918486', $cross->rate);
        self::assertSame('1600', $cross->amount->amount, 'JPY has 0 minor units');
        self::assertCount(2, $cross->legs);
    }

    public function testKwdIsNotOfferedByTheEcb(): void
    {
        try {
            $this->services->converter->convert('1', 'EUR', 'KWD');
            self::fail('converted');
        } catch (RateNotAvailableException $e) {
            self::assertSame(RateNotAvailableException::REASON_UNKNOWN_FOR_SOURCE, $e->reason);
        }
    }

    public function testIdentityReadsNothing(): void
    {
        $result = $this->services->converter->convert('12.30', 'GBP', 'GBP');
        self::assertSame('12.3', $result->amount->amount);
        self::assertSame('12.300000000000', $result->exactAmount);
        self::assertSame('1.000000000000', $result->rate);
        self::assertSame('identity', $result->source->value);
        self::assertSame([], $result->legs);
    }

    public function testSubCentCostCapKeepsTheExactAmount(): void
    {
        $result = $this->services->converter->convert('0.000003', 'USD', 'EUR', null, new ConversionOptions(scale: 12));
        self::assertSame('0.000002729258', $result->exactAmount);
        self::assertSame('0.000002729258', $result->amount->amount);
        self::assertSame('0', $this->services->converter->convert('0.000003', 'USD', 'EUR')->amount->amount, 'rounded to cents');
    }

    public function testRoundingOption(): void
    {
        $up   = $this->services->converter->convert('0.005', 'EUR', 'EUR', null, new ConversionOptions(roundingMode: RoundingMode::HalfUp));
        $even = $this->services->converter->convert('0.005', 'EUR', 'EUR', null, new ConversionOptions(roundingMode: RoundingMode::HalfEven));
        self::assertSame('0.01', $up->amount->amount);
        self::assertSame('0', $even->amount->amount);
    }

    public function testWeekendUsesTheLastPublication(): void
    {
        $result = $this->services->converter->convert('1', 'EUR', 'USD', new DateTimeImmutable('2026-11-15 12:00', new DateTimeZone('Europe/Berlin')));
        self::assertSame('2026-11-13', $result->rateDate->value);
        self::assertSame('2026-11-15', $result->requestedDate->value);
        self::assertSame('1.1052', rtrim(rtrim($result->rate, '0'), '.'));
    }

    public function testDateOnlyOptionAndTimeZoneEdge(): void
    {
        $byDate = $this->services->converter->rate('EUR', 'USD', null, new ConversionOptions(onDate: RateDate::of('2026-11-18')));
        self::assertSame('2026-11-18', $byDate->rateDate->value);
        // 23:30 in New York on the 18th is the 19th in Berlin.
        $edge = $this->services->converter->rate('EUR', 'USD', new DateTimeImmutable('2026-11-18 23:30', new DateTimeZone('America/New_York')));
        self::assertSame('2026-11-19', $edge->requestedDate->value);
    }

    public function testFutureAndBeforeHistory(): void
    {
        foreach (['2026-11-20' => RateNotAvailableException::REASON_FUTURE_DATE, '2020-01-02' => RateNotAvailableException::REASON_BEFORE_HISTORY] as $date => $reason) {
            try {
                $this->services->converter->convert('1', 'EUR', 'USD', null, new ConversionOptions(onDate: RateDate::of($date)));
                self::fail('converted ' . $date);
            } catch (RateNotAvailableException $e) {
                self::assertSame($reason, $e->reason);
            }
        }
    }

    public function testNoPublicationInLookbackAndSuspendedCurrency(): void
    {
        $this->seed('2026-06-01', ['USD' => '1.1', 'RUB' => '90']);
        $this->seed('2026-07-01', ['USD' => '1.2']);
        $services = $this->services;
        try {
            $services->converter->convert('1', 'EUR', 'USD', null, new ConversionOptions(onDate: RateDate::of('2026-06-20')));
            self::fail('converted');
        } catch (RateNotAvailableException $e) {
            self::assertSame(RateNotAvailableException::REASON_NO_PUBLICATION_IN_LOOKBACK, $e->reason);
        }
        try {
            $services->converter->convert('1', 'EUR', 'RUB', null, new ConversionOptions(onDate: RateDate::of('2026-07-02')));
            self::fail('a suspended currency converted at an old rate');
        } catch (RateNotAvailableException $e) {
            self::assertSame(RateNotAvailableException::REASON_UNKNOWN_FOR_SOURCE, $e->reason);
        }
        self::assertSame('2026-06-01', $services->converter->rate('EUR', 'RUB', null, new ConversionOptions(onDate: RateDate::of('2026-06-03')))->rateDate->value);
    }

    public function testMissingOnOneDayLooksBackInTheWindow(): void
    {
        $this->seed('2026-11-20', ['USD' => '1.2']);
        $services = new TestServices($this->connection, new MockClock('2026-11-20 18:00:00', 'Europe/Berlin'), $this->http->client);
        $result   = $services->converter->rate('EUR', 'GBP');
        self::assertSame('2026-11-19', $result->rateDate->value);
    }

    public function testStaleFailFlagAndOverride(): void
    {
        $late = new TestServices($this->connection, new MockClock('2026-12-10 12:00:00', 'Europe/Berlin'), $this->http->client);
        try {
            $late->converter->convert('1', 'EUR', 'USD');
            self::fail('stale converted');
        } catch (StaleRateException $e) {
            self::assertSame('2026-11-19', $e->latestDate);
            self::assertSame(21, $e->ageDays);
        }
        $flagged = $late->converter->convert('1', 'EUR', 'USD', null, new ConversionOptions(allowStale: true));
        self::assertTrue($flagged->stale);
        self::assertSame('2026-11-19', $flagged->rateDate->value);
        $late->converter->convert('2', 'EUR', 'USD', null, new ConversionOptions(allowStale: true));
        self::assertCount(1, $late->events->of(ReferenceRatesStale::class), 'once per request');

        $flag = new TestServices($this->connection, new MockClock('2026-12-10 12:00:00', 'Europe/Berlin'), $this->http->client, ['onStale' => OnStale::Flag]);
        self::assertTrue($flag->converter->convert('1', 'EUR', 'USD')->stale);
        // An explicit past date that resolves in the lookback is never stale.
        self::assertFalse($flag->converter->convert('1', 'EUR', 'USD', null, new ConversionOptions(onDate: RateDate::of('2026-11-19')))->stale);
        $this->expectException(StaleRateException::class);
        $flag->converter->convert('1', 'EUR', 'USD', null, new ConversionOptions(allowStale: false));
    }

    public function testOutageDaysSevenToElevenInBothModes(): void
    {
        foreach ([7 => false, 8 => false, 9 => false, 10 => false, 11 => true] as $days => $stale) {
            $now = RateDate::of('2026-11-19')->plusDays($days)->value . ' 12:00:00';
            foreach ([OnStale::Fail, OnStale::Flag] as $mode) {
                $services = new TestServices($this->connection, new MockClock($now, 'Europe/Berlin'), $this->http->client, ['onStale' => $mode]);
                if ($stale && $mode === OnStale::Fail) {
                    try {
                        $services->converter->convert('1', 'EUR', 'USD');
                        self::fail('day ' . $days . ' converted while stale');
                    } catch (StaleRateException $e) {
                        self::assertSame($days, $e->ageDays);
                    }

                    continue;
                }
                $result = $services->converter->convert('1', 'EUR', 'USD');
                self::assertSame('2026-11-19', $result->rateDate->value, 'day ' . $days);
                self::assertSame($stale, $result->stale, 'day ' . $days . ' ' . $mode->value);
            }
        }
    }

    public function testCrossRatesAreComputedInOneStep(): void
    {
        $idr = $this->services->converter->convert('1000000', 'IDR', 'EUR', null, new ConversionOptions(scale: 12));
        self::assertSame('49.817915518779', $idr->exactAmount, 'amount ÷ 20073.1 at scale 12, not amount × (1 ÷ 20073.1)');
        self::assertSame('0.000049817916', $idr->rate);
        $gbp = $this->services->converter->convert('1000', 'USD', 'GBP', null, new ConversionOptions(scale: 12));
        self::assertSame('760.380276564774', $gbp->exactAmount);
        self::assertSame('0.760380276565', $gbp->rate);
    }

    public function testStaleWithNothingStoredIsBeforeHistory(): void
    {
        $this->connection->executeStatement('DELETE FROM nowo_currency_rate');
        $this->connection->executeStatement('DELETE FROM nowo_currency_rate_publication');
        $this->expectException(RateNotAvailableException::class);
        $this->services()->converter->convert('1', 'EUR', 'USD');
    }

    public function testManualRateWinsOnOneLeg(): void
    {
        $services = $this->services(['manualAllowPast' => true]);
        $manual   = $services->manual->create(new ManualRateInput(CurrencyCode::of('USD'), '1.2', RateDate::of('2026-11-19'), null, 'Board decision'));
        $result   = $services->converter->convert('10', 'GBP', 'USD');
        self::assertSame('manual', $result->source->value);
        self::assertSame($manual->id, $result->manualRateId);
        self::assertSame('ecb', $result->legs[0]->source->value);
        self::assertSame('manual', $result->legs[1]->source->value);
        $plain = $services->converter->convert('10', 'GBP', 'USD', null, new ConversionOptions(onDate: RateDate::of('2026-11-18')));
        self::assertNull($plain->manualRateId);
        $first = $services->converter->convert('10', 'USD', 'GBP');
        self::assertSame($manual->id, $first->manualRateId);
    }

    public function testManualOnlySource(): void
    {
        $services = $this->services(['activeSource' => new SourceKey('manual'), 'manualAllowPast' => true]);
        try {
            $services->converter->convert('1', 'EUR', 'USD');
            self::fail('converted without a manual rate');
        } catch (RateNotAvailableException $e) {
            self::assertSame(RateNotAvailableException::REASON_UNKNOWN_FOR_SOURCE, $e->reason);
        }
        $services->manual->create(new ManualRateInput(CurrencyCode::of('USD'), '1.25', RateDate::of('2026-11-01'), null, 'Air-gapped install'));
        self::assertSame('125', $services->converter->convert('100', 'EUR', 'USD')->amount->amount);
        self::assertSame('Manual', $services->converter->convert('100', 'EUR', 'USD')->sourceLabel->value);
    }

    public function testConvertManyAndInputErrors(): void
    {
        $usd     = CurrencyCode::of('USD');
        $results = $this->services->converter->convertMany([Money::of('1', $usd), Money::of('2', $usd), Money::of('3', CurrencyCode::of('GBP'))], 'EUR');
        self::assertCount(3, $results);
        self::assertSame($results[0]->rate, $results[1]->rate);
        try {
            $this->services->converter->convert(Money::of('1', $usd), 'USD', 'EUR');
            self::fail('accepted');
        } catch (InvalidAmountException) {
        }
        try {
            $this->services->converter->convert('1', null, 'EUR');
            self::fail('accepted');
        } catch (InvalidAmountException) {
        }
        $this->expectException(InvalidCurrencyCodeException::class);
        $this->services->converter->convert('1', 'usd', 'EUR');
    }

    public function testSharedCacheServesOtherWorkersAndIsInvalidated(): void
    {
        $cache  = new Psr6ResolvedRateCache(new ArrayAdapter(), 300);
        $first  = new TestServices($this->connection, new MockClock('2026-11-19 17:00:00', 'Europe/Berlin'), $this->http->client, ['manualAllowPast' => true], false, $cache);
        $second = new TestServices($this->connection, new MockClock('2026-11-19 17:00:00', 'Europe/Berlin'), $this->http->client, [], false, $cache);
        self::assertSame('ecb', $first->converter->convert('1', 'EUR', 'USD')->source->value);
        $this->connection->executeStatement('DELETE FROM nowo_currency_rate');
        self::assertSame('ecb', $second->converter->convert('1', 'EUR', 'USD')->source->value, 'served from the shared cache');
        $first->manual->create(new ManualRateInput(CurrencyCode::of('USD'), '1.1', RateDate::of('2026-11-19'), null, 'Cache test'));
        $second->resolver->forget();
        self::assertSame('manual', $second->converter->convert('1', 'EUR', 'USD')->source->value, 'version bumped: every worker sees the manual rate');
        $third = new TestServices($this->connection, new MockClock('2026-11-19 17:00:00', 'Europe/Berlin'), $this->http->client, [], false, $cache);
        try {
            $third->converter->convert('1', 'EUR', 'GBP');
            self::fail('converted without rows');
        } catch (RateNotAvailableException) {
        }
        $fourth = new TestServices($this->connection, new MockClock('2026-11-19 17:00:00', 'Europe/Berlin'), $this->http->client, [], false, $cache);
        $this->expectException(RateNotAvailableException::class);
        $fourth->converter->convert('1', 'EUR', 'GBP');
    }

    public function testResolverToday(): void
    {
        self::assertSame('2026-11-19', $this->services->resolver->today()->value);
        self::assertSame('ecb', $this->services->resolver->activeSource()->key()->value);
    }
}
