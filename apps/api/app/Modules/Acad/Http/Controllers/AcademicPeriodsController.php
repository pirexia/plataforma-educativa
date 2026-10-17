<?php

namespace App\Modules\Acad\Http\Controllers;

use App\Modules\Acad\Application\AcademicPeriodService;
use App\Modules\Acad\Application\StructureYears;
use App\Modules\Acad\Domain\Models\AcademicPeriod;
use App\Modules\Acad\Domain\Models\EducationLevel;
use App\Modules\Acad\Http\Requests\IndexAcademicPeriodsRequest;
use App\Modules\Acad\Http\Requests\StoreAcademicPeriodRequest;
use App\Modules\Acad\Http\Requests\UpdateAcademicPeriodRequest;
use App\Modules\Acad\Http\Resources\AcademicPeriodResource;
use App\Support\Api\PagePaginatedResponse;
use App\Support\I18n\TranslationsValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * REQ-ACAD/api.md §11.3 (períodos lectivos, 1.11d-a). Controlador fino: la
 * validación de negocio vive en la petición y en `AcademicPeriodService`.
 * Permisos del recurso `configuracion_academica` (`RN-ACAD-50`).
 */
class AcademicPeriodsController extends StructureController
{
    /**
     * `RN-ACAD-60`, api.md §11.5: orden por defecto cronológico (`starts_on`,
     * desempate por `position` e `id`); `sort=position` desempata por
     * `starts_on`. Filtros combinados con `AND`; un `education_level` de otro
     * curso, o inexistente, da una colección vacía.
     */
    public function index(IndexAcademicPeriodsRequest $request, StructureYears $years): JsonResponse
    {
        $param = $request->query('academic_year');
        $year = $years->forListing(is_string($param) ? $param : null, $this->actor($request));

        $sort = $request->string('sort', 'starts_on')->value();
        $column = ltrim($sort, '-');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';

        $query = AcademicPeriod::query()->with('educationLevel')
            ->where('academic_year_id', $year->id)
            ->when($request->list('education_level') !== [], fn ($q) => $q->whereIn(
                'education_level_id',
                EducationLevel::query()->whereIn('public_id', $request->list('education_level'))->select('id'),
            ))
            ->when($request->list('period_type') !== [], fn ($q) => $q->whereIn('period_type', $request->list('period_type')))
            ->orderBy($column, $direction)
            ->orderBy($column === 'starts_on' ? 'position' : 'starts_on')
            ->orderBy('id');

        return PagePaginatedResponse::make($query->paginate($request->integer('per_page', 25))->withQueryString(), AcademicPeriodResource::class);
    }

    public function store(StoreAcademicPeriodRequest $request, TranslationsValidator $translations, AcademicPeriodService $service): JsonResponse
    {
        $period = $service->create($request->payload($translations), $this->actor($request));
        $period->load('educationLevel');

        return (new AcademicPeriodResource($period))
            ->response()
            ->setStatusCode(201)
            ->header('Location', url("/api/v1/academic-periods/{$period->public_id}"));
    }

    public function show(Request $request, string $publicId, AcademicPeriodService $service): AcademicPeriodResource
    {
        return new AcademicPeriodResource($service->find($publicId, $this->actor($request))->load('educationLevel'));
    }

    public function update(UpdateAcademicPeriodRequest $request, string $publicId, AcademicPeriodService $service): AcademicPeriodResource
    {
        return new AcademicPeriodResource($service->update($publicId, $request->payload(), $this->actor($request))->load('educationLevel'));
    }

    public function destroy(Request $request, string $publicId, AcademicPeriodService $service): Response
    {
        $service->delete($publicId, $this->actor($request));

        return response()->noContent();
    }
}
