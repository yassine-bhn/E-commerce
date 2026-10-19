<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Storefront FAQ entries';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE storefront_faq_entry (
            id UUID NOT NULL,
            category VARCHAR(120) NOT NULL,
            question TEXT NOT NULL,
            answer TEXT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX idx_storefront_faq_entry_sort_order ON storefront_faq_entry (sort_order)');

        foreach (self::ENTRIES as $order => $entry) {
            $this->addSql(
                'INSERT INTO storefront_faq_entry (id, category, question, answer, sort_order)
                 VALUES (:id, :category, :question, :answer, :sort_order)',
                [
                    'id' => $entry[0],
                    'category' => $entry[1],
                    'question' => $entry[2],
                    'answer' => $entry[3],
                    'sort_order' => $order + 1,
                ],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE storefront_faq_entry');
    }

    /**
     * FAQ content for a Tunisian building-materials / plumbing store.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private const array ENTRIES = [
        [
            '01929312-9000-7000-8000-000000000001',
            'Livraison',
            'Livrez-vous partout en Tunisie ?',
            'Oui, nous livrons dans les 24 gouvernorats de Tunisie. Votre commande est acheminée en 24 à 72 heures selon la région, et la livraison est offerte dès 300 DT d\'achat.',
        ],
        [
            '01929312-9000-7000-8000-000000000002',
            'Livraison',
            'Quels sont les délais de livraison ?',
            'Les commandes confirmées avant 13h sont expédiées le jour même. Comptez 24 à 48 heures pour le Grand Tunis et 48 à 72 heures pour le reste du pays.',
        ],
        [
            '01929312-9000-7000-8000-000000000003',
            'Paiement',
            'Quels moyens de paiement acceptez-vous ?',
            'Nous acceptons le paiement à la livraison en espèces, la carte bancaire (Visa, Mastercard, D17) et le virement bancaire pour les commandes professionnelles. Une facture est émise pour chaque commande.',
        ],
        [
            '01929312-9000-7000-8000-000000000004',
            'Garantie',
            'Quelle est la garantie de vos produits ?',
            'Tous nos produits bénéficient de la garantie fabricant, de 1 à 5 ans selon les références. Les sanitaires et la robinetterie sont garantis 2 ans pièces et main-d\'œuvre, sur présentation de la facture.',
        ],
        [
            '01929312-9000-7000-8000-000000000005',
            'Retours',
            'Puis-je retourner un produit ?',
            'Vous disposez de 14 jours pour retourner un produit non utilisé, dans son emballage d\'origine et sur présentation de la facture. Les frais de retour restent à votre charge, sauf en cas d\'erreur de notre part.',
        ],
        [
            '01929312-9000-7000-8000-000000000006',
            'Devis',
            'Comment obtenir un devis ?',
            'Remplissez le formulaire de demande de devis en ligne ou envoyez-nous votre liste par e-mail. Notre équipe commerciale vous répond sous 24 heures ouvrables avec des tarifs adaptés aux professionnels.',
        ],
        [
            '01929312-9000-7000-8000-000000000007',
            'Disponibilité',
            'Comment savoir si un produit est en stock ?',
            'La disponibilité affichée sur la fiche produit est mise à jour en temps réel. En cas de rupture, contactez-nous : nous vous indiquons la date de réapprovisionnement et pouvons réserver votre article.',
        ],
        [
            '01929312-9000-7000-8000-000000000008',
            'Installation',
            'Proposez-vous l\'installation ou la pose ?',
            'Nous ne réalisons pas la pose, mais nous travaillons avec un réseau de plombiers agréés dans les principales villes. Nous pouvons vous mettre en relation avec un artisan de confiance.',
        ],
    ];
}
