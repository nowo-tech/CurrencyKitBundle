<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Symfony\Security;

use Nowo\CurrencyKitBundle\Domain\Port\CurrencyKitAccessCheckerInterface;

/**
 * Grants every area: selectable only with `security.allow_unauthenticated: true` (demos and development).
 */
final readonly class AllowAllCurrencyKitAccessChecker implements CurrencyKitAccessCheckerInterface
{
    /**
     * {@inheritDoc}
     */
    public function canViewRates(object $user): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function canManageManualRates(object $user): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function canTriggerSync(object $user): bool
    {
        return true;
    }
}
