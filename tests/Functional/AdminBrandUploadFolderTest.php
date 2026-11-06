<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Security\CustomerUser;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * spec-run-19 R2 — the brands folder joins the admin upload allowlist: brand images enter
 * through the existing upload flow and land under public/uploads/brands under the same rules.
 */
final class AdminBrandUploadFolderTest extends WebTestCase
{
    private const string ONE_PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** @var list<string> */
    private array $userIds = [];
    /** @var list<string> */
    private array $uploadedFiles = [];
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        if ([] !== $this->userIds) {
            $connection->executeStatement('DELETE FROM customer_profile WHERE id IN (:v)', ['v' => $this->userIds], ['v' => ArrayParameterType::STRING]);
        }
        foreach ($this->uploadedFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    private function createUser(string $role): CustomerUser
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $id = Uuid::v4()->toRfc4122();
        $email = sprintf('admin.brand-upload.%s@example.com', substr($id, 0, 8));
        $connection->insert('customer_profile', [
            'id' => $id,
            'name' => 'Brand Upload Test '.$role,
            'email' => $email,
            'role' => $role,
            'status' => 'active',
        ]);
        $this->userIds[] = $id;

        return new CustomerUser($id, $email, null, $role, 'active');
    }

    private function tempPng(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($path, base64_decode(self::ONE_PIXEL_PNG, true));
        $this->tempFiles[] = $path;

        return $path;
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }

    public function testBrandFolderUploadStoresUnderUploadsBrands(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));

        $client->request('POST', '/api/v1/admin/catalog/uploads', ['folder' => 'brands'], [
            'file' => new UploadedFile($this->tempPng(), 'brand.png', 'image/png', null, true),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $payload = $this->json($client);
        self::assertStringStartsWith('/uploads/brands/', (string) $payload['path']);
        self::assertStringEndsWith('.png', (string) $payload['path']);

        /** @var string $projectDir */
        $projectDir = static::getContainer()->getParameter('kernel.project_dir');
        $stored = $projectDir.'/public'.$payload['path'];
        $this->uploadedFiles[] = $stored;
        self::assertFileExists($stored);
    }

    /** The existing content-sniffing rules apply to the brands folder unchanged. */
    public function testBrandFolderStillAcceptsRealImagesOnly(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));

        $fake = (string) tempnam(sys_get_temp_dir(), 'fake');
        file_put_contents($fake, '<?php echo "not an image";');
        $this->tempFiles[] = $fake;
        $client->request('POST', '/api/v1/admin/catalog/uploads', ['folder' => 'brands'], [
            'file' => new UploadedFile($fake, 'evil.png', 'image/png', null, true),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('Only JPEG, PNG and WebP images are accepted.', (string) $this->json($client)['error']);
    }
}
