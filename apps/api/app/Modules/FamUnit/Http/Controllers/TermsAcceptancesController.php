<?php

namespace App\Modules\FamUnit\Http\Controllers;

use App\Modules\FamUnit\Application\FamilyRefs;
use App\Modules\FamUnit\Application\TermsAcceptanceService;
use App\Modules\FamUnit\Http\Requests\IndexTermsAcceptancesRequest;
use App\Modules\FamUnit\Http\Resources\TermsAcceptanceResource;
use Illuminate\Http\JsonResponse;

/**
 * REQ-FAM-UNIT/api.md §2.8 (1.14-c). Consulta administrativa de las aceptaciones de las condiciones de uso (permiso
 * `aceptacion_condiciones.leer`, solo ámbito `todos`). Las aceptaciones son inmutables (`RN-FAM-UNIT-64`): no hay
 * `PATCH` ni `DELETE`. Cursor (`ADR-038 §4.4`).
 */
class TermsAcceptancesController extends FamUnitController
{
    public function index(IndexTermsAcceptancesRequest $request, TermsAcceptanceService $service, FamilyRefs $refs): JsonResponse
    {
        $result = $service->history($request->termsVersions(), $request->users(), $request->cursorValue(), $request->integer('limit', 50));

        $refs->preloadActors(array_map(static fn ($row): int => (int) $row->user_id, $result['rows']));

        return response()->json([
            'data' => TermsAcceptanceResource::collection($result['rows'])->resolve(),
            'meta' => ['next_cursor' => $result['next_cursor'], 'has_more' => $result['has_more']],
        ]);
    }
}
