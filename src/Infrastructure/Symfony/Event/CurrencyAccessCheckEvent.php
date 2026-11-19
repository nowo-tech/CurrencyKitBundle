<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Symfony\Event;

use Nowo\CurrencyKitBundle\Infrastructure\Symfony\Security\CurrencyKitPermission;

/**
 * Area access check ({@see CurrencyKitEvents::CURRENCY_ACCESS_CHECK}): the action is a
 * {@see CurrencyKitPermission} value. A host that grants by
 * permission sets (not roles) listens here.
 */
final class CurrencyAccessCheckEvent extends AbstractAccessCheckEvent
{
}
