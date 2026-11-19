<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Source\Frankfurter;

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

use function rtrim;

/**
 * A Frankfurter-compatible JSON API (a self-hosted mirror of ECB data; off by default): `GET {base}/latest?base=EUR`
 * for the daily feed and `GET {base}/{from}..{to}?base=EUR` for ranges. The base URI comes from the configuration
 * only; `allow_private_network` lets the guard accept a mirror on a private network (cloud metadata stays blocked).
 */
final readonly class FrankfurterCompatibleSource implements RateSourceInterface
{
    /**
     * Builds the source.
     */
    public function __construct(
        private OutboundFetcherInterface $fetcher,
        private FrankfurterJsonParser $parser,
        private SourceKey $key,
        private SourceLabel $label,
        private string $baseUri,
        private DateTimeZone $timezone,
        private bool $allowPrivateNetwork,
        private bool $rebase,
        private int $maxBytes,
        private float $timeout,
        private float $maxDuration,
        private int $maxDays,
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
        return $feed === FetchFeed::Daily || $feed === FetchFeed::Range;
    }

    /**
     * {@inheritDoc}
     */
    public function fetch(FetchRequest $request): RateBatch
    {
        $base = rtrim($this->baseUri, '/');
        $path = match ($request->feed) {
            FetchFeed::Daily => '/latest',
            FetchFeed::Range => '/' . $request->from?->value . '..' . $request->to?->value,
            default          => throw new InvalidValueException('A Frankfurter-compatible source serves the latest day and date ranges.'),
        };
        $body = $this->fetcher->get($base . $path . '?base=EUR', new FetchLimits($this->maxBytes, $this->timeout, $this->maxDuration, 'json'), $this->allowPrivateNetwork, $request->attempts);

        return new RateBatch(RateOrigin::Api, $this->parser->parse($body->content, $this->rebase, $this->maxDays));
    }

    /**
     * {@inheritDoc}
     */
    public function earliestDate(): RateDate
    {
        return RateDate::of('1999-01-04');
    }

    /**
     * {@inheritDoc}
     */
    public function timezone(): DateTimeZone
    {
        return $this->timezone;
    }
}
