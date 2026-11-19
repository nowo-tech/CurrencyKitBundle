<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Application;

use DateTimeInterface;
use DateTimeZone;
use Nowo\CurrencyKitBundle\Domain\Conversion\ConversionOptions;
use Nowo\CurrencyKitBundle\Domain\Conversion\OnStale;
use Nowo\CurrencyKitBundle\Domain\Conversion\RateLeg;
use Nowo\CurrencyKitBundle\Domain\Currency\CurrencyCode;
use Nowo\CurrencyKitBundle\Domain\Event\ReferenceRatesStale;
use Nowo\CurrencyKitBundle\Domain\Exception\RateNotAvailableException;
use Nowo\CurrencyKitBundle\Domain\Exception\StaleRateException;
use Nowo\CurrencyKitBundle\Domain\Manual\ManualRateView;
use Nowo\CurrencyKitBundle\Domain\Port\RateSourceInterface;
use Nowo\CurrencyKitBundle\Domain\Port\RateStoreInterface;
use Nowo\CurrencyKitBundle\Domain\Port\ResolvedRateCacheInterface;
use Nowo\CurrencyKitBundle\Domain\Rate\PublicationInfo;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves one EUR → currency leg of a conversion (contract section 9.1 step 4 and 9.4): manual rate first, then the
 * active source's newest rate within the lookback window, with the staleness rule for "now". Storage only; lookups go
 * through the request memo and the optional shared cache.
 */
