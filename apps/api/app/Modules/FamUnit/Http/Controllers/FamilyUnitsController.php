<?php

namespace App\Modules\FamUnit\Http\Controllers;

use App\Models\User;
use App\Modules\FamUnit\Application\FamilyScope;
use App\Modules\FamUnit\Application\FamilyUnitService;
use App\Modules\FamUnit\Application\FamUnitErrors;
use App\Modules\FamUnit\Http\Requests\IndexFamilyUnitsRequest;
use App\Modules\FamUnit\Http\Requests\StoreFamilyUnitRequest;
use App\Modules\FamUnit\Http\Requests\UpdateFamilyUnitRequest;
use App\Modules\FamUnit\Http\Resources\FamilyUnitResource;
use App\Support\Api\PagePaginatedResponse;
use App\Support\Authorization\PermissionResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * REQ-FAM-UNIT/api.md §2.1 (unidades familiares, 1.14-a). Permisos del recurso `familia` (`permisos.md §2`). Listado y
 * detalle acotados por ámbito con `ScopedQuery`: lo no alcanzado es `404` (`ADR-044 §4.2`).
 */
class FamilyUnitsController extends FamUnitController
{
    public function index(IndexFamilyUnitsRequest $request, FamilyUnitService $service, FamilyScope $scope, PermissionResolver $permissions): JsonResponse
    {
        $actor = $this->actor($request);
        $decision = $this->decision($request);
        $includeDeleted = $request->flag('include_deleted') === true;

        // api.md §3: `include_deleted` solo con `familia.eliminar`.
        if ($includeDeleted && ! $permissions->decide($actor, 'familia.eliminar')->permitted) {
            throw FamUnitErrors::filterForbidden();
        }

        $query = $service->listing($decision, $actor, [
            'q' => $request->query('q') === null ? null : (string) $request->query('q'),
            'has_principal' => $request->flag('has_principal'),
            'include_deleted' => $includeDeleted,
        ]);

        $sort = $request->sortParam('code');
        $query->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')->orderBy('id');

        $page = $query->paginate($request->integer('per_page', 25))->withQueryString();
        $service->attachCounts($page->items(), $this->reach($decision->isUnrestricted(), $scope, $actor));

        return PagePaginatedResponse::make($page, FamilyUnitResource::class);
    }

    public function store(StoreFamilyUnitRequest $request, FamilyUnitService $service): JsonResponse
    {
        $unit = $service->create($request->payload()['code']);
        $service->attachCounts([$unit], null);

        return (new FamilyUnitResource($unit->load('principalGuardian')))
            ->response()
            ->setStatusCode(201)
            ->header('Location', url("/api/v1/family-units/{$unit->public_id}"));
    }

    public function show(Request $request, string $publicId, FamilyUnitService $service, FamilyScope $scope): FamilyUnitResource
    {
        $actor = $this->actor($request);
        $decision = $this->decision($request);

        $unit = $service->findScoped($publicId, $decision, $actor)->load('principalGuardian');
        $service->attachCounts([$unit], $this->reach($decision->isUnrestricted(), $scope, $actor));

        return new FamilyUnitResource($unit);
    }

    public function update(UpdateFamilyUnitRequest $request, string $publicId, FamilyUnitService $service): FamilyUnitResource
    {
        $service->find($publicId);
        $unit = $service->update($publicId, $request->payload())->load('principalGuardian');
        $service->attachCounts([$unit], null);

        return new FamilyUnitResource($unit);
    }

    public function destroy(string $publicId, FamilyUnitService $service): Response
    {
        $service->delete($publicId);

        return response()->noContent();
    }

    public function restore(string $publicId, FamilyUnitService $service): FamilyUnitResource
    {
        $unit = $service->restore($publicId)->load('principalGuardian');
        $service->attachCounts([$unit], null);

        return new FamilyUnitResource($unit);
    }

    /**
     * Con ámbito `todos` los recuentos no se acotan (`null`); si no, solo cuentan lo que alcanza el sujeto
     * (`RN-FAM-UNIT-36`). Un sujeto sin vínculos activos no llega aquí con filas: el listado ya sale vacío.
     *
     * @return Builder<*>|null
     */
    private function reach(bool $unrestricted, FamilyScope $scope, User $actor): ?Builder
    {
        return $unrestricted ? null : $scope->reachableStudentIds($actor);
    }
}
