<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service;

use App\Catalog\Domain\Model\BannerRejectedException;

/**
 * ERR-001 — the shape of the dashboard payload is settled here, at the edge, before a command
 * reaches `command.bus`; what the payload *means* (how many slides, which references, which order)
 * is the aggregate's business (AGG-001) and is enforced in `BannerSet`.
 */
final class BannerPayload
{
    /**
     * The ordered image list of a replace-all write.
     *
     * @return list<string>
     */
    public function imagePathsFrom(mixed $body): array
    {
        return $this->stringListFrom($body, 'images', 'dashboard upload paths', 'image path strings');
    }

    /**
     * The ordered id list of a reorder write.
     *
     * @return list<string>
     */
    public function imageIdsFrom(mixed $body): array
    {
        return $this->stringListFrom($body, 'imageIds', 'banner image ids', 'banner image id strings');
    }

    /**
     * @param array<array-key, mixed>|null $body
     *
     * @return list<string>
     */
    private function stringListFrom(mixed $body, string $field, string $ofWhat, string $ofItems): array
    {
        if (!is_array($body)) {
            throw new BannerRejectedException('The request body must be a JSON object.');
        }

        $values = $body[$field] ?? null;
        if (!is_array($values) || !array_is_list($values)) {
            throw BannerRejectedException::fieldMustBeAList($field, $ofWhat);
        }

        $items = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw BannerRejectedException::fieldMustContainStrings($field, $ofItems);
            }

            $items[] = trim($value);
        }

        return $items;
    }
}
