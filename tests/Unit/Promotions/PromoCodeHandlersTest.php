<?php

declare(strict_types=1);

namespace App\Tests\Unit\Promotions;

use App\Promotions\Application\Command\CreatePromoCode;
use App\Promotions\Application\Command\DeletePromoCode;
use App\Promotions\Application\Command\UpdatePromoCode;
use App\Promotions\Application\CommandHandler\CreatePromoCodeHandler;
use App\Promotions\Application\CommandHandler\DeletePromoCodeHandler;
use App\Promotions\Application\CommandHandler\UpdatePromoCodeHandler;
use App\Promotions\Application\Port\PromoCodeReader;
use App\Promotions\Application\Port\PromoCodeStore;
use App\Promotions\Domain\Model\PromoCodeAlreadyExistsException;
use App\Promotions\Domain\Model\PromoCodeNotFoundException;
use PHPUnit\Framework\TestCase;

/**
 * solid Task 4.10 — the promo-code handlers over in-memory fakes: the duplicate-create refusal,
 * the merge update and the delete passthrough.
 */
final class PromoCodeHandlersTest extends TestCase
{
    public function testCreateRefusesADuplicate(): void
    {
        $reader = new FakePromoCodeReader(exists: true);
        $store = new FakePromoCodeStore();

        try {
            (new CreatePromoCodeHandler($reader, $store))(new CreatePromoCode('WELCOME10', 1000, true));
            self::fail('a duplicate code must be refused');
        } catch (PromoCodeAlreadyExistsException) {
            self::assertNull($store->created, 'a refused create never reaches the store');
        }
    }

    public function testCreateWritesTheCode(): void
    {
        $reader = new FakePromoCodeReader(exists: false);
        $store = new FakePromoCodeStore();

        (new CreatePromoCodeHandler($reader, $store))(new CreatePromoCode('WELCOME10', 1000, true));

        self::assertSame(['code' => 'WELCOME10', 'discountBasisPoints' => 1000, 'active' => true], $store->created);
    }

    public function testUpdateMergesOnlyTheProvidedFields(): void
    {
        $reader = new FakePromoCodeReader(
            row: ['code' => 'WELCOME10', 'discount_basis_points' => 1000, 'active' => true],
        );
        $store = new FakePromoCodeStore();

        (new UpdatePromoCodeHandler($reader, $store))(new UpdatePromoCode('WELCOME10', null, false));

        self::assertSame(
            ['code' => 'WELCOME10', 'discountBasisPoints' => 1000, 'active' => false],
            $store->updated,
            'the discount is kept, the active flag is replaced',
        );
    }

    public function testUpdateOnAnUnknownCodeIsNotFound(): void
    {
        $reader = new FakePromoCodeReader(row: null);
        $store = new FakePromoCodeStore();

        $this->expectException(PromoCodeNotFoundException::class);

        (new UpdatePromoCodeHandler($reader, $store))(new UpdatePromoCode('GHOST', 1000, null));
    }

    public function testDeleteDelegatesToTheStore(): void
    {
        $store = new FakePromoCodeStore();

        (new DeletePromoCodeHandler($store))(new DeletePromoCode('WELCOME10'));

        self::assertSame('WELCOME10', $store->deleted);
    }
}

final class FakePromoCodeReader implements PromoCodeReader
{
    /**
     * @param array{code: string, discount_basis_points: int, active: bool}|null $row
     */
    public function __construct(
        private readonly bool $exists = false,
        private readonly ?array $row = null,
    ) {
    }

    public function exists(string $code): bool
    {
        return $this->exists;
    }

    public function find(string $code): ?array
    {
        return $this->row;
    }
}

final class FakePromoCodeStore implements PromoCodeStore
{
    /** @var array{code: string, discountBasisPoints: int, active: bool}|null */
    public ?array $created = null;

    /** @var array{code: string, discountBasisPoints: int, active: bool}|null */
    public ?array $updated = null;

    public ?string $deleted = null;

    public function create(string $code, int $discountBasisPoints, bool $active): void
    {
        $this->created = ['code' => $code, 'discountBasisPoints' => $discountBasisPoints, 'active' => $active];
    }

    public function update(string $code, int $discountBasisPoints, bool $active): void
    {
        $this->updated = ['code' => $code, 'discountBasisPoints' => $discountBasisPoints, 'active' => $active];
    }

    public function delete(string $code): void
    {
        $this->deleted = $code;
    }
}
