<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Application;

use Nowo\CurrencyKitBundle\Domain\Port\TargetCalendarInterface;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;

use function in_array;
use function intdiv;
use function sprintf;
use function substr;

/**
 * TARGET business days: Monday to Friday except 1 January, Good Friday, Easter Monday, 1 May, 25 and 26 December.
 * Used for scheduling and staleness reports only; never to invent a rate.
 */
final readonly class TargetCalendar implements TargetCalendarInterface
{
    /**
     * {@inheritDoc}
     */
    public function isBusinessDay(RateDate $date): bool
    {
        if ($date->dayOfWeek() >= 6) {
            return false;
        }
        if (in_array(substr($date->value, 5), ['01-01', '05-01', '12-25', '12-26'], true)) {
            return false;
        }
        $easter = $this->easter((int) substr($date->value, 0, 4));

        return !$date->equals($easter->plusDays(-2)) && !$date->equals($easter->plusDays(1));
    }

    /**
     * {@inheritDoc}
     */
    public function previousBusinessDay(RateDate $date): RateDate
    {
        $day = $date->plusDays(-1);
        while (!$this->isBusinessDay($day)) {
            $day = $day->plusDays(-1);
        }

        return $day;
    }

    /**
     * Easter Sunday (Gregorian) of a year, without ext-calendar.
     */
    private function easter(int $year): RateDate
    {
        $a     = $year % 19;
        $b     = intdiv($year, 100);
        $c     = $year % 100;
        $d     = intdiv($b, 4);
        $e     = $b % 4;
        $f     = intdiv($b + 8, 25);
        $g     = intdiv($b - $f + 1, 3);
        $h     = (19 * $a + $b - $d - $g + 15) % 30;
        $i     = intdiv($c, 4);
        $k     = $c % 4;
        $l     = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m     = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day   = (($h + $l - 7 * $m + 114) % 31) + 1;

        return RateDate::of(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }
}
