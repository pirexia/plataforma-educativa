<?php

namespace App\Modules\Acad\Http\Controllers;

use App\Modules\Acad\Application\StructureYears;
use App\Modules\Acad\Application\SubjectService;
use App\Modules\Acad\Domain\Models\Course;
use App\Modules\Acad\Domain\Models\EducationLevel;
use App\Modules\Acad\Domain\Models\Subject;
use App\Modules\Acad\Http\Requests\IndexByCourseRequest;
use App\Modules\Acad\Http\Requests\StoreSubjectRequest;
use App\Modules\Acad\Http\Requests\UpdateTranslatedNameRequest;
use App\Modules\Acad\Http\Resources\SubjectResource;
use App\Support\I18n\TranslationsValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** REQ-ACAD/api.md §2 (asignaturas). */
class SubjectsController extends StructureController
{
    use ListsStructure;

    public function index(IndexByCourseRequest $request, StructureYears $years): JsonResponse
    {
        $year = $this->listingYear($request, $years);

        $query = Subject::query()->with('course')
            ->when($request->list('course') !== [], fn ($q) => $q->whereIn(
                'course_id',
                Course::query()->whereIn('public_id', $request->list('course'))->select('id'),
            ))
            ->when($request->list('education_level') !== [], fn ($q) => $q->whereIn(
                'course_id',
                Course::query()->whereIn(
                    'education_level_id',
                    EducationLevel::query()->whereIn('public_id', $request->list('education_level'))->select('id'),
                )->select('id'),
            ));

        return $this->paginated($query, $request, $year->id, SubjectResource::class);
    }

    public function store(StoreSubjectRequest $request, TranslationsValidator $translations, SubjectService $service): JsonResponse
    {
        $subject = $service->create($request->payload($translations), $this->actor($request));

        return (new SubjectResource($subject))
            ->response()
            ->setStatusCode(201)
            ->header('Location', url("/api/v1/subjects/{$subject->public_id}"));
    }

    public function show(Request $request, string $publicId, SubjectService $service): SubjectResource
    {
        return new SubjectResource($service->find($publicId, $this->actor($request)));
    }

    public function update(UpdateTranslatedNameRequest $request, string $publicId, SubjectService $service): SubjectResource
    {
        return new SubjectResource($service->update($publicId, $request->payload(), $this->actor($request)));
    }

    public function destroy(Request $request, string $publicId, SubjectService $service): Response
    {
        $service->delete($publicId, $this->actor($request));

        return response()->noContent();
    }
}
