<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * spec-run-15 R21 — one-time TND unit conversion: whole dinars → millimes (×1000).
 *
 * Every monetary value written before this migration is a whole dinar. This data-only migration
 * multiplies it by 1000 exactly once so the stored integer becomes millimes (TND has three
 * decimals). Doctrine's migration table prevents a second run. The historical product seed
 * (Version20261017000002 + fixtures/zidane-storefront-catalog.json) intentionally stays whole-dinar
 * and is converted here on a fresh install; the same applies to `fixtures/seed-test-products.sql`
 * only when it is re-run against a database that has not executed this migration.
 *
 * This is a unit conversion, not a projection rebuild: it rewrites values in place, drops no row
 * and changes no sign (R33 allowlists this file for that reason).
 *
 * `jsonb_exists(elem, 'price')` is the semantic equivalent of the previewed `elem ? 'price'`:
 * DBAL rewrites a bare `?` operator into a positional parameter, so the SQL cannot carry it.
 *
 * Down is best effort: after the conversion a value may carry millimes, which whole dinars cannot
 * represent, so the integer division truncates sub-dinar amounts.
 */
final class Version20261029000003 extends AbstractMigration
{
    private const string SCALE_VARIANTS_UP = <<<'SQL'
        UPDATE storefront_product_view
        SET variants = (
            SELECT COALESCE(
                jsonb_agg(
                    CASE
                        WHEN jsonb_exists(elem, 'price') AND (elem->>'price') ~ '^[0-9]+$'
                            THEN jsonb_set(elem, '{price}', to_jsonb(((elem->>'price')::bigint * 1000)))
                        ELSE elem
                    END
                ),
                '[]'::jsonb
            )
            FROM jsonb_array_elements(variants::jsonb) AS elem
        )::json
        WHERE jsonb_array_length(variants::jsonb) > 0
        SQL;

    private const string SCALE_VARIANTS_DOWN = <<<'SQL'
        UPDATE storefront_product_view
        SET variants = (
            SELECT COALESCE(
                jsonb_agg(
                    CASE
                        WHEN jsonb_exists(elem, 'price') AND (elem->>'price') ~ '^[0-9]+$'
                            THEN jsonb_set(elem, '{price}', to_jsonb(((elem->>'price')::bigint / 1000)))
                        ELSE elem
                    END
                ),
                '[]'::jsonb
            )
            FROM jsonb_array_elements(variants::jsonb) AS elem
        )::json
        WHERE jsonb_array_length(variants::jsonb) > 0
        SQL;

    public function getDescription(): string
    {
        return 'One-time TND unit conversion: whole dinars to millimes (spec-run-15 R21)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE catalog_products SET price_minor = price_minor * 1000');
        $this->addSql('UPDATE catalog_product_view SET price_minor = price_minor * 1000');
        $this->addSql('UPDATE storefront_product_view SET price_minor = price_minor * 1000');
        $this->addSql('UPDATE storefront_product_view SET old_price_minor = old_price_minor * 1000 WHERE old_price_minor IS NOT NULL');
        $this->addSql(self::SCALE_VARIANTS_UP);
        $this->addSql('UPDATE storefront_cart_item SET unit_price_minor = unit_price_minor * 1000');
        $this->addSql('UPDATE ordering_orders
            SET subtotal_minor = subtotal_minor * 1000,
                discount_minor = discount_minor * 1000,
                delivery_minor = delivery_minor * 1000,
                total_minor = total_minor * 1000');
        $this->addSql('UPDATE ordering_order_lines SET unit_price_minor = unit_price_minor * 1000');
        $this->addSql('UPDATE payment SET amount_minor = amount_minor * 1000');
        $this->addSql('UPDATE payment_ledger SET amount_minor = amount_minor * 1000');
        $this->addSql('UPDATE storefront_shipping_policy
            SET delivery_fee_minor = delivery_fee_minor * 1000,
                free_delivery_threshold_minor = free_delivery_threshold_minor * 1000');
    }

    public function down(Schema $schema): void
    {
        // Best effort: sub-millime amounts cannot be represented after the conversion, so the
        // integer division truncates them.
        $this->addSql('UPDATE catalog_products SET price_minor = price_minor / 1000');
        $this->addSql('UPDATE catalog_product_view SET price_minor = price_minor / 1000');
        $this->addSql('UPDATE storefront_product_view SET price_minor = price_minor / 1000');
        $this->addSql('UPDATE storefront_product_view SET old_price_minor = old_price_minor / 1000 WHERE old_price_minor IS NOT NULL');
        $this->addSql(self::SCALE_VARIANTS_DOWN);
        $this->addSql('UPDATE storefront_cart_item SET unit_price_minor = unit_price_minor / 1000');
        $this->addSql('UPDATE ordering_orders
            SET subtotal_minor = subtotal_minor / 1000,
                discount_minor = discount_minor / 1000,
                delivery_minor = delivery_minor / 1000,
                total_minor = total_minor / 1000');
        $this->addSql('UPDATE ordering_order_lines SET unit_price_minor = unit_price_minor / 1000');
        $this->addSql('UPDATE payment SET amount_minor = amount_minor / 1000');
        $this->addSql('UPDATE payment_ledger SET amount_minor = amount_minor / 1000');
        $this->addSql('UPDATE storefront_shipping_policy
            SET delivery_fee_minor = delivery_fee_minor / 1000,
                free_delivery_threshold_minor = free_delivery_threshold_minor / 1000');
    }
}
