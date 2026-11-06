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
$db = getenv('DB_NAME') ?: 'app';
$user = getenv('DB_USER') ?: 'app';
$pass = getenv('DB_PASS') ?: 'app';

$pdo = new PDO("pgsql:host={$host};port={$port};dbname={$db}", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

function toMillimes(string $dec): ?int
{
    $dec = trim($dec);
    if ('' === $dec) {
        return null;
    }
    if (false !== strpos($dec, '.')) {
        $dec = rtrim(rtrim($dec, '0'), '.');
    }
    if (!preg_match('/^\d+(\.\d{1,3})?$/', $dec)) {
        return null;
    }
    [$whole, $frac] = array_pad(explode('.', $dec), 2, '');
    $frac = str_pad($frac, 3, '0');
    $digits = $whole.$frac;
    $digits = ltrim($digits, '0');
    if ('' === $digits) {
        return 0;
    }

    return (int) $digits;
}

function slugUnique(PDO $pdo, string $base): string
{
    $slug = $base;
    $i = 2;
    while (true) {
        $st = $pdo->prepare('SELECT 1 FROM storefront_product_view WHERE slug = :s');
        $st->execute([':s' => $slug]);
        if (!$st->fetch()) {
            return $slug;
        }
        $slug = $base.'-'.$i;
        ++$i;
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
            $cbmLocalImages = is_array($row['cbm_local_images'] ?? null) ? $row['cbm_local_images'] : [];
            $cbmAttributes = is_array($row['cbm_attributes'] ?? null) ? $row['cbm_attributes'] : [];
            $cbmColors = is_array($row['cbm_colors'] ?? null) ? $row['cbm_colors'] : [];
            $cbmLabels = is_array($row['cbm_labels'] ?? null) ? $row['cbm_labels'] : [];
            // The CBM product sheet is the descriptive source: the CSV description is empty on
            // almost every matched row and, when present, is only a short label.
            $description = '' !== $cbmDesc ? $cbmDesc : $csvDesc;
            $cat = (string) ($row['category_slug'] ?? '');
            $sub = (string) ($row['subcategory'] ?? '');

            if ('' === $cbmRef || '' === $name || '' === $cat || '' === $sub) {
                throw new RuntimeException("Missing required field for {$cbmRef}");
            }

            $priceMinor = toMillimes($priceHt);
            if (null === $priceMinor) {
                throw new RuntimeException("Bad price for {$cbmRef}: {$priceHt}");
            }

            $stockQty = ('' === $stockQtyRaw || null === $stockQtyRaw) ? null : (int) $stockQtyRaw;
            if (null !== $stockQty && $stockQty < 0) {
                $stockQty = null;
            }
            $stock = (null !== $stockQty && $stockQty > 0) ? 'in' : 'out';
            $eanVal = '' === $ean ? null : $ean;

            // find existing by supplier_reference or ref
            $st = $pdo->prepare('SELECT slug FROM storefront_product_view WHERE supplier_reference = :sr LIMIT 1');
            $st->execute([':sr' => $cbmRef]);
            $existingSlug = $st->fetchColumn();

            if ($existingSlug) {
                $slug = $existingSlug;
            } else {
                $base = '' !== $slugBase ? $slugBase : preg_replace('/[^a-z0-9]+/', '-', strtolower($cbmRef));
                $base = trim($base, '-');
                if ('' === $base) {
                    $base = 'product';
                }
                $slug = slugUnique($pdo, $base);
            }

            // Pictures are served from public/uploads/catalogue/cbm/<slug>/ (copied out of the
            // CBM scrape folder by copy-images.py); never store the remote cbmtunisie URL.
            $imageUrls = [];
            foreach ($cbmLocalImages as $rel) {
                $file = basename((string) $rel);
                if ('' !== $file) {
                    $imageUrls[] = '/uploads/catalogue/cbm/'.$slug.'/'.$file;
                }
            }
            if ([] === $imageUrls) {
                throw new RuntimeException("No local CBM images for {$cbmRef}");
            }
            $imagePath = $imageUrls[0];
            $additional = array_slice($imageUrls, 1);

            $imagesJson = json_encode($imageUrls, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $additionalJson = json_encode($additional, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $colorsJson = json_encode(array_values($cbmColors), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $attributesJson = json_encode((object) $cbmAttributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $characteristics = [];
            foreach ($cbmAttributes as $label => $value) {
                $characteristics[] = [
                    'label' => (string) $label,
                    'value' => is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE),
                ];
            }
            $characteristicsJson = json_encode($characteristics, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $technicalDataJson = json_encode([
                'attributes' => (object) $cbmAttributes,
                'colors' => array_values($cbmColors),
                'labels' => array_values($cbmLabels),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            foreach ([
                'images' => $imagesJson,
                'additional_images' => $additionalJson,
                'colors' => $colorsJson,
                'attributes' => $attributesJson,
                'characteristics' => $characteristicsJson,
                'technical_data' => $technicalDataJson,
            ] as $field => $json) {
                if (false === $json) {
                    throw new RuntimeException("Cannot encode {$field} for {$cbmRef}");
                }
            }
            $technicalInfo = '' !== $cbmDesc ? $cbmDesc : null;

            if ($dryRun) {
                ++$done;
                continue;
            }

            if ($existingSlug) {
                $sql = "UPDATE storefront_product_view SET
                    name = :name, brand = 'CBM', ref = :ref, supplier_reference = :sr,
                    category_slug = :cat, subcategory = :sub, price_minor = :price,
                    old_price_minor = NULL, currency = 'TND', stock = :stock,
                    material = '', dims = '', image_path = :image, description = :description,
                    additional_images = :additional, images = :images, categories = '[]'::json,
                    colors = :colors, attributes = :attributes, downloads = '[]'::json,
                    videos = '[]'::json, compatible_products = '[]'::json,
                    characteristics = :characteristics, technical_info = :tech_info,
                    technical_data = :tech_data,
                    variants = '[]'::json, stock_quantity = :sq, ean = :ean
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
                    ':images' => $imagesJson,
                    ':colors' => $colorsJson,
                    ':attributes' => $attributesJson,
                    ':characteristics' => $characteristicsJson,
                    ':tech_info' => $technicalInfo,
                    ':tech_data' => $technicalDataJson,
                    ':stock' => $stock,
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
                    false, :images, '[]'::json, :colors, :attributes, :ean, NULL, NULL,
                    '[]'::json, '[]'::json, '[]'::json, :characteristics, :tech_info, :tech_data, :additional
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
                    ':image' => $imagePath,
                    ':description' => $description,
                    ':images' => $imagesJson,
                    ':colors' => $colorsJson,
                    ':attributes' => $attributesJson,
                    ':characteristics' => $characteristicsJson,
                    ':tech_info' => $technicalInfo,
                    ':tech_data' => $technicalDataJson,
                    ':additional' => $additionalJson,
                    ':stock' => $stock,
                    ':sq' => $stockQty,
                    ':ean' => $eanVal,
                    ':actor' => $actor,
                ]);
            }

            // inventory stock
            if (null !== $stockQty) {
                $st = $pdo->prepare('INSERT INTO inventory_stock (product_slug, on_hand, reserved, updated_at)
                    VALUES (:slug, :qty, 0, now())
                    ON CONFLICT (product_slug) DO UPDATE SET on_hand = EXCLUDED.on_hand, updated_at = now()');
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

            ++$done;
        }
        $pdo->commit();
        echo 'Batch '.($bi + 1).' done ('.count($batch)." rows)\n";
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, 'Batch failed: '.$e->getMessage()."\n");
        exit(1);
    }
}

echo "Imported {$done} / {$total} products.\n";
