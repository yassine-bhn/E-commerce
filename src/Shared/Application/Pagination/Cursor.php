<?php

declare(strict_types=1);

namespace App\Shared\Application\Pagination;

final class Cursor
{
    public static function encode(string $key, string $id): string
    {
        $json = json_encode(['k' => $key, 'i' => $id], \JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @return array{key: string, id: string}
     */
    public static function decode(string $raw): array
    {
        if ('' === $raw) {
            throw InvalidCursorException::create();
        }

        $decoded = base64_decode(strtr($raw, '-_', '+/'), true);

        if (false === $decoded) {
            throw InvalidCursorException::create();
        }

        try {
            $data = json_decode($decoded, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw InvalidCursorException::create();
        }

        if (!is_array($data) || !isset($data['k'], $data['i']) || !is_string($data['k']) || !is_string($data['i'])) {
            throw InvalidCursorException::create();
        }

        return ['key' => $data['k'], 'id' => $data['i']];
    }
}
