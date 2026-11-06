<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\ReadModel;

use App\Catalog\Application\Query\GetAdminProduct;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * solid Task 4.11 — R15/R17/R25: the Super Admin reads one product whatever its state, with its
 * approval metadata; a regular Admin reads their own submission and nobody else's. Null answers
 * "not found or not yours" (missing and foreign are the same answer, R5).
 *
 * @phpstan-type AdminProductPayload array<string, mixed>
 */
#[AsMessageHandler(bus: 'query.bus')]
final class GetAdminProductHandler
{
    public function __construct(
        private StorefrontProductRepository $products,
        private CatalogSubmissionFinder $submissions,
    ) {
    }

    /**
     * @return AdminProductPayload|null
     */
    public function __invoke(GetAdminProduct $query): ?array
    {
        $content = $this->products->adminBySlug($query->slug);
        if (null === $content) {
            return null;
        }

        $payload = $content->toAdminApi();
        if (null === $query->requesterId) {
            return $payload;
        }

        $submission = $this->submissions->one(
            \App\Catalog\Domain\Model\CatalogSubmissionKind::Product,
            $query->slug,
            $query->requesterId,
            $query->isAdmin,
        );

        if (null === $submission) {
            return null;
        }

        $payload['approval'] = $submission->toApi();

        return $payload;
    }
}
