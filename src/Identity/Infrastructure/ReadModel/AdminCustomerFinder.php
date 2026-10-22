<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\ReadModel;

use App\Identity\Application\Query\ListAdminCustomers;
use App\Identity\Domain\Model\AdminAccessRequiredException;
use App\Shared\Application\Pagination\Page;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * R15 — DBAL-only authorized finder for the dashboard user list (QRY-001/QRY-002/QRY-003).
 *
 * The requester identity is a mandatory parameter and the admin check lives inside the SQL itself
 * (QRY-004 / SEC-002), so this finder can never hand rows to a caller that is not an admin in the
 * database — even if the flag on the query were forged. Only the seven dashboard columns are
 * selected; credentials and ban internals never leave the read model.
 */
#[AsMessageHandler(bus: 'query.bus')]
final class AdminCustomerFinder
{
    /** Roles allowed to read the account list — mirrors the /^/api/v1/admin → ROLE_ADMIN rule. */
    private const ADMIN_ROLES = "'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'";

    private const REQUESTER_GUARD = 'EXISTS (
            SELECT 1 FROM customer_profile requester_account
            WHERE requester_account.id = :requester
              AND requester_account.role IN ('.self::ADMIN_ROLES.')
              AND requester_account.status != \'banned\'
        )';

    /** Sort whitelist: deterministic ORDER BY, always ending on the primary key. */
    private const array ORDER_BY = [
        'name' => 'profile.name ASC, profile.id ASC',
        'createdAt' => 'profile.created_at DESC, profile.id ASC',
        'orderCount' => 'order_count DESC, profile.name ASC, profile.id ASC',
    ];

    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(ListAdminCustomers $query): Page
    {
        if (!$query->requesterIsAdmin) {
            throw AdminAccessRequiredException::create();
        }

        $conditions = [self::REQUESTER_GUARD];
        $params = ['requester' => $query->requesterId];

        if (null !== $query->q) {
            $conditions[] = "(profile.name ILIKE :q ESCAPE '\\' OR profile.email ILIKE :q ESCAPE '\\' OR profile.phone ILIKE :q ESCAPE '\\')";
            $params['q'] = '%'.self::escapeLike($query->q).'%';
        }

        if (null !== $query->role) {
            $conditions[] = 'profile.role = :role';
            $params['role'] = $query->role;
        }

        if (null !== $query->status) {
            $conditions[] = 'profile.status = :status';
            $params['status'] = $query->status;
        }

        $where = implode(' AND ', $conditions);

        $total = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM customer_profile profile WHERE '.$where,
            $params,
        );

        $rows = $this->connection->fetchAllAssociative(
            'SELECT profile.id, profile.name, profile.email, profile.phone,
                     profile.role, profile.status,
                     (SELECT COUNT(*) FROM ordering_orders orders WHERE orders.customer_id = profile.id) AS order_count
             FROM customer_profile profile
             WHERE '.$where.'
             ORDER BY '.self::ORDER_BY[$query->effectiveSort()].'
             LIMIT :limit OFFSET :offset',
            $params + [
                'limit' => $query->page->effectiveSize(),
                'offset' => $query->page->effectiveOffset(),
            ],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return new Page(
            array_map(
                static fn (array $row): AdminCustomerView => new AdminCustomerView(
                    id: (string) $row['id'],
                    name: (string) $row['name'],
                    email: (string) $row['email'],
                    phone: (string) ($row['phone'] ?? ''),
                    role: (string) $row['role'],
                    status: (string) $row['status'],
                    orderCount: (int) $row['order_count'],
                ),
                $rows,
            ),
            $query->page->effectivePage(),
            $query->page->effectiveSize(),
            $total,
        );
    }

    private static function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
