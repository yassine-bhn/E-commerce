<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Http;

use App\Catalog\Application\Command\CreateProduct;
use App\Catalog\Application\Command\SetProductPublication;
use App\Catalog\Application\Command\UpdateProduct;
use App\Catalog\Application\Dto\ProductDraft;
use App\Catalog\Application\Query\GetAdminProduct;
use App\Catalog\Domain\Model\ApprovalTransitionRefusedException;
use App\Catalog\Domain\Model\CatalogSubmissionNotFoundException;
use App\Catalog\Domain\Model\CatalogSubmissionNotOwnedException;
use App\Catalog\Domain\Model\InvalidProductTaxonomyException;
use App\Catalog\Domain\Model\ProductAlreadyExistsException;
use App\Catalog\Domain\Model\ProductHasOrdersCannotBeDeletedException;
use App\Catalog\Domain\Model\ProductNotFoundException;
use App\Identity\Infrastructure\Security\CustomerUser;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Shared\Infrastructure\Http\MessengerQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * R15/R17/R25 — the admin product surface. The edge owns the request shape (JSON, field types,
 * decimal parsing, variants, slug format, stock enum) and builds the ProductDraft; every write
 * is one command on `command.bus` and every read one query on `query.bus` — nothing here touches
 * the database, and the taxonomy, duplicate and authorization checks live in the handlers.
 */
#[Route('/api/v1/admin/catalog/products')]
final class AdminProductController
{
    private const array STOCKS = ['in', 'order', 'pickup', 'out'];

    public function __construct(
        private MessengerQuery $queries,
        private MessageBusInterface $commandBus,
    ) {
    }

