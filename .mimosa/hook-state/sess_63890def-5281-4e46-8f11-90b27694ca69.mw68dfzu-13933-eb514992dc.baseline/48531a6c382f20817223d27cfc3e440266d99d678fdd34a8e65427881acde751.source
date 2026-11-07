<?php

declare(strict_types=1);

use App\Ordering\Application\Command\PlaceOrder;
use App\Shared\Application\Service\StorefrontQuoteService;
use Symfony\Component\Dotenv\Dotenv;

require '/app/vendor/autoload.php';
(new Dotenv())->bootEnv('/app/.env');

$kernel = new App\Kernel('test', false);
$kernel->boot();
$c = $kernel->getContainer()->get('test.service_container');
$quotes = $c->get(StorefrontQuoteService::class);

$conn = $c->get(Doctrine\DBAL\Connection::class);
$slug = 'probe-'.substr(bin2hex(random_bytes(4)), 0, 8);
$conn->executeStatement(
    "INSERT INTO storefront_product_view (
        id, slug, name, brand, ref, category_slug, subcategory, price_minor, old_price_minor, currency,
        stock, stock_quantity, material, dims, image_path, rating, reviews, is_new, is_best, headrest, description, variants, approval_status, published
    )
    SELECT :id, :slug, name, brand, ref, category_slug, subcategory, price_minor, old_price_minor, currency,
           stock, 5, material, dims, image_path, rating, reviews, is_new, is_best, headrest, description, variants, approval_status, published
    FROM storefront_product_view WHERE slug = 'baignoire-d-code'",
    ['id' => Symfony\Component\Uid\Uuid::v7()->toRfc4122(), 'slug' => $slug],
);

$q = $quotes->quote([['slug' => $slug, 'quantity' => 2]], null, 'home');
echo 'current: subtotal=', $q['subtotal']->minorUnits(), ' delivery=', $q['delivery']->minorUnits(),
    ' total=', $q['total']->minorUnits(), ' count=', $q['itemCount'], PHP_EOL;
echo 'pricedLine: unitPriceMinor=', $q['pricedLines'][0]['unitPriceMinor'], PHP_EOL;

$bus = $c->get('messenger.bus.command');
try {
    $bus->dispatch(new PlaceOrder(
        orderId: Symfony\Component\Uid\Uuid::v7()->toRfc4122(),
        customerId: 'probe-customer',
        idempotencyKey: 'probe-'.bin2hex(random_bytes(8)),
        orderNumber: 'ZG-PROBE-1',
        lines: [['slug' => $slug, 'quantity' => 2]],
        deliveryMethod: 'home',
        paymentMethod: 'Carte bancaire',
        promoCode: null,
        confirmedQuote: [
            'lines' => [['slug' => $slug, 'variant' => null, 'unitPrice' => 680000, 'quantity' => 2]],
            'subtotal' => 1360000,
            'discount' => 0,
            'delivery' => 0,
            'total' => 1360000,
            'count' => 2,
            'promoCode' => null,
            'deliveryMethod' => 'home',
        ],
    ));
    echo 'dispatched ok', PHP_EOL;
} catch (Throwable $e) {
    $prev = $e;
    while (null !== $prev->getPrevious()) {
        $prev = $prev->getPrevious();
    }
    echo 'exception: ', get_class($prev), ' ', $prev->getMessage(), PHP_EOL;
    if (method_exists($prev, 'currentQuote')) {
        echo 'currentQuote: ', json_encode($prev->currentQuote()), PHP_EOL;
        echo 'changedLines: ', json_encode($prev->changedLines()), PHP_EOL;
    }
} finally {
    $conn->executeStatement('DELETE FROM ordering_order_lines WHERE order_id IN (SELECT id FROM ordering_orders WHERE customer_id = :c)', ['c' => 'probe-customer']);
    $conn->executeStatement('DELETE FROM ordering_order_audit_trail WHERE order_id IN (SELECT id FROM ordering_orders WHERE customer_id = :c)', ['c' => 'probe-customer']);
    $conn->executeStatement('DELETE FROM inventory_reservation WHERE order_id IN (SELECT id FROM ordering_orders WHERE customer_id = :c)', ['c' => 'probe-customer']);
    $conn->executeStatement('DELETE FROM ordering_orders WHERE customer_id = :c', ['c' => 'probe-customer']);
    $conn->executeStatement('DELETE FROM processed_commands WHERE key LIKE :p', ['p' => 'probe-%']);
    $conn->executeStatement('DELETE FROM storefront_product_view WHERE slug = :s', ['s' => $slug]);
}
