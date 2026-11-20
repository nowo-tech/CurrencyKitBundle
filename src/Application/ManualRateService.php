<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Application;

use Nowo\CurrencyKitBundle\Domain\Audit\AuditEntry;
use Nowo\CurrencyKitBundle\Domain\Event\ManualRateCreated;
use Nowo\CurrencyKitBundle\Domain\Event\ManualRateEnded;
use Nowo\CurrencyKitBundle\Domain\Event\ManualRateUpdated;
use Nowo\CurrencyKitBundle\Domain\Exception\ManualRateNotFoundException;
use Nowo\CurrencyKitBundle\Domain\Exception\ManualRateOverlapException;
use Nowo\CurrencyKitBundle\Domain\Exception\ManualRateRefusedException;
use Nowo\CurrencyKitBundle\Domain\Exception\StaleManualRateException;
use Nowo\CurrencyKitBundle\Domain\Manual\ActorRef;
use Nowo\CurrencyKitBundle\Domain\Manual\ManualRateInput;
use Nowo\CurrencyKitBundle\Domain\Manual\ManualRateStatus;
use Nowo\CurrencyKitBundle\Domain\Manual\ManualRateView;
use Nowo\CurrencyKitBundle\Domain\Money\Decimal;
use Nowo\CurrencyKitBundle\Domain\Port\CurrencyKitActorResolverInterface;
use Nowo\CurrencyKitBundle\Domain\Port\CurrencyKitAuditSinkInterface;
use Nowo\CurrencyKitBundle\Domain\Port\IdGeneratorInterface;
use Nowo\CurrencyKitBundle\Domain\Port\RateStoreInterface;
use Nowo\CurrencyKitBundle\Domain\Port\ResolvedRateCacheInterface;
use Nowo\CurrencyKitBundle\Domain\Port\WriteRunnerInterface;
use Nowo\CurrencyKitBundle\Domain\Rate\RateDate;
use Nowo\CurrencyKitBundle\Domain\Rate\SourceKey;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

use function max;
use function min;

/**
 * Manual rates (contract section 10): EUR → currency over a window, versioned, never deleted. Creating or moving one
 * locks the currency's rows (`FOR UPDATE`, by id) and refuses an overlap (`CURRENCY_009`); an edit on an old version is
 * `CURRENCY_008`. Events, audit lines and cache invalidation follow the commit. The actor is resolved on every attempt.
 */