    #[Route('', name: 'admin_catalog_create_product', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] CustomerUser $admin): JsonResponse
    {
        $body = $this->decode($request);
        if (null === $body) {
            return new JsonResponse(['error' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        $draft = $this->draft($body, null);
        if (is_string($draft)) {
            return new JsonResponse(['error' => $draft], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->commandBus->dispatch(new CreateProduct($draft, $admin->getId()));
        } catch (HandlerFailedException $exception) {
            return $this->domainFailure($this->rootCause($exception));
        }

        return new JsonResponse($this->adminPayload($draft->slug), Response::HTTP_CREATED);
    }

    #[Route('/{slug}', name: 'admin_catalog_show_product', methods: ['GET'])]
    public function show(string $slug, #[CurrentUser] CustomerUser $admin): JsonResponse
    {
        $payload = $this->adminPayload($slug, $admin);

        return null === $payload
            ? new JsonResponse(['error' => 'Product not found.'], Response::HTTP_NOT_FOUND)
            : new JsonResponse($payload);
    }

    #[Route('/{slug}', name: 'admin_catalog_update_product', methods: ['PUT'])]
    public function update(string $slug, Request $request, #[CurrentUser] CustomerUser $admin): JsonResponse
    {
        $body = $this->decode($request);
        if (null === $body) {
            return new JsonResponse(['error' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        $draft = $this->draft($body, $slug);
        if (is_string($draft)) {
            return new JsonResponse(['error' => $draft], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->commandBus->dispatch(new UpdateProduct($slug, $draft, $admin->getId(), $this->isSuperAdmin($admin)));
        } catch (HandlerFailedException $exception) {
            return $this->domainFailure($this->rootCause($exception));
        }

        return new JsonResponse($this->adminPayload($slug, $admin));
    }

    /**
     * R28/R29 — publication is its own explicit decision, never a side effect of approval.
     */
    #[Route('/{slug}/publication', name: 'admin_catalog_product_publication', methods: ['PATCH'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function setPublication(string $slug, Request $request, #[CurrentUser] CustomerUser $admin): JsonResponse
    {
        $body = $this->decode($request);
        if (null === $body || !is_bool($body['published'] ?? null)) {
            return new JsonResponse(['error' => 'published must be a boolean.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->commandBus->dispatch(new SetProductPublication($slug, $body['published'], $admin->getId()));
        } catch (HandlerFailedException $exception) {
            return $this->domainFailure($this->rootCause($exception));
        }

        return new JsonResponse($this->adminPayload($slug, $admin));
    }

    /** @return array<string, mixed>|null */
    private function adminPayload(string $slug, ?CustomerUser $admin = null): ?array
    {
        /* @var array<string, mixed>|null */
        return $this->queries->dispatch(new GetAdminProduct(
            $slug,
            $admin?->getId(),
            null !== $admin && $this->isSuperAdmin($admin),
        ));
    }

    private function isSuperAdmin(CustomerUser $admin): bool
    {
        return in_array('ROLE_SUPER_ADMIN', $admin->getRoles(), true);
    }

    private function rootCause(HandlerFailedException $exception): \Throwable
    {
        $cause = $exception;

        while ($cause instanceof HandlerFailedException && null !== $cause->getPrevious()) {
            $cause = $cause->getPrevious();
        }

        return $cause;
    }

    private function domainFailure(\Throwable $error): JsonResponse
    {
        if ($error instanceof ProductAlreadyExistsException) {
            return new JsonResponse(['error' => 'A product with this slug already exists.'], Response::HTTP_CONFLICT);
        }

        if ($error instanceof InvalidProductTaxonomyException) {
            return new JsonResponse(['error' => $error->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($error instanceof CatalogSubmissionNotFoundException || $error instanceof ProductNotFoundException) {
            return new JsonResponse(['error' => 'Product not found.', 'message' => $error->getMessage()], Response::HTTP_NOT_FOUND);
        }

        if ($error instanceof ProductHasOrdersCannotBeDeletedException) {
            return new JsonResponse([
                'error' => 'Product Has Historical Orders',
                'message' => $error->getMessage(),
                'advice' => 'Unpublish or hide the product instead of deleting it to preserve order history and accounting integrity.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($error instanceof CatalogSubmissionNotOwnedException) {
            return new JsonResponse(['error' => 'Forbidden', 'message' => $error->getMessage()], Response::HTTP_FORBIDDEN);
        }

        if ($error instanceof ApprovalTransitionRefusedException) {
            return new JsonResponse(['error' => 'Approval Transition Refused', 'message' => $error->getMessage()], Response::HTTP_CONFLICT);
        }

        throw $error;
    }

    /** @return array<string, mixed>|null */
    private function decode(Request $request): ?array
    {
        $body = json_decode($request->getContent(), true);

        /** @var array<string, mixed>|null $result */
        $result = is_array($body) ? $body : null;

        return $result;
    }

    /**
     * Validates the payload and returns the draft, or a message describing the first problem.
     *
     * @param array<string, mixed> $body
     */
    private function draft(array $body, ?string $fixedSlug): ProductDraft|string
    {
        $name = $this->text($body['name'] ?? null);
        if ('' === $name || mb_strlen($name) > 255) {
            return 'The name is required (255 characters max).';
        }

        $slug = $fixedSlug ?? $this->text($body['slug'] ?? null);
        if ('' === $slug) {
            $slug = mb_substr(strtolower((new AsciiSlugger('fr'))->slug($name)->toString()), 0, 128);
        }
        if (1 !== preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || strlen($slug) > 128) {
            return 'The slug may only contain lowercase letters, digits and dashes (128 characters max).';
        }

        $brand = $this->text($body['brand'] ?? null);
        if ('' === $brand || mb_strlen($brand) > 64) {
            return 'The brand is required (64 characters max).';
        }

        $ref = $this->text($body['ref'] ?? null);
        if ('' === $ref || mb_strlen($ref) > 64) {
            return 'The reference is required (64 characters max).';
        }

        // Optional supplier reference: the same typed contract as `ref`, but blank means "unset".
        $rawSupplierReference = $body['supplierReference'] ?? null;
        if (null !== $rawSupplierReference && !is_string($rawSupplierReference)) {
            return 'The supplier reference must be a string or null (64 characters max).';
        }
        $supplierReference = is_string($rawSupplierReference) ? trim($rawSupplierReference) : null;
        if ('' === $supplierReference) {
            $supplierReference = null;
        }
        if (null !== $supplierReference && mb_strlen($supplierReference) > 64) {
            return 'The supplier reference must be a string or null (64 characters max).';
        }

        $category = $this->text($body['category'] ?? null);
        $sub = $this->text($body['sub'] ?? null);
        if (mb_strlen($sub) > 128) {
            return 'The subcategory is too long (128 characters max).';
        }

        $variants = $this->variants($body['variants'] ?? []);
        if (is_string($variants)) {
            return $variants;
        }

        if ([] !== $variants) {
            // The storefront shows "from <lowest variant price>": keep the base price in sync.
            $price = min(array_column($variants, 'price'));
        } else {
            $price = $this->decimalMinor($body['price'] ?? null);
            if (null === $price) {
                return 'The price must be a non-negative decimal DT amount with up to 3 decimals.';
            }
        }

        $oldPrice = null;
        $rawOldPrice = $body['oldPrice'] ?? null;
        if (null !== $rawOldPrice && '' !== $rawOldPrice) {
            $oldPrice = $this->decimalMinor($rawOldPrice);
            if (null === $oldPrice || $oldPrice <= $price) {
                return 'The old price must be a decimal DT amount with up to 3 decimals, greater than the price.';
            }
        }

        $stock = $this->text($body['stock'] ?? null);
        if (!in_array($stock, self::STOCKS, true)) {
            return sprintf('The stock must be one of: %s.', implode(', ', self::STOCKS));
        }

        $stockQuantity = null;
        if (isset($body['stockQuantity'])) {
            if (!is_int($body['stockQuantity']) || $body['stockQuantity'] < 0) {
                return 'The stock quantity must be a non-negative integer.';
            }
            $stockQuantity = $body['stockQuantity'];
        }

        $material = $this->text($body['material'] ?? null);
        $dims = $this->text($body['dims'] ?? null);
        if (mb_strlen($material) > 128 || mb_strlen($dims) > 128) {
            return 'The material and dimensions are limited to 128 characters.';
        }

        $image = $this->text($body['image'] ?? null);
        if ('' === $image || strlen($image) > 255 || 1 !== preg_match('#^(/[A-Za-z0-9._/-]+|https?://\S+)$#', $image)) {
            return 'The image must be a site path (/photo.jpg) or an http(s) URL (255 characters max).';
        }

        $description = $this->text($body['description'] ?? null);
        if (mb_strlen($description) > 5000) {
            return 'The description is limited to 5000 characters.';
        }

        // vatRate — decimal percentage (up to 2 decimals), or null when blank/absent.
        $rawVatRate = $body['vatRate'] ?? null;
        $vatRate = null;
        if (null !== $rawVatRate && !(is_string($rawVatRate) && '' === trim($rawVatRate))) {
            if (is_bool($rawVatRate) || (!is_string($rawVatRate) && !is_int($rawVatRate) && !is_float($rawVatRate))) {
                return 'The VAT rate must be a decimal percentage with up to 2 decimals, or null.';
            }
            if (is_float($rawVatRate) && !is_finite($rawVatRate)) {
                return 'The VAT rate must be a decimal percentage with up to 2 decimals, or null.';
            }
            $vatRateString = is_string($rawVatRate) ? $rawVatRate : (string) $rawVatRate;
            if (1 !== preg_match('/^-?\d+(\.\d{1,2})?$/', $vatRateString)) {
                return 'The VAT rate must be a decimal percentage with up to 2 decimals, or null.';
            }
            $vatRate = $vatRateString;
        }

        // salePriceTtc — decimal DT amount parsed with Money::fromDecimalString, or null when blank/absent.
        $rawSalePriceTtc = $body['salePriceTtc'] ?? null;
        $salePriceTtc = null;
        if (null !== $rawSalePriceTtc && !(is_string($rawSalePriceTtc) && '' === trim($rawSalePriceTtc))) {
            if (is_bool($rawSalePriceTtc) || (!is_string($rawSalePriceTtc) && !is_int($rawSalePriceTtc) && !is_float($rawSalePriceTtc))) {
                return 'The sale price (TTC) must be a non-negative decimal DT amount with up to 3 decimals, or null.';
            }
            if (is_float($rawSalePriceTtc) && !is_finite($rawSalePriceTtc)) {
                return 'The sale price (TTC) must be a non-negative decimal DT amount with up to 3 decimals, or null.';
            }
            $salePriceTtcCandidate = is_string($rawSalePriceTtc) ? trim($rawSalePriceTtc) : (string) $rawSalePriceTtc;
            if ('' === $salePriceTtcCandidate) {
                $salePriceTtc = null;
            } else {
                try {
                    $salePriceTtc = Money::fromDecimalString($salePriceTtcCandidate, Currency::TND)->minorUnits();
                } catch (\InvalidArgumentException) {
                    return 'The sale price (TTC) must be a non-negative decimal DT amount with up to 3 decimals, or null.';
                }
            }
        }

        return new ProductDraft(
            slug: $slug,
            name: $name,
            brand: $brand,
            ref: $ref,
            supplierReference: $supplierReference,
            category: $category,
            sub: $sub,
            priceMinor: $price,
            oldPriceMinor: $oldPrice,
            stock: $stock,
            stockQuantity: $stockQuantity,
            material: $material,
            dims: $dims,
            image: $image,
            isNew: true === ($body['isNew'] ?? false),
            best: true === ($body['best'] ?? false),
            headrest: true === ($body['headrest'] ?? false),
            description: $description,
            variants: $variants,
            vatRate: $vatRate,
            salePriceTtcMinor: $salePriceTtc,
        );
    }

    /** @return list<array{label: string, price: int}>|string */
    private function variants(mixed $raw): array|string
    {
        if (!is_array($raw)) {
            return 'The variants must be a list.';
        }

        $variants = [];
        $seen = [];
        foreach (array_values($raw) as $index => $variant) {
            $label = is_array($variant) ? $this->text($variant['label'] ?? null) : '';
            $price = is_array($variant) ? $this->decimalMinor($variant['price'] ?? null) : null;

            if ('' === $label || mb_strlen($label) > 128 || null === $price) {
                return sprintf('Variant #%d needs a label and a non-negative decimal DT price with up to 3 decimals.', $index + 1);
            }
            if (isset($seen[$label])) {
                return sprintf('The variant label "%s" is used twice.', $label);
            }

            $seen[$label] = true;
            $variants[] = ['label' => $label, 'price' => $price];
        }

        return $variants;
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /**
     * A decimal DT amount (string, integer or JSON number) parsed exactly to millimes. JSON floats
     * are only converted to PHP's shortest round-trip decimal representation, never used in
     * arithmetic. Returns null for malformed input, negative values or more than three decimals.
     */
    private function decimalMinor(mixed $value): ?int
    {
        if (is_int($value)) {
            $value = (string) $value;
        } elseif (is_float($value) && is_finite($value)) {
            $value = (string) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        try {
            return Money::fromDecimalString($value, Currency::TND)->minorUnits();
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
