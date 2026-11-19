<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Tests\Unit\Application;

use Nowo\CurrencyKitBundle\Application\BundledCurrencyCatalogue;
use Nowo\CurrencyKitBundle\Application\CurrencyKitSettings;
use Nowo\CurrencyKitBundle\Application\NullResolvedRateCache;
use Nowo\CurrencyKitBundle\Application\RateMemo;
use Nowo\CurrencyKitBundle\Application\RateSourceRegistry;
use Nowo\CurrencyKitBundle\Application\TargetCalendar;
use Nowo\CurrencyKitBundle\Domain\Conversion\OnStale;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidValueException;
use Nowo\CurrencyKitBundle\Domain\Money\RoundingMode;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceLabel;
use Nowo\CurrencyKitBundle\Domain\Sync\ChangedValuePolicy;
use Nowo\CurrencyKitBundle\Infrastructure\Source\Manual\ManualRateSource;
use PHPUnit\Framework\TestCase;

use function count;

final class ApplicationUnitTest extends TestCase
{
    public function testTargetCalendar(): void
    {
        $calendar = new TargetCalendar();
        foreach (['2026-01-01', '2026-04-03', '2026-04-06', '2026-05-01', '2026-12-25', '2026-12-26', '2026-11-21', '2026-11-22', '2027-03-26', '2027-03-29'] as $closed) {
            self::assertFalse($calendar->isBusinessDay(RateDate::of($closed)), $closed);
        }
        foreach (['2026-11-19', '2026-04-02', '2026-04-07', '2026-12-24', '2026-12-28'] as $open) {
            self::assertTrue($calendar->isBusinessDay(RateDate::of($open)), $open);
        }
        self::assertSame('2026-04-02', $calendar->previousBusinessDay(RateDate::of('2026-04-07'))->value, 'Thursday before Easter');
        self::assertSame('2026-11-20', $calendar->previousBusinessDay(RateDate::of('2026-11-23'))->value);
    }

    public function testMemo(): void
    {
        $memo = new RateMemo();
        self::assertFalse($memo->has('a'));
        $memo->set('a', null);
        self::assertTrue($memo->has('a'));
        self::assertNull($memo->get('a'));
        $memo->set('b', ['rate' => '1']);
        self::assertSame(['rate' => '1'], $memo->get('b'));
        self::assertTrue($memo->once('x'));
        self::assertFalse($memo->once('x'));
        for ($i = 0; $i < 3000; ++$i) {
            $memo->set('k' . $i, null);
        }
        self::assertFalse($memo->has('a'), 'bounded');
        self::assertTrue($memo->has('k2999'));
        $memo->reset();
        self::assertFalse($memo->has('k2999'));
        self::assertTrue($memo->once('x'));
    }

    public function testRegistryCatalogueCacheAndSettings(): void
    {
        $manual   = new ManualRateSource(new SourceLabel('Manual'));
        $registry = new RateSourceRegistry([$manual]);
        self::assertTrue($registry->has(SourceKey::of('manual')));
        self::assertFalse($registry->has(SourceKey::of('ecb')));
        self::assertSame(['manual'], $registry->keys());
        try {
            $registry->get(SourceKey::of('ecb'));
            self::fail('found');
        } catch (InvalidValueException) {
        }
        try {
            new RateSourceRegistry([$manual, $manual]);
            self::fail('duplicate key accepted');
        } catch (InvalidValueException) {
        }

        $catalogue = new BundledCurrencyCatalogue();
        self::assertTrue($catalogue->exists('USD'));
        self::assertFalse($catalogue->exists('usd'));
        self::assertSame('USD', $catalogue->get('USD')->code);
        $active = $catalogue->all();
        $all    = $catalogue->all(false);
        self::assertGreaterThan(150, count($active));
        self::assertGreaterThan(count($active), count($all));

        $cache = new NullResolvedRateCache();
        $cache->set('k', ['a' => 'b']);
        $cache->invalidate();
        self::assertNull($cache->get('k'));
        self::assertTrue($cache->claimOnce('k', 1));

        $settings = CurrencyKitSettings::fromConfig(
            ['source' => 'ecb', 'max_lookback_days' => 7, 'max_staleness_days' => 10, 'on_stale' => 'flag', 'rounding_mode' => 'half_even'],
            ['initial' => 'none', 'on_changed_value' => 'keep', 'max_daily_change_percent' => 50, 'lock_ttl_seconds' => 600, 'backfill_max_days' => 100, 'backfill_chunk_days' => 30],
            ['enabled' => 'true', 'retries' => 1],
            ['max_deviation_percent' => 20, 'allow_past' => true],
        );
        self::assertSame(OnStale::Flag, $settings->onStale);
        self::assertSame(RoundingMode::HalfEven, $settings->roundingMode);
        self::assertFalse($settings->initialLast90Days);
        self::assertSame(ChangedValuePolicy::Keep, $settings->onChangedValue);
        self::assertTrue($settings->outboundEnabled);
        foreach ([true, 1, '1', 'true'] as $on) {
            self::assertTrue(CurrencyKitSettings::fromConfig(['source' => 'ecb', 'max_lookback_days' => 7, 'max_staleness_days' => 10, 'on_stale' => 'fail', 'rounding_mode' => 'half_up'], ['initial' => 'last_90_days', 'on_changed_value' => 'replace', 'max_daily_change_percent' => 50, 'lock_ttl_seconds' => 600, 'backfill_max_days' => 100, 'backfill_chunk_days' => 30], ['enabled' => $on, 'retries' => 1], ['max_deviation_percent' => 20, 'allow_past' => false])->outboundEnabled);
        }
        self::assertFalse(CurrencyKitSettings::fromConfig(['source' => 'ecb', 'max_lookback_days' => 7, 'max_staleness_days' => 10, 'on_stale' => 'fail', 'rounding_mode' => 'half_up'], ['initial' => 'last_90_days', 'on_changed_value' => 'replace', 'max_daily_change_percent' => 50, 'lock_ttl_seconds' => 600, 'backfill_max_days' => 100, 'backfill_chunk_days' => 30], ['enabled' => 'garbage', 'retries' => 1], ['max_deviation_percent' => 20, 'allow_past' => false])->outboundEnabled, 'fail closed');
        $this->expectException(InvalidValueException::class);
        CurrencyKitSettings::fromConfig(['source' => 'ecb', 'max_lookback_days' => 7, 'max_staleness_days' => 10, 'on_stale' => 'maybe', 'rounding_mode' => 'half_up'], ['initial' => 'none', 'on_changed_value' => 'keep', 'max_daily_change_percent' => 50, 'lock_ttl_seconds' => 600, 'backfill_max_days' => 100, 'backfill_chunk_days' => 30], ['enabled' => true, 'retries' => 1], ['max_deviation_percent' => 20, 'allow_past' => true]);
    }
}
