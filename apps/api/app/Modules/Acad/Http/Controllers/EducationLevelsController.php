<?php

namespace App\Modules\Acad\Http\Controllers;

use App\Modules\Acad\Application\EducationLevelService;
use App\Modules\Acad\Application\StructureYears;
use App\Modules\Acad\Domain\Models\EducationLevel;
use App\Modules\Acad\Http\Requests\IndexEducationLevelsRequest;
use App\Modules\Acad\Http\Requests\StoreEducationLevelRequest;
use App\Modules\Acad\Http\Requests\UpdateEducationLevelRequest;
use App\Modules\Acad\Http\Resources\EducationLevelResource;
use App\Support\I18n\TranslationsValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * REQ-ACAD/api.md §2 (etapas). Controlador fino: la validación de negocio
 * vive en la petición y en `EducationLevelService`.
 */
class EducationLevelsController extends StructureController
{
    use ListsStructure;

    public function index(IndexEducationLevelsRequest $request, StructureYears $years): JsonResponse
    {
        $year = $this->listingYear($request, $years);

        $query = EducationLevel::query()
            ->when($request->list('stage') !== [], fn ($q) => $q->whereIn('stage', $request->list('stage')))
            ->when($request->list('regime') !== [], fn ($q) => $q->whereIn('regime', $request->list('regime')));

        return $this->paginated($query, $request, $year->id, EducationLevelResource::class);
    }

    public function store(StoreEducationLevelRequest $request, TranslationsValidator $translations, EducationLevelService $service): JsonResponse
    {
        $level = $service->create($request->payload($translations), $this->actor($request));

        return (new EducationLevelResource($level))
            ->response()
            ->setStatusCode(201)
            ->header('Location', url("/api/v1/education-levels/{$level->public_id}"));
    }

    public function show(Request $request, string $publicId, EducationLevelService $service): EducationLevelResource
    {
        return new EducationLevelResource($service->find($publicId, $this->actor($request)));
    }

    public function update(UpdateEducationLevelRequest $request, string $publicId, EducationLevelService $service): EducationLevelResource
    {
        return new EducationLevelResource($service->update($publicId, $request->payload(), $this->actor($request)));
    }

    public function destroy(Request $request, string $publicId, EducationLevelService $service): Response
    {
        $service->delete($publicId, $this->actor($request));

        return response()->noContent();
    }
}
