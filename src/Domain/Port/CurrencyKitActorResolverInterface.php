<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Domain\Port;

use Nowo\CurrencyKitBundle\Domain\Manual\ActorRef;

/**
 * Who is acting now (contract section 7.4): the security user's identifier by default, null in the console unless a
 * command passes `--actor`. Re-resolved on every attempt of a write unit.
 */
interface CurrencyKitActorResolverInterface
{
    /**
     * The current actor, or null.
     */
    public function current(): ?ActorRef;
}
