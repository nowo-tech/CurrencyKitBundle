<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Application;

use Nowo\CurrencyKitBundle\Domain\Conversion\OnStale;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidValueException;
use Nowo\CurrencyKitBundle\Domain\Money\RoundingMode;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;
use Nowo\CurrencyKitBundle\Domain\Sync\ChangedValuePolicy;

use function in_array;
use function is_string;

/**
 * The bundle's settings (contract section 11), built once from the configuration; configuration only, no request
 * data (worker mode). `outboundEnabled` and `onStale` accept `%env()%` values resolved when the container is built.
 */
final readonly class CurrencyKitSettings
{
    /**
     * Builds the settings.
     */
    public function __construct(
        public SourceKey $activeSource,
        public int $maxLookbackDays = 7,
        public int $maxStalenessDays = 10,
        public OnStale $onStale = OnStale::Fail,
        public RoundingMode $roundingMode = RoundingMode::HalfUp,
        public bool $initialLast90Days = true,
        public ChangedValuePolicy $onChangedValue = ChangedValuePolicy::Replace,
        public int $maxDailyChangePercent = 50,
        public int $lockTtlSeconds = 600,
        public int $backfillMaxDays = 10000,
        public int $backfillChunkDays = 366,
        public bool $outboundEnabled = true,
        public int $retries = 2,
        public int $manualMaxDeviationPercent = 50,
        public bool $manualAllowPast = false,
    ) {
    }

    /**
     * Builds the settings from the processed configuration when the container instantiates them, so `%env()%` values
     * of `outbound.enabled` and `conversion.on_stale` are resolved (fail closed: an unreadable `outbound.enabled`
     * counts as false; an unknown `on_stale` is refused).
     *
     * @param array{source: string, max_lookback_days: int, max_staleness_days: int, on_stale: mixed, rounding_mode: string} $conversion
     * @param array{initial: string, on_changed_value: string, max_daily_change_percent: int, lock_ttl_seconds: int, backfill_max_days: int, backfill_chunk_days: int} $sync
     * @param array{enabled: mixed, retries: int} $outbound
     * @param array{max_deviation_percent: int, allow_past: bool} $manual
     *
     * @throws InvalidValueException
     */
    public static function fromConfig(array $conversion, array $sync, array $outbound, array $manual): self
    {
        $onStale = is_string($conversion['on_stale']) ? OnStale::tryFrom($conversion['on_stale']) : null;
        if ($onStale === null) {
            throw new InvalidValueException('conversion.on_stale must be fail or flag.');
        }
        $enabled = $outbound['enabled'];

        return new self(
            SourceKey::of($conversion['source']),
            $conversion['max_lookback_days'],
            $conversion['max_staleness_days'],
            $onStale,
            RoundingMode::from($conversion['rounding_mode']),
            $sync['initial'] === 'last_90_days',
            ChangedValuePolicy::from($sync['on_changed_value']),
            $sync['max_daily_change_percent'],
            $sync['lock_ttl_seconds'],
            $sync['backfill_max_days'],
            $sync['backfill_chunk_days'],
            in_array($enabled, [true, 1, '1', 'true'], true),
            $outbound['retries'],
            $manual['max_deviation_percent'],
            $manual['allow_past'],
        );
    }
}
