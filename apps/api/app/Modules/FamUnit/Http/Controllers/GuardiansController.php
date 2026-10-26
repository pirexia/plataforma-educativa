<?php

namespace App\Modules\FamUnit\Http\Controllers;

use App\Modules\FamUnit\Application\FamilyRefs;
use App\Modules\FamUnit\Application\GuardianService;
use App\Modules\FamUnit\Domain\Models\Guardian;
use App\Modules\FamUnit\Http\Requests\IndexGuardiansRequest;
use App\Modules\FamUnit\Http\Requests\StoreGuardianRequest;
use App\Modules\FamUnit\Http\Requests\UpdateGuardianRequest;
use App\Modules\FamUnit\Http\Resources\GuardianResource;
use App\Support\Api\PagePaginatedResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REQ-FAM-UNIT/api.md §2.2 (tutores, 1.14-a). Permisos del recurso `familia`. El alta parte de un usuario que ya
 * existe en `Core` (`RN-FAM-UNIT-10`).
 */
class GuardiansController extends FamUnitController
{
    public function index(IndexGuardiansRequest $request, GuardianService $service, FamilyRefs $refs): JsonResponse
    {
        $query = $service->listing($this->decision($request), $this->actor($request), [
            'family_unit' => $request->list('family_unit'),
            'user' => $request->list('user'),
            'sort' => $request->sortParam('created_at'),
        ]);

        $page = $query->paginate($request->integer('per_page', 25))->withQueryString();
        $refs->preloadUsers(array_map(static fn (Guardian $g): int => (int) $g->person_id, $page->items()));

        return PagePaginatedResponse::make($page, GuardianResource::class);
    }

    public function store(StoreGuardianRequest $request, GuardianService $service): JsonResponse
    {
        $input = $request->payload();
        $guardian = $service->create($input['user'], $input['family_unit']);

        return (new GuardianResource($guardian))
            ->response()
            ->setStatusCode(201)
            ->header('Location', url("/api/v1/guardians/{$guardian->public_id}"));
    }

    public function show(Request $request, string $publicId, GuardianService $service): GuardianResource
    {
        return new GuardianResource($service->findScoped($publicId, $this->decision($request), $this->actor($request)));
    }

    public function update(UpdateGuardianRequest $request, string $publicId, GuardianService $service): GuardianResource
    {
        $service->find($publicId);
        $request->assertEditable();

        return new GuardianResource($service->update($publicId));
    }

    public function destroy(string $publicId, GuardianService $service): JsonResponse
    {
        return response()->json($service->delete($publicId));
    }
}
