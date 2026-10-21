<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Application\Command\BannerRemoveImage;
use App\Catalog\Application\Command\BannerReorderImages;
use App\Catalog\Application\Command\BannerReplaceImages;
use App\Catalog\Application\Query\GetBannerImages;
use App\Catalog\Domain\Model\BannerImageNotFoundException;
use App\Catalog\Domain\Model\BannerRejectedException;
use App\Catalog\Infrastructure\ReadModel\BannerView;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * R18/R20/R21/R22 — the write model behind the banner endpoints: one command.bus handler per command,
 * one transaction, and a DBAL read that returns exactly what was stored in exactly that order.
 */
final class BannerImageSetTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $this->db()->executeStatement('DELETE FROM storefront_banner_image');
    }

    protected function tearDown(): void
    {
        $this->db()->executeStatement('DELETE FROM storefront_banner_image');

        parent::tearDown();
    }

    public function testReplacingTheSetWithThreeImagesStoresThreeOrderedSlides(): void
    {
        $this->commands()->dispatch(new BannerReplaceImages([
            '/uploads/products/cccc.png',
            '/uploads/products/aaaa.png',
            '/uploads/products/bbbb.png',
        ]));

        /** @var list<array{image_path: string, sort_order: string|int}> $rows */
        $rows = $this->db()->fetchAllAssociative(
            'SELECT image_path, sort_order FROM storefront_banner_image ORDER BY sort_order ASC, created_at ASC, id ASC',
        );

        self::assertCount(3, $rows);
        self::assertSame(
            ['/uploads/products/cccc.png', '/uploads/products/aaaa.png', '/uploads/products/bbbb.png'],
            array_column($rows, 'image_path'),
        );
        self::assertSame([0, 1, 2], array_map(static fn (array $row): int => (int) $row['sort_order'], $rows));
    }

    public function testTheAdminReadReturnsEveryStoredSlideWithItsIdentity(): void
    {
        $this->commands()->dispatch(new BannerReplaceImages([
            '/uploads/products/first.png',
            '/uploads/products/second.png',
        ]));

        $view = $this->adminView();

        self::assertSame(['/uploads/products/first.png', '/uploads/products/second.png'], $view->paths());
        self::assertNotNull($view->images[0]->id);
        self::assertNotNull($view->images[1]->id);
    }

    public function testTheStoredCountIsAlwaysWhatTheAdminSelected(): void
    {
        foreach ([1, 3, 7, 1] as $selected) {
            $paths = [];
            for ($index = 1; $index <= $selected; ++$index) {
                $paths[] = sprintf('/uploads/products/slide-%02d.png', $index);
            }

            $this->commands()->dispatch(new BannerReplaceImages($paths));

            self::assertSame($selected, $this->storedCount(), sprintf('%d images selected', $selected));
            self::assertSame($paths, $this->adminView()->paths());
        }
    }

    public function testReorderingMovesTheStoredSequenceAndEveryReadFollowsIt(): void
    {
        $this->commands()->dispatch(new BannerReplaceImages([
            '/uploads/products/a.png',
            '/uploads/products/b.png',
            '/uploads/products/c.png',
        ]));

        /** @var list<string> $ids */
        $ids = array_map(
            static fn (array $row): string => (string) $row['id'],
            $this->db()->fetchAllAssociative('SELECT id FROM storefront_banner_image ORDER BY sort_order ASC, created_at ASC, id ASC'),
        );
        self::assertCount(3, $ids);

        $this->commands()->dispatch(new BannerReorderImages([$ids[2], $ids[0], $ids[1]]));

        self::assertSame(
            ['/uploads/products/c.png', '/uploads/products/a.png', '/uploads/products/b.png'],
            $this->adminView()->paths(),
        );
        self::assertSame([0, 1, 2], $this->storedSortOrders());
    }

    public function testRemovingOneSlideLeavesTheOthersAndNeverTouchesTheFile(): void
    {
        // The uploads volume is created by the Docker image and is not writable for this user, so the
        // proof uses whatever a real dashboard upload already put there: its reference goes into the
        // banner, leaves the banner again, and the file must still be on disk afterwards.
        $listing = $this->uploadedListing();
        $uploaded = $listing[0] ?? null;

        $paths = ['/uploads/products/removed-slide.png', '/uploads/products/kept-slide.png'];
        if (null !== $uploaded) {
            array_unshift($paths, '/uploads/products/'.$uploaded);
        }

        $this->commands()->dispatch(new BannerReplaceImages($paths));

        $view = $this->adminView();
        $this->commands()->dispatch(new BannerRemoveImage((string) $view->images[0]->id));

        self::assertSame(['/uploads/products/removed-slide.png', '/uploads/products/kept-slide.png'], $this->adminView()->paths());
        self::assertSame(
            $listing,
            $this->uploadedListing(),
            'R22 — removing a reference must not delete or move a file under public/uploads/',
        );

        if (null !== $uploaded) {
            self::assertFileExists($this->uploadsDirectory().'/'.$uploaded);
        }
    }

    public function testRemovingAnUnknownSlideFailsAndWritesNothing(): void
    {
        $this->commands()->dispatch(new BannerReplaceImages(['/uploads/products/only.png']));

        try {
            $this->commands()->dispatch(new BannerRemoveImage('00000000-0000-0000-0000-000000000000'));
            self::fail('The unknown slide must be refused.');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(BannerImageNotFoundException::class, $exception->getPrevious());
        }

        self::assertSame(['/uploads/products/only.png'], $this->adminView()->paths());
    }

    public function testARefusedWriteLeavesTheStoredSetExactlyAsItWas(): void
    {
        $this->commands()->dispatch(new BannerReplaceImages([
            '/uploads/products/keep-one.png',
            '/uploads/products/keep-two.png',
        ]));

        $tooMany = [];
        for ($index = 1; $index <= 11; ++$index) {
            $tooMany[] = sprintf('/uploads/products/too-many-%02d.png', $index);
        }

        $refusals = [
            'no image selected' => [],
            'eleven images selected' => $tooMany,
            'duplicate slide' => ['/uploads/products/twice.png', '/uploads/products/twice.png'],
            'empty reference' => [''],
            'external url' => ['https://cdn.example.com/hero.png'],
            'outside the uploads area' => ['/etc/passwd'],
        ];

        foreach ($refusals as $reason => $paths) {
            try {
                $this->commands()->dispatch(new BannerReplaceImages($paths));
                self::fail(sprintf('Expected the %s write to be refused.', $reason));
            } catch (HandlerFailedException $exception) {
                self::assertInstanceOf(BannerRejectedException::class, $exception->getPrevious(), $reason);
            }

            self::assertSame(
                ['/uploads/products/keep-one.png', '/uploads/products/keep-two.png'],
                $this->adminView()->paths(),
                $reason,
            );
        }
    }

    public function testSlidesWithACollidedSortOrderStillResolveToAStableOrder(): void
    {
        // Two concurrent admin writes can store the same sort_order: the id tie-break is what makes the
        // read deterministic, so a stored tie must never fall back to an unqualified SELECT.
        $sameInstant = '2026-10-20 10:00:00+00';
        $rows = [
            ['id' => '00000000-0000-7000-8000-000000000001', 'image_path' => '/uploads/products/z-first.png'],
            ['id' => '00000000-0000-7000-8000-000000000002', 'image_path' => '/uploads/products/a-second.png'],
        ];

        foreach ($rows as $row) {
            $this->db()->insert('storefront_banner_image', [
                'id' => $row['id'],
                'banner_id' => 1,
                'image_path' => $row['image_path'],
                'sort_order' => 0,
                'created_at' => $sameInstant,
            ]);
        }

        self::assertSame(
            ['/uploads/products/z-first.png', '/uploads/products/a-second.png'],
            $this->adminView()->paths(),
        );

        $again = $this->adminView();
        self::assertSame($again->paths(), $this->adminView()->paths(), 'the tie resolves the same way twice');
    }

    public function testAPublicRequesterSeesTheOrderButNotTheSlideIdentities(): void
    {
        $this->commands()->dispatch(new BannerReplaceImages([
            '/uploads/products/public-a.png',
            '/uploads/products/public-b.png',
        ]));

        $view = $this->read([]);

        self::assertSame(['/uploads/products/public-a.png', '/uploads/products/public-b.png'], $view->paths());
        self::assertNull($view->images[0]->id, 'QRY-004 — slide identities are not public');
        self::assertSame(
            ['images' => ['/uploads/products/public-a.png', '/uploads/products/public-b.png']],
            $view->toPublicApi(),
        );
    }

    public function testAnEmptyBannerReadsAsAnEmptyArrayAndNotAsAnError(): void
    {
        self::assertSame(0, $this->storedCount());

        self::assertSame(['images' => []], $this->read([])->toPublicApi());
        self::assertSame(['items' => [], 'count' => 0], $this->adminView()->toAdminApi());
    }

    /**
     * @param list<string> $requesterRoles
     *
     * @throws \RuntimeException when the query found no handler
     */
    private function read(array $requesterRoles): BannerView
    {
        /** @var MessageBusInterface $queryBus */
        $queryBus = static::getContainer()->get('messenger.bus.query');
        $handled = $queryBus->dispatch(new GetBannerImages($requesterRoles))->last(HandledStamp::class);

        if (null === $handled) {
            throw new \RuntimeException('The banner read must be handled on query.bus');
        }

        /** @var BannerView $view */
        $view = $handled->getResult();

        return $view;
    }

    private function adminView(): BannerView
    {
        return $this->read(['ROLE_ADMIN']);
    }

    private function commands(): MessageBusInterface
    {
        /** @var MessageBusInterface $bus */
        $bus = static::getContainer()->get('messenger.bus.command');

        return $bus;
    }

    private function db(): Connection
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        return $connection;
    }

    private function uploadsDirectory(): string
    {
        return dirname(__DIR__, 3).'/public/uploads/products';
    }

    /** @return list<string> the files the dashboard upload endpoint has left in the uploads area */
    private function uploadedListing(): array
    {
        $directory = $this->uploadsDirectory();
        $files = is_dir($directory) ? scandir($directory) : [];
        if (!is_array($files)) {
            return [];
        }

        $listing = [];
        foreach ($files as $file) {
            if ('.' !== $file && '..' !== $file) {
                $listing[] = $file;
            }
        }
        sort($listing);

        return $listing;
    }

    private function storedCount(): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM storefront_banner_image WHERE banner_id = 1');
    }

    /** @return list<int> */
    private function storedSortOrders(): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['sort_order'],
            $this->db()->fetchAllAssociative('SELECT sort_order FROM storefront_banner_image ORDER BY sort_order ASC, created_at ASC, id ASC'),
        );
    }
}
