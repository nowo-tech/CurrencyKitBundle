<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Symfony\Security;

use Nowo\CurrencyKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CurrencyKitBundle\Domain\Manual\ActorRef;
use Nowo\CurrencyKitBundle\Domain\Port\CurrencyKitAccessCheckerInterface;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Event\CurrencyAccessCheckEvent;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Event\CurrencyKitEvents;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Event\ManualRateAccessCheckEvent;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Event\SyncAccessCheckEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function in_array;

/**
 * The currency kit guard (house access pattern, contract section 13.1), called first by every admin operation: the
 * console commands that read or write in 0.1, the UI in 0.2.
 *
 * Web: no user and not `allow_unauthenticated` ⇒ denied; the area checker decides, then
 * {@see CurrencyKitEvents::CURRENCY_ACCESS_CHECK} may grant or deny, then the per-record event. Deny by default: a user
 * the checker does not grant is refused. Console: the operator's default is granted; listeners may deny.
 * Nothing is kept between calls; the user is read from the token storage on every call.
 */
final readonly class CurrencyKitGuard
{
    /**
     * Builds the guard.
     */
    public function __construct(
        private CurrencyKitAccessCheckerInterface $checker,
        private EventDispatcherInterface $dispatcher,
        private bool $allowUnauthenticated = false,
        private ?TokenStorageInterface $tokens = null,
    ) {
    }

    /**
     * The current security user, if any.
     */
    public function user(): ?object
    {
        return $this->tokens?->getToken()?->getUser();
    }

    /**
     * The area decision.
     */
    public function can(CurrencyKitPermission $permission, AccessChannel $channel, ?ActorRef $actor = null): bool
    {
        $user  = $this->user();
        $event = new CurrencyAccessCheckEvent($user, $actor, $channel, $permission->value, $this->areaDefault($permission, $channel, $user));
        $this->dispatcher->dispatch($event, CurrencyKitEvents::CURRENCY_ACCESS_CHECK);

        return $event->isGranted();
    }

    /**
     * Asserts a manual-rate action (area, then the per-record event).
     *
     * @param string $action a ManualRateAccessCheckEvent `ACTION_*` constant
     *
     * @throws AccessDeniedException
     */
    public function assertManualRate(string $action, AccessChannel $channel, ?ActorRef $actor, ?string $manualRateId = null, ?string $currency = null): void
    {
        $permission = $action === ManualRateAccessCheckEvent::ACTION_VIEW ? CurrencyKitPermission::ViewRates : CurrencyKitPermission::ManageManualRates;
        $event      = new ManualRateAccessCheckEvent($this->user(), $actor, $channel, $action, $this->can($permission, $channel, $actor), $manualRateId, $currency);
        $this->dispatcher->dispatch($event, CurrencyKitEvents::MANUAL_RATE_ACCESS_CHECK);
        if (!$event->isGranted()) {
            throw new AccessDeniedException();
        }
    }

    /**
     * Asserts a sync-like action on a source (area, then the per-source event). Applying a change and pruning are
     * management actions; sync, backfill and import need the sync permission.
     *
     * @param string $action a SyncAccessCheckEvent `ACTION_*` constant
     *
     * @throws AccessDeniedException
     */
    public function assertSync(string $action, string $source, AccessChannel $channel, ?ActorRef $actor): void
    {
        $permission = in_array($action, [SyncAccessCheckEvent::ACTION_APPLY_CHANGE, SyncAccessCheckEvent::ACTION_PRUNE], true) ? CurrencyKitPermission::ManageManualRates : CurrencyKitPermission::TriggerSync;
        $event      = new SyncAccessCheckEvent($this->user(), $actor, $channel, $action, $this->can($permission, $channel, $actor), $source);
        $this->dispatcher->dispatch($event, CurrencyKitEvents::SYNC_ACCESS_CHECK);
        if (!$event->isGranted()) {
            throw new AccessDeniedException();
        }
    }

    /**
     * Asserts an area permission.
     *
     * @throws AccessDeniedException
     */
    public function assert(CurrencyKitPermission $permission, AccessChannel $channel, ?ActorRef $actor = null): void
    {
        if (!$this->can($permission, $channel, $actor)) {
            throw new AccessDeniedException();
        }
    }

    /**
     * The decision before listeners.
     */
    private function areaDefault(CurrencyKitPermission $permission, AccessChannel $channel, ?object $user): bool
    {
        if ($user === null) {
            return $channel === AccessChannel::Console || $this->allowUnauthenticated;
        }

        return match ($permission) {
            CurrencyKitPermission::ViewRates         => $this->checker->canViewRates($user),
            CurrencyKitPermission::ManageManualRates => $this->checker->canManageManualRates($user),
            CurrencyKitPermission::TriggerSync       => $this->checker->canTriggerSync($user),
        };
    }
}
