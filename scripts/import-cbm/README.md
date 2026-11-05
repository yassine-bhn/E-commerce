# CBM import scripts

## Dry run (no DB writes)
```bash
python3 scripts/import-cbm/match.py
```
Outputs in `scripts/import-cbm/out/`:
- `matched.csv` — auto-accepted matches (score >= 0.95, classification confidence >= 0.7)
- `review.csv` — needs review (0.80–0.95 or low classification confidence)
- `unmatched.csv` — no match
- `summary.json` — totals, per-tier counts, per-category counts, anomalies
- `import.json` — manifest used by the importer

Environment variables:
- `CBM_PRODUCTS_JSON` (default: cbm_catalogue/products.json)
- `ZIDAINE_CSV` (default: ../nobili-scrapping/zidaine-groupe-product.csv)

## Import (DB writes)
Backup first:
```bash
make db-backup
```
Then:
```bash
IMPORT_JSON=/app/scripts/import-cbm/out/import.json \
IMPORT_ACTOR=system:cbm-import \
IMPORT_BATCH_SIZE=200 \
docker compose exec -T -e IMPORT_JSON=/app/scripts/import-cbm/out/import.json \
  -e IMPORT_ACTOR=system:cbm-import -e IMPORT_BATCH_SIZE=200 \
  app php scripts/import-cbm/import.php
```
The importer is idempotent (keyed on `supplier_reference`): re-running updates existing rows instead of duplicating.

## Verification
```sql
SELECT COUNT(*) FROM storefront_product_view;
SELECT category_slug, subcategory, COUNT(*) FROM storefront_product_view GROUP BY 1,2;
```
All imported products are `PENDING_APPROVAL` and `published = false`.