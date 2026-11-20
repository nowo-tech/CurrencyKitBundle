<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Tests\Unit\Infrastructure;

use Nowo\CurrencyKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CurrencyKitBundle\Domain\Manual\ActorRef;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Event\CurrencyAccessCheckEvent;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Event\CurrencyKitEvents;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Event\ManualRateAccessCheckEvent;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Event\SyncAccessCheckEvent;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Security\AccessChannel;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Security\AllowAllCurrencyKitAccessChecker;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Security\ConfigurableCurrencyKitAccessChecker;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Security\CurrencyKitGuard;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Security\CurrencyKitPermission;
use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Security\SecurityActorResolver;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Role\RoleHierarchy;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function str_repeat;

final class SecurityTest extends TestCase
{
    public function testConfigurableChecker(): void
    {
        $checker = new ConfigurableCurrencyKitAccessChecker(['ROLE_ADMIN'], [], ['ROLE_ADMIN'], new RoleHierarchy(['ROLE_SUPER' => ['ROLE_ADMIN']]));
        $admin   = new InMemoryUser('admin', null, ['ROLE_ADMIN']);
        $super   = new InMemoryUser('super', null, ['ROLE_SUPER']);
        $viewer  = new InMemoryUser('viewer', null, ['ROLE_VIEWER']);
        self::assertTrue($checker->canViewRates($admin));
        self::assertTrue($checker->canManageManualRates($super), 'role hierarchy');
        self::assertTrue($checker->canTriggerSync($admin));
        self::assertFalse($checker->canViewRates($viewer), 'deny by default');
        self::assertFalse($checker->canViewRates(new stdClass()));

        $split       = new ConfigurableCurrencyKitAccessChecker(['ROLE_STAFF'], ['ROLE_VIEWER'], ['ROLE_ADMIN']);
        $staffViewer = new InMemoryUser('v', null, ['ROLE_STAFF', 'ROLE_VIEWER']);
        self::assertTrue($split->canViewRates($staffViewer));
        self::assertFalse($split->canManageManualRates($staffViewer));
        self::assertFalse($split->canViewRates($viewer), 'access_roles is required too');

        $all = new AllowAllCurrencyKitAccessChecker();
        self::assertTrue($all->canViewRates(new stdClass()) && $all->canManageManualRates(new stdClass()) && $all->canTriggerSync(new stdClass()));
    }

    public function testGuardOnTheWebAndInTheConsole(): void
    {
        $dispatcher = new EventDispatcher();
        $tokens     = new TokenStorage();
        $checker    = new ConfigurableCurrencyKitAccessChecker(['ROLE_ADMIN'], [], ['ROLE_ADMIN']);
        $guard      = new CurrencyKitGuard($checker, $dispatcher, false, $tokens);

        self::assertFalse($guard->can(CurrencyKitPermission::ViewRates, AccessChannel::Http), 'guest on the web');
        self::assertTrue($guard->can(CurrencyKitPermission::ManageManualRates, AccessChannel::Console), 'console operator');
        self::assertTrue((new CurrencyKitGuard($checker, $dispatcher, true))->can(CurrencyKitPermission::ViewRates, AccessChannel::Http), 'allow_unauthenticated');
        self::assertNull((new CurrencyKitGuard($checker, $dispatcher))->user());

        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('viewer', null, ['ROLE_VIEWER']), 'main', ['ROLE_VIEWER']));
        self::assertFalse($guard->can(CurrencyKitPermission::TriggerSync, AccessChannel::Http));
        self::assertFalse($guard->can(CurrencyKitPermission::TriggerSync, AccessChannel::Console), 'a signed-in user is checked even in the console');

        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('admin', null, ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']));
        $guard->assert(CurrencyKitPermission::ViewRates, AccessChannel::Http);
        $guard->assertManualRate(ManualRateAccessCheckEvent::ACTION_EDIT, AccessChannel::Http, new ActorRef('admin'), 'id-1', 'USD');
        $guard->assertSync(SyncAccessCheckEvent::ACTION_PRUNE, 'ecb', AccessChannel::Http, null);

        // Permission sets instead of roles: a listener on the area event.
        $seen = [];
        $dispatcher->addListener(CurrencyKitEvents::CURRENCY_ACCESS_CHECK, static function (CurrencyAccessCheckEvent $event) use (&$seen): void {
            $seen[] = [$event->getAction(), $event->getChannel(), $event->isDefaultGranted(), $event->getUser() !== null, $event->getActor()?->value];
            if ($event->getAction() === CurrencyKitPermission::TriggerSync->value) {
                $event->deny();
                $event->grant();
            }
        });
        $dispatcher->addListener(CurrencyKitEvents::MANUAL_RATE_ACCESS_CHECK, static function (ManualRateAccessCheckEvent $event): void {
            if ($event->getCurrency() === 'GBP' && $event->getManualRateId() === null) {
                $event->deny();
            }
        });
        $dispatcher->addListener(CurrencyKitEvents::SYNC_ACCESS_CHECK, static function (SyncAccessCheckEvent $event): void {
            if ($event->getSource() === 'mirror') {
                $event->grant();
            }
        });
        self::assertFalse($guard->can(CurrencyKitPermission::TriggerSync, AccessChannel::Http, new ActorRef('admin')), 'a deny sticks');
        self::assertSame(['trigger_sync', AccessChannel::Http, true, true, 'admin'], $seen[0]);
        foreach ([
            static fn () => $guard->assert(CurrencyKitPermission::TriggerSync, AccessChannel::Http),
            static fn () => $guard->assertManualRate(ManualRateAccessCheckEvent::ACTION_CREATE, AccessChannel::Http, null, null, 'GBP'),
            static fn () => $guard->assertSync(SyncAccessCheckEvent::ACTION_SYNC, 'ecb', AccessChannel::Http, null),
        ] as $call) {
            try {
                $call();
                self::fail('granted');
            } catch (AccessDeniedException) {
            }
        }
        $event = new SyncAccessCheckEvent(null, null, AccessChannel::Http, SyncAccessCheckEvent::ACTION_SYNC, false, 'mirror');
        $event->grant();
        self::assertTrue($event->isGranted());
        self::assertFalse($event->isDenied());
    }

    public function testActorResolver(): void
    {
        $tokens = new TokenStorage();
        self::assertNull((new SecurityActorResolver())->current());
        $resolver = new SecurityActorResolver($tokens);
        self::assertNull($resolver->current());
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('alice', null), 'main'));
        $alice = (new SecurityActorResolver($tokens))->current();
        self::assertNotNull($alice);
        self::assertSame('alice', $alice->value);
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser(str_repeat('a', 200), null), 'main'));
        self::assertNull($resolver->current(), 'an identifier that is not a valid actor ref');
    }
}
