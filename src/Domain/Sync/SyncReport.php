<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Sync;

use Nowo\CurrencyKitBundle\Domain\Rate\FetchFeed;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;

/**
 * The result of a sync, backfill or import run.
 */
final readonly class SyncReport
{
    /** A gap of more than 90 days: older days need `rates:backfill`. */
    public const WARNING_GAP_EXCEEDS_90_DAYS = 'gap_exceeds_90_days';

    /** A code in the feed that is not in the ISO table, or a refused X-code: skipped, never stored. */
    public const WARNING_UNKNOWN_CURRENCY = 'unknown_currency_skipped';

    /** The daily feed is older than the stored latest day (a lagging mirror). */
    public const WARNING_FEED_BEHIND = 'feed_behind_storage';

    /**
     * @param list<string> $stored dates stored for the first time
     * @param list<string> $changed dates whose values changed (change rows written)
     * @param list<string> $unchanged dates already stored with equal values
     * @param list<string> $warnings `WARNING_*` constants
     * @param bool $expected for NotYetPublished: whether today is a TARGET business day
     * @param int|null $retryAfter seconds a skipped run (lease held) can retry after
     */
    public function __construct(
        public SourceKey $source,
        public SyncOutcome $outcome,
        public ?FetchFeed $feed = null,
        public array $stored = [],
        public array $changed = [],
        public array $unchanged = [],
        public array $warnings = [],
        public bool $expected = true,
        public ?int $retryAfter = null,
    ) {
    }
}
