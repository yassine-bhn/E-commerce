<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Application\Pagination\CursorRequest;
use App\Shared\Application\Pagination\PageRequest;
use Symfony\Component\HttpFoundation\Request;

final class ListQueryParams
{
    public static function page(
        Request $request,
        int $defaultSize = PageRequest::DEFAULT_SIZE,
        int $maxSize = PageRequest::MAX_SIZE,
    ): PageRequest {
        return new PageRequest(
            $request->query->getInt('page', 1),
            $request->query->getInt('size', $defaultSize),
            $maxSize,
        );
    }

    public static function cursor(
        Request $request,
        int $defaultLimit = CursorRequest::DEFAULT_LIMIT,
        int $maxLimit = CursorRequest::MAX_LIMIT,
    ): CursorRequest {
        return new CursorRequest(
            $request->query->getInt('limit', $defaultLimit),
            self::raw($request, 'cursor'),
            $maxLimit,
        );
    }

    public static function text(Request $request, string $key = 'q'): ?string
    {
        $value = self::raw($request, $key);

        if (null === $value) {
            return null;
        }

        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }

    public static function intOrNull(Request $request, string $key): ?int
    {
        $value = self::raw($request, $key);

        if (null === $value || '' === $value || !is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    public static function boolOrNull(Request $request, string $key): ?bool
    {
        $value = self::raw($request, $key);

        if (null === $value) {
            return null;
        }

        return filter_var($value, \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE);
    }

    /**
     * Strict `Y-m-d` reader for date-range filters: a malformed or impossible date is ignored
     * (returns `null`) rather than rejected, matching how out-of-range `page`/`size` are clamped.
     */
    public static function dateOrNull(Request $request, string $key): ?\DateTimeImmutable
    {
        $value = self::raw($request, $key);

        if (null === $value || '' === trim($value)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        return false !== $date && $date->format('Y-m-d') === trim($value) ? $date : null;
    }

    private static function raw(Request $request, string $key): ?string
    {
        $value = $request->query->get($key);

        return is_string($value) ? $value : null;
    }
}
