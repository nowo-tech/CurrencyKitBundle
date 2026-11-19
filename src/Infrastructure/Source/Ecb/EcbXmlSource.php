<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Source\Ecb;

use DateTimeZone;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidValueException;
use Nowo\CurrencyKitBundle\Domain\Port\OutboundFetcherInterface;
use Nowo\CurrencyKitBundle\Domain\Port\RateSourceInterface;
use Nowo\CurrencyKitBundle\Domain\Rate\FetchFeed;
use Nowo\CurrencyKitBundle\Domain\Rate\FetchLimits;
use Nowo\CurrencyKitBundle\Domain\Rate\FetchRequest;
use Nowo\CurrencyKitBundle\Domain\Rate\RateBatch;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use Nowo\CurrencyKitBundle\Domain\Rate\RateOrigin;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceLabel;

use function max;

/**
 * The euro reference rates of the European Central Bank (key `ecb`): the daily file, the 90-day file and the full
 * history (only from `rates:backfill`, QC-10). Fixed URLs from the configuration; the calendar is Europe/Berlin.
 */
final readonly class EcbXmlSource implements RateSourceInterface
{
    /** The first ECB reference rate day. */
    public const EARLIEST = '1999-01-04';

    /**
     * @param array{daily: int, last_90_days: int, history: int} $maxBytes decoded-byte caps per feed
     */
    public function __construct(
        private OutboundFetcherInterface $fetcher,
        private EcbXmlParser $parser,
        private SourceLabel $label,
        private string $dailyUrl,
        private string $last90DaysUrl,
        private string $historyUrl,
        private array $maxBytes,
        private float $timeout,
        private float $maxDuration,
        private int $maxDays,
        private SourceKey $key = new SourceKey('ecb'),
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function key(): SourceKey
    {
        return $this->key;
    }

    /**
     * {@inheritDoc}
     */
    public function label(): SourceLabel
    {
        return $this->label;
    }

    /**
     * {@inheritDoc}
     */
    public function supports(FetchFeed $feed): bool
    {
        return $feed !== FetchFeed::Range;
    }

    /**
     * {@inheritDoc}
     */
    public function fetch(FetchRequest $request): RateBatch
    {
        [$url, $bytes, $duration, $origin] = match ($request->feed) {
            FetchFeed::Daily       => [$this->dailyUrl, $this->maxBytes['daily'], $this->maxDuration, RateOrigin::Daily],
            FetchFeed::Last90Days  => [$this->last90DaysUrl, $this->maxBytes['last_90_days'], $this->maxDuration, RateOrigin::History90],
            FetchFeed::FullHistory => [$this->historyUrl, $this->maxBytes['history'], max($this->maxDuration, 120.0), RateOrigin::History],
            FetchFeed::Range       => throw new InvalidValueException('The ECB source serves files, not date ranges.'),
        };
        $body = $this->fetcher->get($url, new FetchLimits($bytes, $this->timeout, $duration, 'xml'), false, $request->attempts);

        return new RateBatch($origin, $this->parser->parseString($body->content, $this->maxDays));
    }

    /**
     * {@inheritDoc}
     */
    public function earliestDate(): RateDate
    {
        return RateDate::of(self::EARLIEST);
    }

    /**
     * {@inheritDoc}
     */
    public function timezone(): DateTimeZone
    {
        return new DateTimeZone('Europe/Berlin');
    }
}
