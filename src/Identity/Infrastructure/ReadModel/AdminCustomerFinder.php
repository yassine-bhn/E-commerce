<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\ReadModel;

use App\Identity\Application\Query\ListAdminCustomers;
use App\Identity\Domain\Model\AdminAccessRequiredException;
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

    private const COUNT_SQL = 'SELECT COUNT(*) FROM customer_profile profile
        WHERE '.self::REQUESTER_GUARD;

    private const ROWS_SQL = 'SELECT profile.id, profile.name, profile.email, profile.phone,
                     profile.role, profile.status,
                     (SELECT COUNT(*) FROM ordering_orders orders WHERE orders.customer_id = profile.id) AS order_count
        FROM customer_profile profile
        WHERE '.self::REQUESTER_GUARD.'
        ORDER BY profile.name ASC, profile.id ASC
        LIMIT :limit OFFSET :offset';

    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(ListAdminCustomers $query): AdminCustomersPageView
    {
        if (!$query->requesterIsAdmin) {
            throw AdminAccessRequiredException::create();
        }

        /** @var array<string, ParameterType> $types */
        $types = ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER];
        $params = [
            'requester' => $query->requesterId,
            'limit' => $query->effectiveLimit(),
            'offset' => $query->effectiveOffset(),
        ];

        $total = (int) $this->connection->fetchOne(self::COUNT_SQL, ['requester' => $query->requesterId]);
        $rows = $this->connection->fetchAllAssociative(self::ROWS_SQL, $params, $types);

        return new AdminCustomersPageView(
            items: array_map(
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
            page: $query->effectivePage(),
            limit: $query->effectiveLimit(),
            total: $total,
        );
    }
}