final readonly class ManualRateService implements ManualRateServiceInterface
{
    /**
     * Builds the service.
     */
    public function __construct(
        private RateStoreInterface $store,
        private WriteRunnerInterface $runner,
        private IdGeneratorInterface $ids,
        private CurrencyKitActorResolverInterface $actors,
        private CurrencyKitAuditSinkInterface $audit,
        private ResolvedRateCacheInterface $cache,
        private RateMemo $memo,
        private RateResolver $resolver,
        private CurrencyKitSettings $settings,
        private ClockInterface $clock,
        private ?EventDispatcherInterface $dispatcher = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function create(ManualRateInput $input, ?ActorRef $actor = null): ManualRateView
    {
        $this->checkRules($input, null);

        /** @var ManualRateView $view */
        // @igor-ignore - the write runner calls the closure at once and keeps nothing
        $view = $this->runner->write(function () use ($input, $actor): ManualRateView {
            $actor ??= $this->actors->current();
            foreach ($this->store->lockManualRates($input->currency->code) as $existing) {
                if ($existing->overlaps($input->validFrom, $input->validUntil)) {
                    throw new ManualRateOverlapException();
                }
            }
            $now  = $this->clock->now();
            $view = new ManualRateView($this->ids->generate(), $input->currency, $input->rate, $input->validFrom, $input->validUntil, $input->reason, ManualRateStatus::Active, $actor, $now, $actor, $now, 1);
            $this->store->insertManualRate($view);

            return $view;
        });
        $this->committed(AuditEntry::MANUAL_RATE_CREATED, $view, null, $input->force);
        $this->dispatcher?->dispatch(new ManualRateCreated($view->id, $view->currency, $view->rate, $view->validFrom, $view->validUntil, $view->createdBy, $view->version));

        return $view;
    }

    /**
     * {@inheritDoc}
     */
    public function update(string $id, ManualRateInput $input, int $expectedVersion, ?ActorRef $actor = null): ManualRateView
    {
        /** @var array{0: ManualRateView, 1: ManualRateView} $pair */
        // @igor-ignore - the write runner calls the closure at once and keeps nothing
        $pair = $this->runner->write(function () use ($id, $input, $expectedVersion, $actor): array {
            $actor ??= $this->actors->current();
            $current = $this->store->manualRate($id) ?? throw new ManualRateNotFoundException();
            if ($current->version !== $expectedVersion) {
                throw new StaleManualRateException();
            }
            if ($current->status === ManualRateStatus::Ended || !$current->currency->equals($input->currency)) {
                throw new ManualRateRefusedException('An ended manual rate, or its currency, cannot be changed.');
            }
            $this->checkRules($input, $current);
            $this->keepThePast($current, $input);
            if (!$current->validFrom->equals($input->validFrom) || $current->validUntil?->value !== $input->validUntil?->value) {
                foreach ($this->store->lockManualRates($input->currency->code) as $existing) {
                    if ($existing->id !== $current->id && $existing->overlaps($input->validFrom, $input->validUntil)) {
                        throw new ManualRateOverlapException();
                    }
                }
            }
            $next = new ManualRateView($current->id, $current->currency, $input->rate, $input->validFrom, $input->validUntil, $input->reason, ManualRateStatus::Active, $current->createdBy, $current->createdAt, $actor, $this->clock->now(), $current->version + 1);
            if (!$this->store->updateManualRate($next, $expectedVersion)) {
                throw new StaleManualRateException();
            }

            return [$current, $next];
        });
        [$old, $view] = $pair;
        $this->committed(AuditEntry::MANUAL_RATE_UPDATED, $view, $old, $input->force);
        $this->dispatcher?->dispatch(new ManualRateUpdated($view->id, $view->currency, $view->rate, $view->validFrom, $view->validUntil, $view->updatedBy, $view->version));

        return $view;
    }

    /**
     * {@inheritDoc}
     */
    public function end(string $id, RateDate $lastDay, int $expectedVersion, ?ActorRef $actor = null): ManualRateView
    {
        /** @var array{0: ManualRateView, 1: ManualRateView} $pair */
        // @igor-ignore - the write runner calls the closure at once and keeps nothing
        $pair = $this->runner->write(function () use ($id, $lastDay, $expectedVersion, $actor): array {
            $actor ??= $this->actors->current();
            $current = $this->store->manualRate($id) ?? throw new ManualRateNotFoundException();
            if ($current->version !== $expectedVersion) {
                throw new StaleManualRateException();
            }
            if ($current->status === ManualRateStatus::Ended) {
                throw new ManualRateRefusedException('The manual rate is already ended.');
            }
            if ($lastDay->isBefore($current->validFrom->plusDays(-1)) || ($current->validUntil instanceof RateDate && $lastDay->isAfter($current->validUntil))) {
                throw new ManualRateRefusedException('The last day must be inside the window (or the day before it starts).');
            }
            $this->keepThePastOnEnd($current, $lastDay);
            $next = new ManualRateView($current->id, $current->currency, $current->rate, $current->validFrom, $lastDay, $current->reason, ManualRateStatus::Ended, $current->createdBy, $current->createdAt, $actor, $this->clock->now(), $current->version + 1);
            if (!$this->store->updateManualRate($next, $expectedVersion)) {
                throw new StaleManualRateException();
            }

            return [$current, $next];
        });
        [$old, $view] = $pair;
        $this->committed(AuditEntry::MANUAL_RATE_ENDED, $view, $old, false);
        $this->dispatcher?->dispatch(new ManualRateEnded($view->id, $view->currency, $view->rate, $view->validFrom, $view->validUntil, $view->updatedBy, $view->version));

        return $view;
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $id): ManualRateView
    {
        return $this->store->manualRate($id) ?? throw new ManualRateNotFoundException();
    }

    /**
     * {@inheritDoc}
     */
    public function list(?ManualRateStatus $status = null, ?string $currency = null, int $page = 1, int $pageSize = 50): array
    {
        $pageSize = max(1, min(500, $pageSize));

        return $this->store->manualRates($status, $currency, (max(1, $page) - 1) * $pageSize, $pageSize);
    }

    /**
     * Past windows only with `manual.allow_past`; a deviation beyond `manual.max_deviation_percent` from the source's
     * latest value only with `force` (audited as `forced: true`).
     *
     * @throws ManualRateRefusedException
     */
    private function checkRules(ManualRateInput $input, ?ManualRateView $current): void
    {
        $today = $this->resolver->today();
        $moved = !$current instanceof ManualRateView || !$current->validFrom->equals($input->validFrom);
        if ($moved && !$this->settings->manualAllowPast && $input->validFrom->isBefore($today)) {
            throw new ManualRateRefusedException('A manual rate cannot start in the past (manual.allow_past is false).');
        }
        if ($input->force || $this->settings->activeSource->value === SourceKey::MANUAL) {
            return;
        }
        $latest = $this->store->rateOnOrBefore($this->settings->activeSource, $input->currency->code, $today, $today->plusDays(-366));
        if ($latest !== null && Decimal::movedMoreThan($latest['rate'], $input->rate, $this->settings->manualMaxDeviationPercent)) {
            throw new ManualRateRefusedException('The rate deviates from the source by more than manual.max_deviation_percent; confirm it with force.');
        }
    }

    /**
     * Without `manual.allow_past`, an edit never changes the result of a past date (owner decision Q2, audit VR-3): on a
     * window that has started, the rate and the first day stay as they are, and the last day may only move between
     * yesterday and later (end it yesterday and create a new rate instead).
     *
     * @throws ManualRateRefusedException
     */
    private function keepThePast(ManualRateView $current, ManualRateInput $input): void
    {
        $today = $this->resolver->today();
        if ($this->settings->manualAllowPast || $current->validFrom->isAfter($today)) {
            return;
        }
        $yesterday = $today->plusDays(-1);
        $untilKept = $current->validUntil?->value === $input->validUntil?->value;
        $untilOk   = $untilKept || (!$this->endsBefore($current->validUntil, $yesterday) && !$this->endsBefore($input->validUntil, $yesterday));
        if (Decimal::compare($current->rate, $input->rate) !== 0 || !$current->validFrom->equals($input->validFrom) || !$untilOk) {
            throw new ManualRateRefusedException('A started manual rate cannot change past dates (manual.allow_past is false): end it yesterday and create a new one.');
        }
    }

    /**
     * Without `manual.allow_past`, ending never changes a past date: the last day is yesterday or later, and a window
     * that has started cannot be retracted.
     *
     * @throws ManualRateRefusedException
     */
    private function keepThePastOnEnd(ManualRateView $current, RateDate $lastDay): void
    {
        $today = $this->resolver->today();
        if ($this->settings->manualAllowPast || $current->validFrom->isAfter($today)) {
            return;
        }
        if ($lastDay->isBefore($today->plusDays(-1)) || $lastDay->isBefore($current->validFrom)) {
            throw new ManualRateRefusedException('A started manual rate ends yesterday at the earliest and cannot be retracted (manual.allow_past is false).');
        }
    }

    /**
     * Whether a last day (null = open) is before the date.
     */
    private function endsBefore(?RateDate $until, RateDate $date): bool
    {
        return $until instanceof RateDate && $until->isBefore($date);
    }

    /**
     * After the commit: cache and memo invalidated (every worker sees the change at once), audit line.
     */
    private function committed(string $action, ManualRateView $view, ?ManualRateView $old, bool $forced): void
    {
        $this->cache->invalidate();
        $this->memo->reset();
        $this->audit->record(new AuditEntry($action, ($view->updatedBy ?? $view->createdBy)?->value, [
            'id'          => $view->id,
            'currency'    => $view->currency->code,
            'old_rate'    => $old?->rate,
            'new_rate'    => $view->rate,
            'valid_from'  => $view->validFrom->value,
            'valid_until' => $view->validUntil?->value,
            'version'     => $view->version,
            'reason'      => $view->reason,
            'forced'      => $forced,
        ]));
    }
}
