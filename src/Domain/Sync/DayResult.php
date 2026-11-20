<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Sync;

use Nowo\CurrencyKitBundle\Domain\Rate\RateChange;

/**
 * What storing one day did (contract section 8.3): the outcome and the change rows it wrote.
 */
final readonly class DayResult
{
    /**
     * @param list<RateChange> $changes
     */
    public function __construct(public SyncOutcome $outcome, public int $currencyCount, public array $changes = [])
    {
    }
}
