<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Rate;

use Nowo\CurrencyKitBundle\Domain\Exception\InvalidValueException;

/**
 * What to fetch: a feed and, for {@see FetchFeed::Range}, the inclusive date range. `$attempts` caps the transient
 * retries (1 = a single attempt, the UI "sync now" path).
 */
final readonly class FetchRequest
{
    /**
     * @throws InvalidValueException when a range has no bounds or ends before it starts
     */
    public function __construct(
        public FetchFeed $feed,
        public ?RateDate $from = null,
        public ?RateDate $to = null,
        public int $attempts = 1,
    ) {
        if ($feed === FetchFeed::Range && (!$from instanceof RateDate || !$to instanceof RateDate || $to->isBefore($from))) {
            throw new InvalidValueException('A range fetch needs from <= to.');
        }
        if ($attempts < 1) {
            throw new InvalidValueException('A fetch needs at least one attempt.');
        }
    }
}
