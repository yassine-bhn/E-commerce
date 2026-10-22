<?php

declare(strict_types=1);

namespace App\Tests\Integration\Promotions;

use App\Promotions\Application\Query\ListAdminPromoCodes;
use App\Shared\Application\Pagination\Page;
use App\Shared\Application\Pagination\PageRequest;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * R12 — the admin promo-code list through the real query.bus against PostgreSQL: a filtered
 * COUNT plus a bounded LIMIT/OFFSET window, `code` order and the `active` filter in SQL (QRY-001/QRY-003).
 */
final class AdminPromoCodeListQueryTest extends KernelTestCase
{
    /** @var list<string> */
    private array $codes = [];

    protected function setUp(): void
    {
        self::bootKernel();

        try {
            self::db()->executeQuery('SELECT 1 FROM storefront_promo_code LIMIT 1');
        } catch (\Throwable) {
            self::markTestSkipped('PostgreSQL schema not migrated (run doctrine:migrations:migrate).');
        }
    }

    protected function tearDown(): void
    {
        if ([] !== $this->codes) {
            self::db()->executeStatement('DELETE FROM storefront_promo_code WHERE code IN (:codes)', ['codes' => $this->codes], ['codes' => ArrayParameterType::STRING]);
        }

        $this->codes = [];

        parent::tearDown();
    }

    public function testThePromoCodePageFiltersOnSearchAndActiveWithABoundedWindow(): void
    {
        $token = 'IT'.strtoupper(bin2hex(random_bytes(4)));
        $active = 'ACT'.$token;
        $inactive = 'INA'.$token;
        $other = 'OTH'.$token;
        $this->insertPromoCode($active, 1_500, true);
        $this->insertPromoCode($inactive, 500, false);
        $this->insertPromoCode($other, 2_000, true);

        $first = $this->dispatch(new ListAdminPromoCodes(new PageRequest(1, 2, ListAdminPromoCodes::MAX_SIZE), q: $token));
        self::assertSame(1, $first->page);
        self::assertSame(2, $first->size);
        self::assertSame(3, $first->total, 'the COUNT carries the same free-text predicate as the rows');
        self::assertSame(2, $first->totalPages());
        self::assertTrue($first->hasMore());
        self::assertSame([
            ['code' => $active, 'discountBasisPoints' => 1_500, 'active' => true],
            ['code' => $inactive, 'discountBasisPoints' => 500, 'active' => false],
        ], $first->items, 'code is the fixed order and the row keeps its dashboard shape');

        $second = $this->dispatch(new ListAdminPromoCodes(new PageRequest(2, 2, ListAdminPromoCodes::MAX_SIZE), q: $token));
        self::assertSame([['code' => $other, 'discountBasisPoints' => 2_000, 'active' => true]], $second->items);
        self::assertFalse($second->hasMore());

        $onlyInactive = $this->dispatch(new ListAdminPromoCodes($this->maxPage(), q: $token, active: false));
        self::assertSame(1, $onlyInactive->total);
        self::assertSame([$inactive], array_column($onlyInactive->items, 'code'));

        $onlyActive = $this->dispatch(new ListAdminPromoCodes($this->maxPage(), q: $token, active: true));
        self::assertSame(2, $onlyActive->total);
        self::assertSame([$active, $other], array_column($onlyActive->items, 'code'));

        $none = $this->dispatch(new ListAdminPromoCodes($this->maxPage(), q: 'ZZZ'.$token));
        self::assertSame(0, $none->total);
        self::assertSame([], $none->items);
    }

    private function maxPage(): PageRequest
    {
        return new PageRequest(1, ListAdminPromoCodes::MAX_SIZE, ListAdminPromoCodes::MAX_SIZE);
    }

    private function dispatch(object $query): Page
    {
        $envelope = $this->queryBus()->dispatch($query);
        $handled = $envelope->last(HandledStamp::class);
        self::assertNotNull($handled, 'the query must be handled on query.bus');

        $result = $handled->getResult();
        self::assertInstanceOf(Page::class, $result, 'QRY-002 — one page DTO, never a raw row list');

        return $result;
    }

    private function insertPromoCode(string $code, int $basisPoints, bool $active): void
    {
        $this->codes[] = $code;

        self::db()->insert('storefront_promo_code', [
            'code' => $code,
            'discount_basis_points' => $basisPoints,
            'active' => $active,
        ], [
            'discount_basis_points' => ParameterType::INTEGER,
            'active' => ParameterType::BOOLEAN,
        ]);
    }

    private function queryBus(): MessageBusInterface
    {
        $bus = static::getContainer()->get('messenger.bus.query');
        assert($bus instanceof MessageBusInterface);

        return $bus;
    }

    private static function db(): Connection
    {
        $connection = static::getContainer()->get(Connection::class);
        assert($connection instanceof Connection);

        return $connection;
    }
}
