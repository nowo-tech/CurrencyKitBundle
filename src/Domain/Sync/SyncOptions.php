<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Sync;

use Nowo\CurrencyKitBundle\Domain\Manual\ActorRef;
use Nowo\CurrencyKitBundle\Domain\Rate\FetchFeed;

/**
 * Options of a sync run (contract section 12.4).
 */
final readonly class SyncOptions
{
    /**
     * @param FetchFeed|null $feed force a feed (`daily` or `last_90_days`); null picks it from the gap
     * @param list<string> $acceptImplausible currency codes whose implausible change is accepted (audited)
     * @param int $attempts fetch attempts for transient failures (1 + `outbound.retries` on the console path)
     * @param ActorRef|null $actor who triggered the run (audit), null for a scheduled run
     * @param bool $manualTrigger a person asked for the run (audited as `currency_kit.sync.manual_trigger`)
     */
    public function __construct(
        public ?FetchFeed $feed = null,
        public array $acceptImplausible = [],
        public int $attempts = 1,
        public ?ActorRef $actor = null,
        public bool $manualTrigger = false,
    ) {
    }
}
