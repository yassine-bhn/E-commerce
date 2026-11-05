<?php
declare(strict_types=1);

$input = $argv[1] ?? getenv('IMPORT_JSON') ?: '/home/yassine/Desktop/freelance/Zidaine-groupe/E-commerce/scripts/import-cbm/out/import.json';
$batchSize = (int) (getenv('IMPORT_BATCH_SIZE') ?: 200);
$dryRun = (bool) getenv('DRY_RUN');

if (!is_readable($input)) {
    fwrite(STDERR, "Cannot read input: {$input}\n");
    exit(1);
}
$rows = json_decode((string) file_get_contents($input), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($rows)) {
    fwrite(STDERR, "Invalid JSON\n");
    exit(1);
}

$host = getenv('DB_HOST') ?: 'database';
$port = getenv('DB_PORT') ?: '5432';
$db   = getenv('DB_NAME') ?: 'app';
$user = getenv('DB_USER') ?: 'app';
$pass = getenv('DB_PASS') ?: 'app';

$pdo = new PDO("pgsql:host={$host};port={$port};dbname={$db}", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

function toMillimes(string $dec): ?int {
    $dec = trim($dec);
    if ($dec === '') return null;
    if (strpos($dec, ".") !== false) { $dec = rtrim(rtrim($dec, "0"), "."); }
    if (!preg_match('/^\d+(\.\d{1,3})?$/', $dec)) return null;
    [$whole, $frac] = array_pad(explode('.', $dec), 2, '');
    $frac = str_pad($frac, 3, '0');
    $digits = $whole . $frac;
    $digits = ltrim($digits, '0');
    if ($digits === '') return 0;
    return (int) $digits;
}

function slugUnique(PDO $pdo, string $base): string {
    $slug = $base;
    $i = 2;
    while (true) {
        $st = $pdo->prepare('SELECT 1 FROM storefront_product_view WHERE slug = :s');
        $st->execute([':s' => $slug]);
        if (!$st->fetch()) return $slug;
        $slug = $base . '-' . $i;
        $i++;
    }
}

$actor = getenv('IMPORT_ACTOR') ?: 'system:cbm-import';
$total = count($rows);
$done = 0;
$batches = array_chunk($rows, $batchSize);

foreach ($batches as $bi => $batch) {
    $pdo->beginTransaction();
    try {
        foreach ($batch as $row) {
            $cbmRef = trim((string) ($row['cbm_ref'] ?? ''));
            $slugBase = (string) ($row['slug'] ?? '');
            $csvRef = trim((string) ($row['csv_ref'] ?? ''));
            $name = (string) ($row['csv_name'] ?? '');
            $priceHt = (string) ($row['csv_price_ht'] ?? '');
            $stockQtyRaw = $row['csv_stock_qty'] ?? '';
            $ean = trim((string) ($row['csv_ean'] ?? ''));
            $csvDesc = trim((string) ($row['csv_description'] ?? ''));
            $cbmDesc = trim((string) ($row['cbm_description'] ?? ''));
            $cbmImage = trim((string) ($row['cbm_image'] ?? ''));
            $cbmImages = $row['cbm_images'] ?? [];
            $description = $csvDesc !== '' ? $csvDesc : $cbmDesc;
            $imagePath = $cbmImage;
            $additional = is_array($cbmImages) ? $cbmImages : [];
            if ($imagePath !== '' && count($additional) > 0 && $additional[0] === $imagePath) {
                array_shift($additional);
            }
            $additionalJson = json_encode(array_values($additional), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($additionalJson === false) $additionalJson = '[]';
            $cat = (string) ($row['category_slug'] ?? '');
            $sub = (string) ($row['subcategory'] ?? '');

            if ($cbmRef === '' || $name === '' || $cat === '' || $sub === '') {
                throw new RuntimeException("Missing required field for {$cbmRef}");
            }

            $priceMinor = toMillimes($priceHt);
            if ($priceMinor === null) {
                throw new RuntimeException("Bad price for {$cbmRef}: {$priceHt}");
            }

            $stockQty = ($stockQtyRaw === '' || $stockQtyRaw === null) ? null : (int) $stockQtyRaw;
            if ($stockQty !== null && $stockQty < 0) {
                $stockQty = null;
            }
            $stock = ($stockQty !== null && $stockQty > 0) ? 'in' : 'out';
            $eanVal = $ean === '' ? null : $ean;

            // find existing by supplier_reference or ref
            $st = $pdo->prepare('SELECT slug FROM storefront_product_view WHERE supplier_reference = :sr LIMIT 1');
            $st->execute([':sr' => $cbmRef]);
            $existingSlug = $st->fetchColumn();

            if ($existingSlug) {
                $slug = $existingSlug;
            } else {
                $base = $slugBase !== '' ? $slugBase : preg_replace('/[^a-z0-9]+/', '-', strtolower($cbmRef));
                $base = trim($base, '-');
                if ($base === '') $base = 'product';
                $slug = slugUnique($pdo, $base);
            }

            if ($dryRun) {
                $done++;
                continue;
            }

            if ($existingSlug) {
                $sql = "UPDATE storefront_product_view SET
                    name = :name, brand = 'CBM', ref = :ref, supplier_reference = :sr,
                    category_slug = :cat, subcategory = :sub, price_minor = :price,
                    old_price_minor = NULL, currency = 'TND', stock = :stock,
                    material = '', dims = '', image_path = :image, description = :description,
                    additional_images = :additional,
                    variants = '[]'::json, stock_quantity = :sq, ean = :ean,
                    images = '[]'::json, categories = '[]'::json, colors = '[]'::json,
                    attributes = '{}'::json, downloads = '[]'::json, videos = '[]'::json,
                    compatible_products = '[]'::json, characteristics = '[]'::json
                  WHERE slug = :slug";
                $st = $pdo->prepare($sql);
                $st->execute([
                    ':name' => $name,
                    ':ref' => $csvRef,
                    ':sr' => $cbmRef,
                    ':cat' => $cat,
                    ':sub' => $sub,
                    ':price' => $priceMinor,
                    ':image' => $imagePath,
                    ':description' => $description,
                    ':additional' => $additionalJson,
                    ':stock' => $stock,
                    ':image' => $imagePath,
                    ':description' => $description,
                    ':additional' => $additionalJson,
                    ':sq' => $stockQty,
                    ':ean' => $eanVal,
                    ':slug' => $slug,
                ]);
            } else {
                $sql = "INSERT INTO storefront_product_view (
                    id, slug, name, brand, ref, supplier_reference, category_slug, subcategory,
                    price_minor, old_price_minor, currency, stock, material, dims, image_path,
                    rating, reviews, is_new, is_best, headrest, description, variants,
                    stock_quantity, approval_status, submitted_by, submitted_at, reviewed_by,
                    reviewed_at, rejection_reason, published, images, categories, colors,
                    attributes, ean, weight, dimensions, downloads, videos, compatible_products,
                    characteristics, technical_info, technical_data, additional_images
                ) VALUES (
                    gen_random_uuid(), :slug, :name, 'CBM', :ref, :sr, :cat, :sub,
                    :price, NULL, 'TND', :stock, '', '', :image, 0, 0, true, false, false, :description,
                    '[]'::json, :sq, 'PENDING_APPROVAL', :actor, now(), NULL, NULL, NULL,
                    false, '[]'::json, '[]'::json, '[]'::json, '{}'::json, :ean, NULL, NULL,
                    '[]'::json, '[]'::json, '[]'::json, '[]'::json, NULL, NULL, :additional
                )";
                $st = $pdo->prepare($sql);
                $st->execute([
                    ':slug' => $slug,
                    ':name' => $name,
                    ':ref' => $csvRef,
                    ':sr' => $cbmRef,
                    ':cat' => $cat,
                    ':sub' => $sub,
                    ':price' => $priceMinor,
                    ':stock' => $stock,
                    ':sq' => $stockQty,
                    ':ean' => $eanVal,
                    ':actor' => $actor,
                ]);
            }

            // inventory stock
            if ($stockQty !== null) {
                $st = $pdo->prepare("INSERT INTO inventory_stock (product_slug, on_hand, reserved, updated_at)
                    VALUES (:slug, :qty, 0, now())
                    ON CONFLICT (product_slug) DO UPDATE SET on_hand = EXCLUDED.on_hand, updated_at = now()");
                $st->execute([':slug' => $slug, ':qty' => $stockQty]);
            }

            // catalog submission (idempotent)
            $st = $pdo->prepare("INSERT INTO catalog_submission
                (kind, slug, approval_status, submitted_by, submitted_at, reviewed_by, reviewed_at, rejection_reason, updated_at)
                VALUES ('products', :slug, 'PENDING_APPROVAL', :actor, now(), NULL, NULL, NULL, now())
                ON CONFLICT (kind, slug) DO NOTHING");
            $st->execute([':slug' => $slug, ':actor' => $actor]);

            // audit log
            $st = $pdo->prepare("INSERT INTO catalog_audit_log (id, action, target_type, target_slug, actor_id, reason, created_at)
                VALUES (gen_random_uuid(), 'CBM_CATALOG_IMPORTED', 'product', :slug, :actor, :reason, now())");
            $st->execute([':slug' => $slug, ':actor' => $actor, ':reason' => "CBM ref {$cbmRef}"]);

            $done++;
        }
        $pdo->commit();
        echo "Batch " . ($bi + 1) . " done (" . count($batch) . " rows)\n";
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "Batch failed: " . $e->getMessage() . "\n");
        exit(1);
    }
}

echo "Imported {$done} / {$total} products.\n";