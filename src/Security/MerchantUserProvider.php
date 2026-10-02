<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * @implements UserProviderInterface<MerchantUser>
 */
class MerchantUserProvider implements UserProviderInterface
{
    public function __construct(private readonly MerchantIdentityProvider $identities)
    {
    }

    public function loadUserByIdentifier(string $identifier): MerchantUser
    {
        $identity = $this->identities->findById($identifier);

        if (null === $identity || !$identity->active) {
            throw new UserNotFoundException();
        }

        return new MerchantUser($identity->id, $identity->name);
    }

    public function refreshUser(UserInterface $user): MerchantUser
    {
        if (!$user instanceof MerchantUser) {
            throw new UnsupportedUserException(\sprintf('Unsupported user class "%s".', $user::class));
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return MerchantUser::class === $class;
    }
}
