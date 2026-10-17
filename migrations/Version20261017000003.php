<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Storefront dynamic support tables: contact messages, newsletter, reviews, customer profile and addresses';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE storefront_contact_message (
            id UUID NOT NULL,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            phone VARCHAR(50),
            subject VARCHAR(255),
            message TEXT NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id)
        )');

        $this->addSql('CREATE TABLE storefront_newsletter_subscriber (
            id UUID NOT NULL,
            email VARCHAR(255) NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            CONSTRAINT uniq_newsletter_email UNIQUE (email)
        )');

        $this->addSql('CREATE TABLE storefront_product_review (
            id UUID NOT NULL,
            product_slug VARCHAR(255) NOT NULL,
            author_name VARCHAR(255) NOT NULL,
            rating SMALLINT NOT NULL,
            comment TEXT NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX idx_review_product_slug ON storefront_product_review (product_slug)');

        $this->addSql('CREATE TABLE customer_profile (
            id UUID NOT NULL,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            phone VARCHAR(50) DEFAULT \'\',
            account_type VARCHAR(50) NOT NULL DEFAULT \'particulier\',
            company VARCHAR(255),
            mf VARCHAR(255),
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id)
        )');

        $this->addSql('CREATE TABLE customer_address (
            id UUID NOT NULL,
            customer_id UUID NOT NULL,
            name VARCHAR(255) NOT NULL,
            phone VARCHAR(50) NOT NULL,
            street VARCHAR(255) NOT NULL,
            city VARCHAR(100) NOT NULL,
            governorate VARCHAR(100) NOT NULL,
            zip VARCHAR(20) NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX idx_address_customer_id ON customer_address (customer_id)');

        // Seed some initial reviews for top products
        $sampleReviews = [
            ['baignoire-d-code', 'Sami B.', 5, 'Très belle baignoire, installation facile et confort parfait.'],
            ['baignoire-d-code', 'Monia K.', 5, 'Matériau solide et design épuré, livraison rapide à Sousse.'],
            ['wc-suspendu-rimless', 'Karim M.', 5, 'Système Geberit irréprochable, silencieux et très hygiénique.'],
            ['mitigeur-lavabo-chrome', 'Ahmed T.', 4, 'Très bon rapport qualité-prix. Marque Sopal toujours au rendez-vous.'],
            ['radiateur-acier-t22', 'Leila J.', 4, 'Chauffe rapidement et uniformément, très satisfait.'],
        ];

        foreach ($sampleReviews as $r) {
            $this->addSql('INSERT INTO storefront_product_review (id, product_slug, author_name, rating, comment)
                VALUES (gen_random_uuid(), :slug, :author, :rating, :comment)', [
                'slug' => $r[0],
                'author' => $r[1],
                'rating' => $r[2],
                'comment' => $r[3],
            ]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE customer_address');
        $this->addSql('DROP TABLE customer_profile');
        $this->addSql('DROP TABLE storefront_product_review');
        $this->addSql('DROP TABLE storefront_newsletter_subscriber');
        $this->addSql('DROP TABLE storefront_contact_message');
    }
}
