<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Symfony\Security;

use Nowo\CurrencyKitBundle\Domain\Port\CurrencyKitAccessCheckerInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_intersect;

/**
 * The default area checker (REQ-UI-002): the user must hold one of `security.access_roles` (role hierarchy applied)
 * and then `security.dashboard_roles` (empty: `access_roles`) to view, `security.manage_roles` to manage manual rates
 * and trigger syncs. Anything that is not a Symfony user is denied.
 */
final readonly class ConfigurableCurrencyKitAccessChecker implements CurrencyKitAccessCheckerInterface
{
    /**
     * @param list<string> $accessRoles
     * @param list<string> $dashboardRoles
     * @param list<string> $manageRoles
     */
    public function __construct(
        private array $accessRoles,
        private array $dashboardRoles,
        private array $manageRoles,
        private ?RoleHierarchyInterface $roleHierarchy = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function canViewRates(object $user): bool
    {
        return $this->holds($user, $this->dashboardRoles === [] ? $this->accessRoles : $this->dashboardRoles);
    }

    /**
     * {@inheritDoc}
     */
    public function canManageManualRates(object $user): bool
    {
        return $this->holds($user, $this->manageRoles);
    }

    /**
     * {@inheritDoc}
     */
    public function canTriggerSync(object $user): bool
    {
        return $this->holds($user, $this->manageRoles);
    }

    /**
     * Whether the user holds an access role and one of `$roles`.
     *
     * @param list<string> $roles
     */
    private function holds(object $user, array $roles): bool
    {
        if (!$user instanceof UserInterface) {
            return false;
        }
        $held = $user->getRoles();
        if ($this->roleHierarchy instanceof RoleHierarchyInterface) {
            $held = $this->roleHierarchy->getReachableRoleNames($held);
        }

        return array_intersect($held, $this->accessRoles) !== [] && array_intersect($held, $roles) !== [];
    }
}
