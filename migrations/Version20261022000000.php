<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Read-model indexes backing list filters and keyset orderings (ADR-0009)';
    }

    public function up(Schema $schema): void
    {
        // Dedicated B-tree indexes for the new list filters and keyset orderings (PRF-002).
        // No row is inserted/updated/deleted: the *_view tables are rebuilt by projection
        // replay (MIG-002). The DESC columns mirror the keyset ORDER BY so the index is
        // consumed in the query's direction without an extra sort.
        $this->addSql('CREATE INDEX idx_storefront_product_brand ON storefront_product_view (brand)');
        $this->addSql('CREATE INDEX idx_storefront_product_price ON storefront_product_view (price_minor)');
        $this->addSql('CREATE INDEX idx_storefront_product_new ON storefront_product_view (is_new)');
        $this->addSql('CREATE INDEX idx_storefront_product_best ON storefront_product_view (is_best)');
        $this->addSql('CREATE INDEX idx_storefront_product_rating ON storefront_product_view (rating DESC)');

        $this->addSql('CREATE INDEX idx_ordering_orders_customer_placed ON ordering_orders (customer_id, placed_at DESC, id DESC)');
        $this->addSql('CREATE INDEX idx_ordering_orders_source_customer_placed ON ordering_orders (order_source, customer_id, placed_at DESC, id DESC)');

        $this->addSql('CREATE INDEX idx_review_product_created ON storefront_product_review (product_slug, created_at DESC, id DESC)');

        $this->addSql('CREATE INDEX idx_quote_requests_user_created ON identity_quote_requests (user_id, created_at DESC, id DESC)');

        $this->addSql('CREATE INDEX idx_wishlist_customer_created ON storefront_wishlist_item (customer_id, created_at DESC)');

        $this->addSql('CREATE INDEX idx_customer_profile_role_status ON customer_profile (role, status)');
        $this->addSql('CREATE INDEX idx_customer_profile_name ON customer_profile (name)');
        $this->addSql('CREATE INDEX idx_customer_profile_email ON customer_profile (email)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_customer_profile_email');
        $this->addSql('DROP INDEX idx_customer_profile_name');
        $this->addSql('DROP INDEX idx_customer_profile_role_status');
        $this->addSql('DROP INDEX idx_wishlist_customer_created');
        $this->addSql('DROP INDEX idx_quote_requests_user_created');
        $this->addSql('DROP INDEX idx_review_product_created');
        $this->addSql('DROP INDEX idx_ordering_orders_source_customer_placed');
        $this->addSql('DROP INDEX idx_ordering_orders_customer_placed');
        $this->addSql('DROP INDEX idx_storefront_product_rating');
        $this->addSql('DROP INDEX idx_storefront_product_best');
        $this->addSql('DROP INDEX idx_storefront_product_new');
        $this->addSql('DROP INDEX idx_storefront_product_price');
        $this->addSql('DROP INDEX idx_storefront_product_brand');
    }
}
