<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Port;

use DateTimeZone;
use Nowo\CurrencyKitBundle\Domain\Exception\OutboundDisabledException;
use Nowo\CurrencyKitBundle\Domain\Exception\RateSourceResponseException;
use Nowo\CurrencyKitBundle\Domain\Exception\RateSourceUnavailableException;
use Nowo\CurrencyKitBundle\Domain\Rate\FetchFeed;
use Nowo\CurrencyKitBundle\Domain\Rate\FetchRequest;
use Nowo\CurrencyKitBundle\Domain\Rate\RateBatch;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceLabel;

/**
 * A reference-rate source (contract section 7.1), tagged `nowo_currency_kit.rate_source`.
 *
 * `fetch()` fetches and parses only: no storage, no lock, no transaction. The result is EUR-based (an adapter rebases
 * when its feed is not). Values are decimal strings parsed from the feed text, never through float.
 */
interface RateSourceInterface
{
    /**
     * The storage key (`ecb`, `mirror`, host keys).
     */
    public function key(): SourceKey;

    /**
     * The text users see (`ECB`).
     */
    public function label(): SourceLabel;

    /**
     * Whether the source can serve the feed; the synchronizer picks the smallest one that covers the gap.
     */
    public function supports(FetchFeed $feed): bool;

    /**
     * Fetches and parses a feed.
     *
     * @throws RateSourceUnavailableException transient (timeout, 5xx, DNS, guard refusal, redirect)
     * @throws RateSourceResponseException permanent (4xx, malformed body, size cap, wrong base)
     * @throws OutboundDisabledException when `outbound.enabled` is false
     */
    public function fetch(FetchRequest $request): RateBatch;

    /**
     * The first date the source can have (ECB: 1999-01-04), null when unknown.
     */
    public function earliestDate(): ?RateDate;

    /**
     * The source's publication calendar (Europe/Berlin for the ECB): "today" and the calendar date of an instant.
     */
    public function timezone(): DateTimeZone;
}
