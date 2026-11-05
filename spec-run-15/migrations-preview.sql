-- Preview of the three migrations proposed for spec-run-15 v2.
-- Nothing here has been run. Review, then approve (MIG-001).

-- =====================================================================
-- Version20261029000001 — catalog_submission (approval write model)
-- =====================================================================
CREATE TABLE catalog_submission (
    kind VARCHAR(16) NOT NULL,
    slug VARCHAR(128) NOT NULL,
    approval_status VARCHAR(20) NOT NULL DEFAULT 'PENDING_APPROVAL',
    submitted_by VARCHAR(64) DEFAULT NULL,
    submitted_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
    reviewed_by VARCHAR(64) DEFAULT NULL,
    reviewed_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
    rejection_reason TEXT DEFAULT NULL,
    updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW(),
    PRIMARY KEY (kind, slug),
    CONSTRAINT chk_catalog_submission_kind
        CHECK (kind IN ('products', 'categories')),
    CONSTRAINT chk_catalog_submission_status
        CHECK (approval_status IN ('PENDING_APPROVAL', 'APPROVED', 'REJECTED'))
);

CREATE INDEX idx_catalog_submission_status
    ON catalog_submission (kind, approval_status);

-- Down:
-- DROP TABLE catalog_submission;

-- =====================================================================
-- Version20261029000002 — inventory_stock (Inventory-owned balance)
-- =====================================================================
CREATE TABLE inventory_stock (
    product_slug VARCHAR(128) NOT NULL,
    on_hand INT NOT NULL,
    reserved INT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW(),
    PRIMARY KEY (product_slug),
    CONSTRAINT chk_inventory_stock_on_hand CHECK (on_hand >= 0),
    CONSTRAINT chk_inventory_stock_reserved CHECK (reserved >= 0),
    CONSTRAINT chk_inventory_stock_available CHECK (reserved <= on_hand)
);

-- Down:
-- DROP TABLE inventory_stock;

-- =====================================================================
-- Version20261029000003 — one-time TND unit conversion: dinars -> millimes
-- =====================================================================
-- Every existing monetary value is a whole dinar. This migration multiplies
-- it by 1000 exactly once so the stored integer becomes millimes (TND has 3
-- decimals). It is a unit conversion, not a projection rebuild; Doctrine's
-- migration table prevents a second run. The historical product seed
-- (Version20261017000002 + fixtures/zidane-storefront-catalog.json) stays in
-- whole dinars and is converted here on a fresh install.

UPDATE catalog_products
SET price_minor = price_minor * 1000;

UPDATE catalog_product_view
SET price_minor = price_minor * 1000;

UPDATE storefront_product_view
SET price_minor = price_minor * 1000;

UPDATE storefront_product_view
SET old_price_minor = old_price_minor * 1000
WHERE old_price_minor IS NOT NULL;

UPDATE storefront_product_view
SET variants = (
    SELECT COALESCE(
        jsonb_agg(
            CASE
                WHEN elem ? 'price' AND (elem->>'price') ~ '^[0-9]+$'
                    THEN jsonb_set(elem, '{price}', to_jsonb(((elem->>'price')::bigint * 1000)))
                ELSE elem
            END
        ),
        '[]'::jsonb
    )
    FROM jsonb_array_elements(variants::jsonb) AS elem
)::json
WHERE jsonb_array_length(variants::jsonb) > 0;

UPDATE storefront_cart_item
SET unit_price_minor = unit_price_minor * 1000;

UPDATE ordering_orders
SET subtotal_minor = subtotal_minor * 1000,
    discount_minor = discount_minor * 1000,
    delivery_minor = delivery_minor * 1000,
    total_minor = total_minor * 1000;

UPDATE ordering_order_lines
SET unit_price_minor = unit_price_minor * 1000;

UPDATE payment
SET amount_minor = amount_minor * 1000;

UPDATE payment_ledger
SET amount_minor = amount_minor * 1000;

UPDATE storefront_shipping_policy
SET delivery_fee_minor = delivery_fee_minor * 1000,
    free_delivery_threshold_minor = free_delivery_threshold_minor * 1000;

-- Down (best effort): sub-millime amounts cannot be represented after the
-- conversion, so integer division truncates them.
UPDATE catalog_products SET price_minor = price_minor / 1000;
UPDATE catalog_product_view SET price_minor = price_minor / 1000;
UPDATE storefront_product_view SET price_minor = price_minor / 1000;
UPDATE storefront_product_view
SET old_price_minor = old_price_minor / 1000
WHERE old_price_minor IS NOT NULL;
UPDATE storefront_product_view
SET variants = (
    SELECT COALESCE(
        jsonb_agg(
            CASE
                WHEN elem ? 'price' AND (elem->>'price') ~ '^[0-9]+$'
                    THEN jsonb_set(elem, '{price}', to_jsonb(((elem->>'price')::bigint / 1000)))
                ELSE elem
            END
        ),
        '[]'::jsonb
    )
    FROM jsonb_array_elements(variants::jsonb) AS elem
)::json
WHERE jsonb_array_length(variants::jsonb) > 0;
UPDATE storefront_cart_item SET unit_price_minor = unit_price_minor / 1000;
UPDATE ordering_orders
SET subtotal_minor = subtotal_minor / 1000,
    discount_minor = discount_minor / 1000,
    delivery_minor = delivery_minor / 1000,
    total_minor = total_minor / 1000;
UPDATE ordering_order_lines SET unit_price_minor = unit_price_minor / 1000;
UPDATE payment SET amount_minor = amount_minor / 1000;
UPDATE payment_ledger SET amount_minor = amount_minor / 1000;
UPDATE storefront_shipping_policy
SET delivery_fee_minor = delivery_fee_minor / 1000,
    free_delivery_threshold_minor = free_delivery_threshold_minor / 1000;
