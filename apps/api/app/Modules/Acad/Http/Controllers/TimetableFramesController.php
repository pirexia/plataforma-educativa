<?php

namespace App\Modules\Acad\Http\Controllers;

use App\Modules\Acad\Application\StructureYears;
use App\Modules\Acad\Application\TimetableFrameService;
use App\Modules\Acad\Domain\Models\EducationLevel;
use App\Modules\Acad\Domain\Models\TimetableFrame;
use App\Modules\Acad\Http\Requests\IndexTimetableFramesRequest;
use App\Modules\Acad\Http\Requests\StoreTimetableFrameRequest;
use App\Modules\Acad\Http\Requests\UpdateTimetableFrameRequest;
use App\Modules\Acad\Http\Resources\TimetableFrameResource;
use App\Support\Api\PagePaginatedResponse;
use App\Support\I18n\TranslationsValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * REQ-ACAD/api.md §12 (marcos horarios, 1.12-a). Permisos del recurso
 * `horarios` (`permisos.md §10.1`). Las franjas viajan dentro del marco
 * (`RN-ACAD-113`), sin *endpoint* propio.
 */
class TimetableFramesController extends StructureController
{
    public function index(IndexTimetableFramesRequest $request, StructureYears $years): JsonResponse
    {
        $param = $request->query('academic_year');
        $year = $years->forListing(is_string($param) ? $param : null, $this->actor($request));

        $direction = str_starts_with($request->string('sort', 'education_level')->value(), '-') ? 'desc' : 'asc';

        $query = TimetableFrame::query()->with(['educationLevel', 'slots'])
            ->where('academic_year_id', $year->id)
            ->when($request->list('education_level') !== [], fn ($q) => $q->whereIn(
                'education_level_id',
                EducationLevel::query()->whereIn('public_id', $request->list('education_level'))->select('id'),
            ))
            ->when($request->list('frame_type') !== [], fn ($q) => $q->whereIn('frame_type', $request->list('frame_type')))
            ->orderBy(EducationLevel::query()->select('position')->whereColumn('education_levels.id', 'timetable_frames.education_level_id'), $direction)
            ->orderBy('education_level_id', $direction)
            ->orderBy('frame_type')
            ->orderBy('id');

        return PagePaginatedResponse::make($query->paginate($request->integer('per_page', 25))->withQueryString(), TimetableFrameResource::class);
    }

    public function store(StoreTimetableFrameRequest $request, TimetableFrameService $service, TranslationsValidator $translations): JsonResponse
    {
        $frame = $service->create($request->payload($translations), $this->actor($request));

        return (new TimetableFrameResource($frame->load(['educationLevel', 'slots'])))
            ->response()
            ->setStatusCode(201)
            ->header('Location', url("/api/v1/timetable-frames/{$frame->public_id}"));
    }

    public function show(Request $request, string $publicId, TimetableFrameService $service): TimetableFrameResource
    {
        return new TimetableFrameResource($service->find($publicId, $this->actor($request))->load(['educationLevel', 'slots']));
    }

    public function update(UpdateTimetableFrameRequest $request, string $publicId, TimetableFrameService $service): TimetableFrameResource
    {
        return new TimetableFrameResource($service->update($publicId, $request->payload(), $this->actor($request))->load(['educationLevel', 'slots']));
    }

    public function destroy(Request $request, string $publicId, TimetableFrameService $service): Response
    {
        $service->delete($publicId, $this->actor($request));

        return response()->noContent();
    }
}
