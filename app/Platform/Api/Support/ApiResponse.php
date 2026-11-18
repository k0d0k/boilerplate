<?php

namespace App\Platform\Api\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Phase 8B — one JSON envelope for the whole patient API.
 *
 * Success:  { "data": <payload>, "meta": { ... } }
 * Error:    { "error": { "code": "...", "message": "...", "details": {...} } }
 *
 * Keeping a single shape lets the mobile client parse every response — list,
 * detail and failure — with one small set of helpers.
 */
final class ApiResponse
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public static function ok(mixed $data, array $meta = [], int $status = 200): JsonResponse
    {
        $payload = ['data' => $data];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return new JsonResponse($payload, $status);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function error(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return new JsonResponse(['error' => $error], $status);
    }

    /**
     * Paginate a collection that has already been mapped through
     * {@see LengthAwarePaginator::through()}.
     */
    public static function paginated(LengthAwarePaginator $paginator): JsonResponse
    {
        return self::ok($paginator->getCollection()->values()->all(), [
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}
