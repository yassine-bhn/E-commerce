<?php

declare(strict_types=1);

namespace App\Identity\Application\CommandHandler;

use App\Identity\Application\Command\RequestPasswordReset;
use App\Identity\Application\Port\PasswordResetStore;
use App\Identity\Application\Port\PasswordResetTokenStore;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;

#[AsMessageHandler(bus: 'command.bus')]
final class RequestPasswordResetHandler
{
    public function __construct(
        private PasswordResetStore $passwords,
        private PasswordResetTokenStore $tokens,
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
        $customer = $this->passwords->customerByEmail($command->email);

        if (null === $customer) {
            return;
        }

        $customerId = $customer['id'];
        $email = $customer['email'];

        $this->tokens->invalidateActive($customerId);

        $token = bin2hex(random_bytes(32));
        $this->tokens->issue($customerId, hash('sha256', $token));

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
