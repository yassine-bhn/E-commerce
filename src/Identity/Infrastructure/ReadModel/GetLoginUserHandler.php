<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\ReadModel;

use App\Identity\Application\Query\GetLoginUser;
use App\Identity\Infrastructure\Security\CustomerProfileUserProvider;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * solid Task 4.2 — the account behind the verified Google address, loaded through the same
 * provider the password login uses.
 */
#[AsMessageHandler(bus: 'query.bus')]
final class GetLoginUserHandler
{
    public function __construct(private CustomerProfileUserProvider $users)
    {
    }

    public function __invoke(GetLoginUser $query): UserInterface
    {
        return $this->users->loadUserByIdentifier($query->email);
    }
}
