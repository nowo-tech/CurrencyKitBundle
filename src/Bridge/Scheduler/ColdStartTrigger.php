<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Bridge\Scheduler;

use DateTimeImmutable;
use Nowo\CurrencyKitBundle\Application\ReferenceRateReaderInterface;
use Nowo\CurrencyKitBundle\Domain\Rate\PublicationInfo;
use Symfony\Component\Scheduler\Trigger\TriggerInterface;
use Throwable;

/**
 * One sync on a cold start when no rate exists yet (Beacon QB-6): fires right after the scheduler starts, then every
 * `$retryMinutes` while the store is still empty, and never once a publication exists. The only state is whether the
 * first run time was handed out, kept on the trigger object of the schedule (never in a container service).
 */
final class ColdStartTrigger implements TriggerInterface
{
    private bool $started = false;

    /**
     * Builds the trigger.
     */
    public function __construct(private readonly ReferenceRateReaderInterface $reader, private readonly int $retryMinutes = 30)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function __toString(): string
    {
        return 'cold start (no reference rate stored yet)';
    }

    /**
     * {@inheritDoc}
     */
    public function getNextRunDate(DateTimeImmutable $run): ?DateTimeImmutable
    {
        try {
            $hasRates = $this->reader->latest() instanceof PublicationInfo;
        } catch (Throwable) {
            // The database is down: never crash the scheduler worker; look again later (audit VR-14).
            return $run->modify('+' . $this->retryMinutes . ' minutes');
        }
        if ($hasRates) {
            return null;
        }
        if (!$this->started) {
            // @igor-ignore - per-schedule trigger object (built in getSchedule()), not a shared worker service.
            $this->started = true;

            return $run;
        }

        return $run->modify('+' . $this->retryMinutes . ' minutes');
    }
}
