<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Port;

use Nowo\CurrencyKitBundle\Domain\Exception\OutboundDisabledException;
use Nowo\CurrencyKitBundle\Domain\Exception\RateSourceResponseException;
use Nowo\CurrencyKitBundle\Domain\Exception\RateSourceUnavailableException;
use Nowo\CurrencyKitBundle\Domain\Rate\FetchedBody;
use Nowo\CurrencyKitBundle\Domain\Rate\FetchLimits;

/**
 * The only HTTP code of the bundle (contract section 7.2): a GET through the outbound URL guard, no redirects, a
 * timeout and a decoded-byte cap.
 */
interface OutboundFetcherInterface
{
    /**
     * GETs the URL and returns the decoded body.
     *
     * @param bool $allowPrivate allow a private-network address (a self-hosted mirror); cloud metadata stays blocked
     * @param int $attempts attempts for transient failures (backoff 2 s then 8 s between them)
     *
     * @throws OutboundDisabledException before anything else (no DNS either) when outbound calls are disabled
     * @throws RateSourceUnavailableException transient failure after the last attempt
     * @throws RateSourceResponseException 4xx or size cap
     */
    public function get(string $url, FetchLimits $limits, bool $allowPrivate = false, int $attempts = 1): FetchedBody;
}
