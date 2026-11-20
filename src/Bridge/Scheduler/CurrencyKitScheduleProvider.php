<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Bridge\Scheduler;

use DateTimeZone;
use Nowo\CurrencyKitBundle\Application\CurrencyKitSettings;
use Nowo\CurrencyKitBundle\Application\ReferenceRateReaderInterface;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Messenger\SyncReferenceRatesMessage;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

use function explode;
use function sprintf;

/**
 * The Scheduler bridge (`scheduler.enabled: true`, contract section 8.1): {@see SyncReferenceRatesMessage} Monday to
 * Friday at `sync.times` (16:15, 17:00, 18:30 Europe/Berlin by default) and at `sync.catch_up_time` (08:00), plus one
 * run on a cold start while no rate is stored (QB-6; only when `sync.initial` loads days, outbound calls are on and
 * the active source is not `manual`). Registered as the schedule `nowo_currency_kit`
 * (transport `scheduler_nowo_currency_kit`).
 */
final readonly class CurrencyKitScheduleProvider implements ScheduleProviderInterface
{
    /** Schedule name. */
    public const NAME = 'nowo_currency_kit';

    /**
     * @param list<string> $times `HH:MM` in Europe/Berlin
     */
    public function __construct(
        private ReferenceRateReaderInterface $reader,
        private CurrencyKitSettings $settings,
        private array $times,
        private string $catchUpTime,
        private string $timezone = 'Europe/Berlin',
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function getSchedule(): Schedule
    {
        $zone     = new DateTimeZone($this->timezone);
        $schedule = new Schedule();
        foreach ([...$this->times, $this->catchUpTime] as $time) {
            [$hour, $minute] = explode(':', $time);
            $schedule->add(RecurringMessage::cron(sprintf('%d %d * * 1-5', (int) $minute, (int) $hour), new SyncReferenceRatesMessage(), $zone));
        }
        // The cold-start run only makes sense when a sync can load the first days (audit VR-14).
        $settings = $this->settings;
        if ($settings->initialLast90Days && $settings->outboundEnabled && $settings->activeSource->value !== SourceKey::MANUAL) {
            $schedule->add(RecurringMessage::trigger(new ColdStartTrigger($this->reader), new SyncReferenceRatesMessage()));
        }

        return $schedule;
    }
}
