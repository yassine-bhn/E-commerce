<?php

declare(strict_types=1);

namespace App\Identity\Application\CommandHandler;

use App\Identity\Application\Command\ResetPassword;
use App\Identity\Application\Port\PasswordResetStore;
use App\Identity\Application\Port\PasswordResetTokenStore;
use App\Identity\Domain\Model\InvalidPasswordResetTokenException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class ResetPasswordHandler
{
    public function __construct(
        private PasswordResetTokenStore $tokens,
        private PasswordResetStore $passwords,
    ) {
    }

    public function __invoke(ResetPassword $command): void
    {
        $customerId = $this->tokens->consume(hash('sha256', $command->token));

        if (null === $customerId) {
            throw InvalidPasswordResetTokenException::invalid();
        }

        $this->passwords->updatePassword($customerId, password_hash($command->newPassword, PASSWORD_BCRYPT));
    }
}
