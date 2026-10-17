<?php

namespace App\Modules\Acad\Http\Controllers;

use App\Modules\Acad\Application\GradingScaleService;
use App\Modules\Acad\Application\StructureYears;
use App\Modules\Acad\Domain\Models\GradingScale;
use App\Modules\Acad\Http\Requests\IndexGradingScalesRequest;
use App\Modules\Acad\Http\Requests\StoreGradingScaleRequest;
use App\Modules\Acad\Http\Requests\UpdateGradingScaleRequest;
use App\Modules\Acad\Http\Resources\GradingScaleResource;
use App\Support\Api\PagePaginatedResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * REQ-ACAD/api.md §11.3 (escalas de calificación, 1.11d-b). Controlador fino:
 * la validación de negocio vive en `GradingScaleValidator` y la escritura en
 * `GradingScaleService`. Permisos del recurso `configuracion_academica`
 * (`RN-ACAD-50`). Los valores no tienen *endpoint* propio (`RN-ACAD-80`).
 */
class GradingScalesController extends StructureController
{
    /**
     * api.md §11.5: `?academic_year=` o el activo; filtro `scale_type`
     * (múltiple); orden por `code` (defecto) con desempate por `id`. Cada
     * escala viaja con sus valores, cargados de una vez.
     */
    public function index(IndexGradingScalesRequest $request, StructureYears $years): JsonResponse
    {
        $param = $request->query('academic_year');
        $year = $years->forListing(is_string($param) ? $param : null, $this->actor($request));

        $sort = $request->string('sort', 'code')->value();
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';

        $query = GradingScale::query()->with('values')
            ->where('academic_year_id', $year->id)
            ->when($request->list('scale_type') !== [], fn ($q) => $q->whereIn('scale_type', $request->list('scale_type')))
            ->orderBy('code', $direction)
            ->orderBy('id');

        return PagePaginatedResponse::make($query->paginate($request->integer('per_page', 25))->withQueryString(), GradingScaleResource::class);
    }

    public function store(StoreGradingScaleRequest $request, GradingScaleService $service): JsonResponse
    {
        $input = $request->payload();
        $scale = $service->create($input['academic_year'], $input['scale_type'], $input['body'], $this->actor($request));

        return (new GradingScaleResource($scale))
            ->response()
            ->setStatusCode(201)
            ->header('Location', url("/api/v1/grading-scales/{$scale->public_id}"));
    }

    public function show(Request $request, string $publicId, GradingScaleService $service): GradingScaleResource
    {
        return new GradingScaleResource($service->find($publicId, $this->actor($request))->load('values'));
    }

    public function update(UpdateGradingScaleRequest $request, string $publicId, GradingScaleService $service): GradingScaleResource
    {
        return new GradingScaleResource($service->update($publicId, $request->payload(), $this->actor($request)));
    }

    public function destroy(Request $request, string $publicId, GradingScaleService $service): Response
    {
        $service->delete($publicId, $this->actor($request));

        return response()->noContent();
    }
}
