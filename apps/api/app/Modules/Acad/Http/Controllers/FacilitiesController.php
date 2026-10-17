<?php

namespace App\Modules\Acad\Http\Controllers;

use App\Modules\Acad\Application\FacilityService;
use App\Modules\Acad\Domain\Models\Facility;
use App\Modules\Acad\Http\Requests\IndexFacilitiesRequest;
use App\Modules\Acad\Http\Requests\StoreFacilityRequest;
use App\Modules\Acad\Http\Requests\UpdateFacilityRequest;
use App\Modules\Acad\Http\Resources\FacilityResource;
use App\Support\Api\PagePaginatedResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * REQ-ACAD/api.md §12 (espacios, 1.12-a). Permisos del recurso `espacios`
 * (`permisos.md §10.1`). Sin curso académico: no hay selector ni `404` de curso
 * cerrado (`OPEN-ACAD-33`).
 */
class FacilitiesController extends StructureController
{
    public function index(IndexFacilitiesRequest $request): JsonResponse
    {
        $direction = str_starts_with($request->string('sort', 'code')->value(), '-') ? 'desc' : 'asc';

        $query = Facility::query()
            ->when($request->list('facility_type') !== [], fn ($q) => $q->whereIn('facility_type', $request->list('facility_type')))
            ->orderBy('code', $direction)
            ->orderBy('id');

        return PagePaginatedResponse::make($query->paginate($request->integer('per_page', 25))->withQueryString(), FacilityResource::class);
    }

    public function store(StoreFacilityRequest $request, FacilityService $service): JsonResponse
    {
        $facility = $service->create($request->payload());

        return (new FacilityResource($facility))
            ->response()
            ->setStatusCode(201)
            ->header('Location', url("/api/v1/facilities/{$facility->public_id}"));
    }

    public function show(string $publicId, FacilityService $service): FacilityResource
    {
        return new FacilityResource($service->find($publicId));
    }

    public function update(UpdateFacilityRequest $request, string $publicId, FacilityService $service): FacilityResource
    {
        return new FacilityResource($service->update($publicId, $request->payload()));
    }

    public function destroy(string $publicId, FacilityService $service): Response
    {
        $service->delete($publicId);

        return response()->noContent();
    }
}
