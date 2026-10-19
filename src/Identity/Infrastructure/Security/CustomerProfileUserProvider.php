<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * @implements UserProviderInterface<CustomerUser>
 */
final readonly class CustomerProfileUserProvider implements UserProviderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        return $this->loadUser($identifier);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof CustomerUser) {
            throw new UnsupportedUserException(sprintf('Unsupported user class "%s".', $user::class));
        }

        return $this->loadUser($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return $class === CustomerUser::class || is_subclass_of($class, CustomerUser::class);
    }

    private function loadUser(string $identifier): CustomerUser
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, email, password_hash, role, status FROM customer_profile WHERE lower(email) = lower(:email)',
            ['email' => $identifier],
        );

        if ($row === false || ($row['status'] ?? '') === 'banned') {
            throw new UserNotFoundException(sprintf('Customer "%s" was not found.', $identifier));
        }

        $id = $row['id'] ?? null;
        $email = $row['email'] ?? null;
        $role = $row['role'] ?? null;
        $status = $row['status'] ?? null;

        if (!is_string($id) || !is_string($email) || !is_string($role) || !is_string($status)) {
            throw new UserNotFoundException(sprintf('Customer "%s" was not found.', $identifier));
        }

        $passwordHash = $row['password_hash'] ?? null;

        return new CustomerUser(
            $id,
            $email,
            is_string($passwordHash) ? $passwordHash : null,
            $role,
            $status,
        );
    }
}
