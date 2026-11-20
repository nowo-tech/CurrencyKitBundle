<?php

declare(strict_types=1);

namespace Nowo\CurrencyKitBundle\Infrastructure\Symfony\Security;

use Nowo\CurrencyKitBundle\Domain\Exception\InvalidValueException;
use Nowo\CurrencyKitBundle\Domain\Manual\ActorRef;
use Nowo\CurrencyKitBundle\Domain\Port\CurrencyKitActorResolverInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The default actor: the security user's identifier, read from the token storage on every call (nothing kept), null
 * without SecurityBundle, without a user, or when the identifier is not a valid actor ref.
 */
final readonly class SecurityActorResolver implements CurrencyKitActorResolverInterface
{
    /**
     * Builds the resolver.
     */
    public function __construct(private ?TokenStorageInterface $tokens = null)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function current(): ?ActorRef
    {
        $user = $this->tokens?->getToken()?->getUser();
        if (!$user instanceof UserInterface) {
            return null;
        }
        try {
            return new ActorRef($user->getUserIdentifier());
        } catch (InvalidValueException) {
            return null;
        }
    }
}
