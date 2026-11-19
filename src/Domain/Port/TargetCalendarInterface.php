<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Port;

use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;

/**
 * Expected publication days (TARGET business days) for scheduling and reports only: never used to invent a rate.
 */
interface TargetCalendarInterface
{
    /**
     * Whether the source is expected to publish on that date.
     */
    public function isBusinessDay(RateDate $date): bool;

    /**
     * The last business day strictly before the date.
     */
    public function previousBusinessDay(RateDate $date): RateDate;
}
