<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Http;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Stores a product image under public/uploads/products and returns its public URL.
 * Only real JPEG / PNG / WebP files are accepted: the type is detected from the file
 * content, never from the client-supplied name or MIME type.
 */
#[Route('/api/v1/admin/catalog/uploads')]
#[IsGranted('ROLE_SUPER_ADMIN')]
final class AdminUploadController
{
    private const int MAX_BYTES = 2 * 1024 * 1024;

    private const array EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /** Sub-folders of public/uploads the dashboard may write to (the banner only accepts /uploads/ references). */
    private const array FOLDERS = ['products', 'banner'];

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    #[Route('', name: 'admin_catalog_upload_image', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return new JsonResponse(['error' => 'A "file" field is required.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$file->isValid()) {
            return new JsonResponse(['error' => 'The upload failed: '.$file->getErrorMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $size = $file->getSize();
        if (false === $size || $size > self::MAX_BYTES) {
            return new JsonResponse(['error' => 'The image must not exceed 2 MB.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $mime = (string) $file->getMimeType();
        $extension = self::EXTENSIONS[$mime] ?? null;
        if (null === $extension) {
            return new JsonResponse(['error' => 'Only JPEG, PNG and WebP images are accepted.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $folder = $request->request->getString('folder', 'products');
        if (!in_array($folder, self::FOLDERS, true)) {
            return new JsonResponse(['error' => 'Unknown upload folder.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $directory = $this->projectDir.'/public/uploads/'.$folder;
        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            return new JsonResponse(['error' => 'The upload directory is not writable.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $name = Uuid::v7()->toRfc4122().'.'.$extension;
        $file->move($directory, $name);

        $path = '/uploads/'.$folder.'/'.$name;

        return new JsonResponse([
            'path' => $path,
            'url' => $request->getSchemeAndHttpHost().$path,
        ], Response::HTTP_CREATED);
    }
}
