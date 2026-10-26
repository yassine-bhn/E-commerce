<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

use App\Shared\Application\Pagination\PageRequest;

/**
 * Dashboard list of every order (online, phone and WhatsApp), paginated and filterable.
 *
 * Carries the requester identity so the finder can authorize inside the query itself
 * (QRY-004 / SEC-002). `page`/`size` are raw client input clamped by the shared PageRequest.
 */
final readonly class ListAdminOrders
{
    public const int DEFAULT_SIZE = 20;
    public const int MAX_SIZE = 50;

    /** @var list<string> */
    public const array SORTS = ['newest', 'oldest', 'totalDesc', 'totalAsc'];

    /**
     * @param string|null $status admin status slug: pending, paid, failed, cancelled, shipped, refunded
     * @param string|null $source ONLINE, PHONE or WHATSAPP
     */
    public function __construct(
        public string $requesterId,
        public bool $requesterIsAdmin,
        public PageRequest $page = new PageRequest(1, self::DEFAULT_SIZE, self::MAX_SIZE),
        public ?string $q = null,
        public ?string $status = null,
        public ?string $source = null,
        public ?\DateTimeImmutable $dateFrom = null,
        public ?\DateTimeImmutable $dateTo = null,
        public ?string $sort = null,
    ) {
    }

    public function effectiveSort(): string
    {
        return \in_array($this->sort, self::SORTS, true) ? $this->sort : 'newest';
    }
}
