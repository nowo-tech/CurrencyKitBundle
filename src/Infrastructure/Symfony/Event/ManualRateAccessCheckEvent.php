<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Symfony\Event;

use Nowo\CurrencyKitBundle\Domain\Manual\ActorRef;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Security\AccessChannel;

/**
 * Per manual rate access check ({@see CurrencyKitEvents::MANUAL_RATE_ACCESS_CHECK}). The default is the area decision.
 */
final class ManualRateAccessCheckEvent extends AbstractAccessCheckEvent
{
    public const ACTION_VIEW   = 'view';
    public const ACTION_CREATE = 'create';
    public const ACTION_EDIT   = 'edit';
    public const ACTION_END    = 'end';

    /**
     * @param string|null $manualRateId null when creating
     */
    public function __construct(
        ?object $user,
        ?ActorRef $actor,
        AccessChannel $channel,
        string $action,
        bool $defaultGranted,
        private readonly ?string $manualRateId,
        private readonly ?string $currency,
    ) {
        parent::__construct($user, $actor, $channel, $action, $defaultGranted);
    }

    /**
     * The manual rate's id (null when creating).
     */
    public function getManualRateId(): ?string
    {
        return $this->manualRateId;
    }

    /**
     * The currency code, when known.
     */
    public function getCurrency(): ?string
    {
        return $this->currency;
    }
}