final readonly class RateResolver
{
    /** How far back a "now" conversion looks for the latest publication before calling it stale. */
    private const NOW_HORIZON_DAYS = 366;

    /**
     * Builds the resolver.
     */
    public function __construct(
        private RateStoreInterface $store,
        private RateSourceRegistry $sources,
        private CurrencyKitSettings $settings,
        private RateMemo $memo,
        private ResolvedRateCacheInterface $cache,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private ?EventDispatcherInterface $dispatcher = null,
    ) {
    }

    /**
     * The active source (`conversion.source`).
     */
    public function activeSource(): RateSourceInterface
    {
        return $this->sources->get($this->settings->activeSource);
    }

    /**
     * Today in the active source's calendar.
     */
    public function today(): RateDate
    {
        return RateDate::fromInstant($this->clock->now(), $this->timezone());
    }

    /**
     * The requested date (QC-11): `onDate` wins, then the calendar date of `$on` in the source calendar, else today.
     *
     * @throws RateNotAvailableException `future_date` when the date is after today in the source calendar
     */
    public function requestedDate(?DateTimeInterface $on, ?ConversionOptions $options): RateDate
    {
        $today     = $this->today();
        $requested = $options->onDate ?? ($on instanceof DateTimeInterface ? RateDate::fromInstant($on, $this->timezone()) : $today);
        if ($requested->isAfter($today)) {
            throw new RateNotAvailableException(RateNotAvailableException::REASON_FUTURE_DATE);
        }

        return $requested;
    }

    /**
     * Resolves the EUR → `$currency` leg of a date.
     *
     * @param bool|null $allowStale per-call override of `conversion.on_stale` (true: flag, false: fail)
     *
     * @throws RateNotAvailableException
     * @throws StaleRateException
     *
     * @return array{leg: RateLeg, stale: bool}
     */
    public function leg(CurrencyCode $currency, RateDate $requested, ?bool $allowStale = null): array
    {
        $manual = $this->lookup('m|' . $currency->code . '|' . $requested->value, function () use ($currency, $requested): ?array {
            $rate = $this->store->manualRateCovering($currency->code, $requested);

            return $rate instanceof ManualRateView ? ['id' => $rate->id, 'rate' => $rate->rate] : null;
        });
        if ($manual !== null) {
            return ['leg' => new RateLeg($currency, (string) $manual['rate'], $requested, new SourceKey(SourceKey::MANUAL), $manual['id']), 'stale' => false];
        }

        $source = $this->settings->activeSource;
        if ($source->value === SourceKey::MANUAL) {
            throw new RateNotAvailableException(RateNotAvailableException::REASON_UNKNOWN_FOR_SOURCE);
        }

        $anchor = $requested;
        $stale  = false;
        if ($requested->equals($this->today())) {
            $latest = $this->latestDate($source, $requested);
            if ($latest instanceof RateDate && $latest->daysUntil($requested) > $this->settings->maxStalenessDays) {
                $this->stale($source, $latest, $latest->daysUntil($requested), $allowStale);
                $anchor = $latest;
                $stale  = true;
            }
        }

        $notBefore = $anchor->plusDays(-$this->settings->maxLookbackDays);
        $found     = $this->lookup('r|' . $source->value . '|' . $currency->code . '|' . $anchor->value . '|' . $notBefore->value, function () use ($source, $currency, $anchor, $notBefore): ?array {
            $row = $this->store->rateOnOrBefore($source, $currency->code, $anchor, $notBefore);

            return $row === null ? null : ['date' => $row['date']->value, 'rate' => $row['rate']];
        });
        if ($found !== null) {
            return ['leg' => new RateLeg($currency, (string) $found['rate'], RateDate::of((string) $found['date']), $source), 'stale' => $stale];
        }

        throw new RateNotAvailableException($this->missingReason($source, $anchor, $notBefore));
    }

    /**
     * Forgets the request memo (after a commit that wrote rates or manual rates).
     */
    public function forget(): void
    {
        $this->memo->reset();
    }

    /**
     * Why no rate was found: before the stored history, no publication in the window, or the currency is not offered.
     */
    private function missingReason(SourceKey $source, RateDate $anchor, RateDate $notBefore): string
    {
        $earliest = $this->lookup('e|' . $source->value, function () use ($source): ?array {
            $publication = $this->store->earliestPublication($source);

            return $publication instanceof PublicationInfo ? ['date' => $publication->rateDate->value] : null;
        });
        if ($earliest === null || $anchor->isBefore(RateDate::of((string) $earliest['date']))) {
            return RateNotAvailableException::REASON_BEFORE_HISTORY;
        }
        $publication = $this->lookup('p|' . $source->value . '|' . $anchor->value . '|' . $notBefore->value, function () use ($source, $anchor, $notBefore): ?array {
            $publication = $this->store->publicationOnOrBefore($source, $anchor, $notBefore);

            return $publication instanceof PublicationInfo ? ['date' => $publication->rateDate->value] : null;
        });

        return $publication === null ? RateNotAvailableException::REASON_NO_PUBLICATION_IN_LOOKBACK : RateNotAvailableException::REASON_UNKNOWN_FOR_SOURCE;
    }

    /**
     * The newest publication date within the "now" horizon.
     */
    private function latestDate(SourceKey $source, RateDate $today): ?RateDate
    {
        $latest = $this->lookup('l|' . $source->value . '|' . $today->value, function () use ($source, $today): ?array {
            $publication = $this->store->publicationOnOrBefore($source, $today, $today->plusDays(-self::NOW_HORIZON_DAYS));

            return $publication instanceof PublicationInfo ? ['date' => $publication->rateDate->value] : null;
        });

        return $latest === null ? null : RateDate::of((string) $latest['date']);
    }

    /**
     * Stale data for "now": fail (default) or flag with a warning once per request and the event at most hourly.
     *
     * @throws StaleRateException
     */
    private function stale(SourceKey $source, RateDate $latest, int $ageDays, ?bool $allowStale): void
    {
        $flag = $allowStale ?? ($this->settings->onStale === OnStale::Flag);
        if (!$flag) {
            throw new StaleRateException($latest->value, $ageDays);
        }
        if ($this->memo->once('stale|' . $source->value)) {
            $this->logger->warning('Currency kit: reference rates are stale; the sync may have stopped.', ['source' => $source->value, 'latest' => $latest->value, 'age_days' => $ageDays]);
        }
        if ($this->dispatcher instanceof EventDispatcherInterface && $this->cache->claimOnce('stale_event|' . $source->value, 3600) && $this->memo->once('stale_event|' . $source->value)) {
            $this->dispatcher->dispatch(new ReferenceRatesStale($source, $latest, $ageDays));
        }
    }

    /**
     * A lookup through the request memo, then the shared cache, then the store.
     *
     * @param callable(): (array<string, string|null>|null) $load
     *
     * @return array<string, string|null>|null
     */
    private function lookup(string $key, callable $load): ?array
    {
        if ($this->memo->has($key)) {
            return $this->memo->get($key);
        }
        $cached = $this->cache->get($key);
        if ($cached !== null) {
            if (($cached['found'] ?? null) !== '1') {
                // @igor-ignore - the request memo is cleared at every boundary (RateMemoResetListener, kernel.reset)
                $this->memo->set($key, null);

                return null;
            }
            unset($cached['found']);
            // @igor-ignore - the request memo is cleared at every boundary (RateMemoResetListener, kernel.reset)
            $this->memo->set($key, $cached);

            return $cached;
        }
        $value = $load();
        // @igor-ignore - shared PSR-6 cache of public data, versioned and TTL-bounded; nothing kept on the service
        $this->cache->set($key, ($value ?? []) + ['found' => $value === null ? '0' : '1']);
        // @igor-ignore - the request memo is cleared at every boundary (RateMemoResetListener, kernel.reset)
        $this->memo->set($key, $value);

        return $value;
    }

    /**
     * The active source's calendar.
     */
    private function timezone(): DateTimeZone
    {
        return $this->activeSource()->timezone();
    }
}
