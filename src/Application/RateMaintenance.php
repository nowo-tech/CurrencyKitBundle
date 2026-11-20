<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Application;

use Nowo\CurrencyKitBundle\Domain\Audit\AuditEntry;
use Nowo\CurrencyKitBundle\Domain\Event\ReferenceRateChanged;
use Nowo\CurrencyKitBundle\Domain\Exception\InvalidValueException;
use Nowo\CurrencyKitBundle\Domain\Exception\SyncInterruptedException;
use Nowo\CurrencyKitBundle\Domain\Manual\ActorRef;
use Nowo\CurrencyKitBundle\Domain\Port\CurrencyKitAuditSinkInterface;
use Nowo\CurrencyKitBundle\Domain\Port\IdGeneratorInterface;
use Nowo\CurrencyKitBundle\Domain\Port\RateStoreInterface;
use Nowo\CurrencyKitBundle\Domain\Port\ResolvedRateCacheInterface;
use Nowo\CurrencyKitBundle\Domain\Port\WriteRunnerInterface;
use Nowo\CurrencyKitBundle\Domain\Rate\RateChange;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;
use Nowo\CurrencyKitBundle\Domain\Sync\SyncOutcome;
use Nowo\CurrencyKitBundle\Domain\Sync\SyncReport;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * Maintenance writes (contract sections 6.2, 8.3, 13.2 and 14): apply a kept change, prune old days, verify content
 * hashes and forget an actor. Each write is its own unit; events and audit lines follow the commit.
 */
final readonly class RateMaintenance
{
    /** Publication days deleted per prune unit. */
    public const PRUNE_BATCH_DAYS = 1000;

    /**
     * Builds the service.
     */
    public function __construct(
        private RateStoreInterface $store,
        private WriteRunnerInterface $runner,
        private IdGeneratorInterface $ids,
        private CurrencyKitAuditSinkInterface $audit,
        private ResolvedRateCacheInterface $cache,
        private RateMemo $memo,
        private RateResolver $resolver,
        private CurrencyKitSettings $settings,
        private ClockInterface $clock,
        private ?EventDispatcherInterface $dispatcher = null,
        private int $pruneBatchDays = self::PRUNE_BATCH_DAYS,
    ) {
    }

    /**
     * Applies a change kept by `on_changed_value: keep` (audited). Null when it does not exist, was applied already or
     * is a removal (a removed currency is never deleted from a stored day).
     */
    public function applyChange(string $changeId, ?ActorRef $actor): ?RateChange
    {
        $runId = $this->ids->generate();
        /** @var RateChange|null $change */
        $change = $this->runner->write(fn (): ?RateChange => $this->store->applyChange($changeId, $runId, $this->clock->now()));
        if ($change === null) {
            return null;
        }
        $this->cache->invalidate();
        $this->memo->reset();
        $this->audit->record(new AuditEntry(AuditEntry::CHANGE_APPLIED, $actor?->value, [
            'change_id' => $changeId,
            'source'    => $change->source->value,
            'date'      => $change->rateDate->value,
            'currency'  => $change->currency,
            'old_rate'  => $change->oldRate,
            'new_rate'  => $change->newRate,
        ]));
        $this->dispatcher?->dispatch(new ReferenceRateChanged($change->source, $change->rateDate, $change->currency, $change->oldRate, $change->newRate, true, $change->id));

        return $change;
    }

    /**
     * Deletes publication days (and rates) before `$before`, in units of 1 000 days. Refuses a date inside the
     * lookback window of today; never prunes changes or manual rates.
     *
     * @throws InvalidValueException when `$before` is inside `conversion.max_lookback_days` of today
     * @throws SyncInterruptedException when a batch fails after earlier batches were committed
     *
     * @return int days deleted
     */
    public function prune(SourceKey $source, RateDate $before, ?ActorRef $actor): int
    {
        if ($before->isAfter($this->resolver->today()->plusDays(-$this->settings->maxLookbackDays))) {
            throw new InvalidValueException('Pruning inside the lookback window of today is refused.');
        }
        $total = 0;
        do {
            try {
                /** @var int $deleted */
                $deleted = $this->runner->write(fn (): int => $this->store->pruneBefore($source, $before, $this->pruneBatchDays));
            } catch (Throwable $e) {
                // Earlier batches are committed: never a blind retry (CONFLICT_002 on a lock conflict; audit VR-11).
                throw $total > 0 ? new SyncInterruptedException(new SyncReport($source, SyncOutcome::Changed), $e) : $e;
            }
            $total += $deleted;
        } while ($deleted === $this->pruneBatchDays);
        if ($total > 0) {
            $this->cache->invalidate();
            $this->memo->reset();
        }
        $this->audit->record(new AuditEntry(AuditEntry::PRUNE, $actor?->value, ['source' => $source->value, 'before' => $before->value, 'days' => $total]));

        return $total;
    }

    /**
     * Publication dates whose rows changed outside the bundle.
     *
     * @return list<string>
     */
    public function verify(SourceKey $source): array
    {
        return $this->store->verify($source);
    }

    /**
     * Sets an actor reference to NULL on manual rates (privacy, contract section 14).
     */
    public function forgetActor(ActorRef $actor, ?ActorRef $by): int
    {
        /** @var int $rows */
        $rows = $this->runner->write(fn (): int => $this->store->forgetActor($actor));
        $this->audit->record(new AuditEntry(AuditEntry::ACTOR_FORGOTTEN, $by?->value, ['rows' => $rows]));

        return $rows;
    }
}
