<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Event;

use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;

/**
 * A sync run failed (`reason` is the exception's reason or error code; `transient` true for a retryable failure). Dispatched after the commit; carries value objects and ids only.
 */
final readonly class ReferenceRateSyncFailed
{
    /**
     * Builds the event.
     */
    public function __construct(
        public SourceKey $source,
        public string $reason,
        public bool $transient,
    ) {
    }
}
