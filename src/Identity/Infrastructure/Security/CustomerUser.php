<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class CustomerUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    public function __construct(
        private readonly string $id,
        private readonly string $email,
        private readonly ?string $passwordHash,
        private readonly string $role,
        private readonly string $status,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getRoles(): array
    {
        return array_values(array_unique([$this->role, 'ROLE_USER']));
    }

    public function eraseCredentials(): void
    {
    }

    /**
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        return '' !== $this->email ? $this->email : 'user@unknown';
    }

    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }
}
