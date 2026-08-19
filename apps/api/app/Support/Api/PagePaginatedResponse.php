<?php

namespace App\Support\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * ADR-038 §4.3: `{data, meta}` con `current_page`, `per_page`, `total`,
 * `last_page` — sin `links` (el cliente construye sus propias URLs).
 * Laravel's `PaginatedResourceResponse` por defecto añade `links` y una
 * forma de `meta` distinta; no se usa aquí a propósito.
 */
final class PagePaginatedResponse
{
    /**
     * @param  class-string<JsonResource>  $resourceClass
     */
    public static function make(LengthAwarePaginator $paginator, string $resourceClass): JsonResponse
    {
        return response()->json([
            'data' => $resourceClass::collection($paginator->items())->resolve(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}
