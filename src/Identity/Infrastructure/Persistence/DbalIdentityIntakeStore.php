<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Persistence;

use App\Identity\Application\Port\ContactMessageStore;
use App\Identity\Application\Port\NewsletterStore;
use Doctrine\DBAL\Connection;

/**
 * DIP-003 — the public intake forms (contact, newsletter); the SQL is the handlers' former
 * inline inserts, moved verbatim. The quote request has its own store: both ports name their
 * write `store`, so one class cannot implement the two contracts.
 */
final class DbalIdentityIntakeStore implements ContactMessageStore, NewsletterStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function store(
        string $id,
        string $name,
        string $email,
        ?string $phone,
        ?string $subject,
        string $message,
    ): void {
        $this->connection->executeStatement(
            'INSERT INTO storefront_contact_message (id, name, email, phone, subject, message)
             VALUES (:id, :name, :email, :phone, :subject, :message)',
            [
                'id' => $id,
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'subject' => $subject,
                'message' => $message,
            ],
        );
    }

    public function subscribe(string $id, string $email): void
    {
        $this->connection->executeStatement(
            'INSERT INTO storefront_newsletter_subscriber (id, email)
             VALUES (:id, :email)
             ON CONFLICT (email) DO NOTHING',
            [
                'id' => $id,
                'email' => strtolower(trim($email)),
            ],
        );
    }
}
