<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Symfony\Event;

use Nowo\CurrencyKitBundle\Domain\Manual\ActorRef;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Security\AccessChannel;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Base of the access-check events: the subject (a security user, or null), the actor ref, the channel, the action and
 * the default decision. `grant()` / `deny()` override it; a deny sticks (a later grant is ignored), so one listener
 * can never re-open what another closed.
 */
abstract class AbstractAccessCheckEvent extends Event
{
    private bool $granted = false;

    private bool $denied = false;

    /**
     * Builds the event.
     */
    public function __construct(
        private readonly ?object $user,
        private readonly ?ActorRef $actor,
        private readonly AccessChannel $channel,
        private readonly string $action,
        private readonly bool $defaultGranted,
    ) {
    }

    /**
     * The security user (null in the console or for a guest).
     */
    public function getUser(): ?object
    {
        return $this->user;
    }

    /**
     * The actor reference (the user identifier, or the console `--actor`).
     */
    public function getActor(): ?ActorRef
    {
        return $this->actor;
    }

    /**
     * Web or console.
     */
    public function getChannel(): AccessChannel
    {
        return $this->channel;
    }

    /**
     * What is being decided (the subclass's `ACTION_*` constants).
     */
    public function getAction(): string
    {
        return $this->action;
    }

    /**
     * The decision without listeners.
     */
    public function isDefaultGranted(): bool
    {
        return $this->defaultGranted;
    }

    /**
     * Grants (ignored after a deny).
     */
    public function grant(): void
    {
        if ($this->denied) {
            return;
        }
        // @igor-ignore - request-scoped event object; not a shared worker service.
        $this->granted = true;
    }

    /**
     * Denies (sticks).
     */
    public function deny(): void
    {
        // @igor-ignore - request-scoped event object; not a shared worker service.
        $this->denied = true;
        // @igor-ignore - request-scoped event object; not a shared worker service.
        $this->granted = false;
    }

    /**
     * Whether a listener denied.
     */
    public function isDenied(): bool
    {
        return $this->denied;
    }

    /**
     * The final decision: denied ⇒ false, granted ⇒ true, else the default.
     */
    public function isGranted(): bool
    {
        return !$this->denied && ($this->granted || $this->defaultGranted);
    }
}
