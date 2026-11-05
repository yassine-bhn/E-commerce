<?php

declare(strict_types=1);

namespace App\Identity\Application\CommandHandler;

use App\Identity\Application\Command\RequestPasswordReset;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final class RequestPasswordResetHandler
{
    public function __construct(
        private Connection $connection,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        #[Autowire('%env(STOREFRONT_APP_URL)%')]
        private string $storefrontAppUrl,
        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
    }

    public function __invoke(RequestPasswordReset $command): void
    {
        $customer = $this->connection->fetchAssociative(
            'SELECT id, email FROM customer_profile WHERE lower(email) = lower(:email) LIMIT 1',
            ['email' => $command->email],
        );

        if (false === $customer) {
            return;
        }

        $customerId = (string) $customer['id'];
        $email = (string) $customer['email'];

        $this->connection->executeStatement(
            'UPDATE customer_password_reset_token SET used_at = CURRENT_TIMESTAMP
             WHERE customer_id = :customer AND used_at IS NULL',
            ['customer' => $customerId],
        );

        $token = bin2hex(random_bytes(32));

        $this->connection->executeStatement(
            'INSERT INTO customer_password_reset_token (id, customer_id, token_hash, expires_at, created_at)
             VALUES (:id, :customer, :token_hash, CURRENT_TIMESTAMP + INTERVAL \'1 hour\', CURRENT_TIMESTAMP)',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'customer' => $customerId,
                'token_hash' => hash('sha256', $token),
            ],
        );

        $resetUrl = sprintf('%s/reset-password?token=%s', rtrim($this->storefrontAppUrl, '/'), $token);

        $message = (new Email())
            ->from('no-reply@zidaine.tn')
            ->to($email)
            ->subject('Réinitialisation de votre mot de passe')
            ->text(sprintf("Pour réinitialiser votre mot de passe, cliquez sur le lien suivant :\n\n%s\n\nCe lien expire dans 1 heure.", $resetUrl))
            ->html(sprintf('<p>Pour réinitialiser votre mot de passe, cliquez sur le lien suivant :</p><p><a href="%s">Réinitialiser mon mot de passe</a></p><p>Ce lien expire dans 1 heure.</p>', $resetUrl));

        $this->mailer->send($message);

        if ('prod' !== $this->environment) {
            $this->logger->info('Password reset link generated.', ['url' => $resetUrl]);
        }
    }
}
