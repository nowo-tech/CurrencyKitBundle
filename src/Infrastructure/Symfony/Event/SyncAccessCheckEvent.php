<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Symfony\Event;

use Nowo\CurrencyKitBundle\Domain\Manual\ActorRef;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Security\AccessChannel;

/**
 * Per source access check of a sync-like action ({@see CurrencyKitEvents::SYNC_ACCESS_CHECK}). The default is the area
 * decision.
 */
final class SyncAccessCheckEvent extends AbstractAccessCheckEvent
{
    public const ACTION_SYNC         = 'sync';
    public const ACTION_BACKFILL     = 'backfill';
    public const ACTION_IMPORT       = 'import';
    public const ACTION_APPLY_CHANGE = 'apply_change';
    public const ACTION_PRUNE        = 'prune';

    /**
     * Builds the event.
     */
    public function __construct(
        ?object $user,
        ?ActorRef $actor,
        AccessChannel $channel,
        string $action,
        bool $defaultGranted,
        private readonly string $source,
    ) {
        parent::__construct($user, $actor, $channel, $action, $defaultGranted);
    }

    /**
     * The source key.
     */
    public function getSource(): string
    {
        return $this->source;
    }
}
