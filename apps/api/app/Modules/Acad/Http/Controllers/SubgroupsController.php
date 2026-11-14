<?php

namespace App\Modules\Acad\Http\Controllers;

use App\Modules\Acad\Application\SubgroupService;
use App\Modules\Acad\Domain\Models\Subgroup;
use App\Modules\Acad\Http\Requests\StoreSubgroupRequest;
use App\Modules\Acad\Http\Requests\UpdateSimpleNameRequest;
use App\Modules\Acad\Http\Resources\SubgroupResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * REQ-ACAD/api.md §2 (subgrupos). Los listados anidados no admiten
 * `academic_year` (es el del grupo) ni se paginan [DERIVADA]: orden `position`
 * y luego `code`.
 */
class SubgroupsController extends StructureController
{
    public function index(Request $request, string $groupPublicId, SubgroupService $service): JsonResponse
    {
        $group = $service->groupOf($groupPublicId, $this->actor($request));

        $subgroups = Subgroup::query()->with('group')
            ->where('group_id', $group->id)
            ->orderBy('position')->orderBy('code')->orderBy('id')
            ->get();

        return response()->json(['data' => SubgroupResource::collection($subgroups)->resolve()]);
    }

    public function store(StoreSubgroupRequest $request, string $groupPublicId, SubgroupService $service): JsonResponse
    {
        $actor = $this->actor($request);
        // El grupo de la ruta se resuelve antes que el cuerpo: `404` y no `422` (CA-ACAD-007).
        $service->groupOf($groupPublicId, $actor);

        $subgroup = $service->create($groupPublicId, $request->payload(), $actor);

        return (new SubgroupResource($subgroup))
            ->response()
            ->setStatusCode(201)
            ->header('Location', url("/api/v1/subgroups/{$subgroup->public_id}"));
    }

    public function show(Request $request, string $publicId, SubgroupService $service): SubgroupResource
    {
        return new SubgroupResource($service->find($publicId, $this->actor($request)));
    }

    public function update(UpdateSimpleNameRequest $request, string $publicId, SubgroupService $service): SubgroupResource
    {
        return new SubgroupResource($service->update($publicId, $request->payload(), $this->actor($request)));
    }

    public function destroy(Request $request, string $publicId, SubgroupService $service): Response
    {
        $service->delete($publicId, $this->actor($request));

        return response()->noContent();
    }
}
