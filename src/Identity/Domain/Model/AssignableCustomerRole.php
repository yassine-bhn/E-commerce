<?php

declare(strict_types=1);

namespace App\Identity\Domain\Model;

/**
 * R11 — the exhaustive set of roles the dashboard may assign (spec Q6 default: ROLE_ADMIN stays
 * unassignable even though it guards /api/v1/admin).
 */
final class AssignableCustomerRole
{
    public const ROLE_USER = 'ROLE_USER';
    public const ROLE_SUPER_ADMIN = 'ROLE_SUPER_ADMIN';

    /** @var list<string> */
    public const NAMES = [self::ROLE_USER, self::ROLE_SUPER_ADMIN];

    private function __construct(private readonly string $name)
    {
    }

    public static function fromString(string $role): self
    {
        if (!in_array($role, self::NAMES, true)) {
            throw RoleNotAssignableException::forRole($role);
        }

        return new self($role);
    }

    /**
     * @return non-empty-string
     */
    public static function sentence(): string
    {
        return implode(' and ', self::NAMES);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isSuperAdmin(): bool
    {
        return self::ROLE_SUPER_ADMIN === $this->name;
    }

    public function equals(self $other): bool
    {
        return $this->name === $other->name;
    }
}
