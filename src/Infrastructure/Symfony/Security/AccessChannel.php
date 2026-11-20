<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Symfony\Security;

/**
 * Where an access decision is taken.
 */
enum AccessChannel: string
{
    /** A web request (the 0.2 UI, a host controller): deny by default, a user is required. */
    case Http = 'http';

    /**
     * A console command run by an operator with shell access to the instance. The default decision is granted (the
     * operator can already read the database credentials); a `*_ACCESS_CHECK` listener may deny it.
     */
    case Console = 'console';
}
