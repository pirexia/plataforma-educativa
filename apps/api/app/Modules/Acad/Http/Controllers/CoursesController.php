<?php

namespace App\Modules\Acad\Http\Controllers;

use App\Modules\Acad\Application\CourseService;
use App\Modules\Acad\Application\StructureYears;
use App\Modules\Acad\Domain\Models\Course;
use App\Modules\Acad\Domain\Models\EducationLevel;
use App\Modules\Acad\Http\Requests\IndexCoursesRequest;
use App\Modules\Acad\Http\Requests\StoreCourseRequest;
use App\Modules\Acad\Http\Requests\UpdateTranslatedNameRequest;
use App\Modules\Acad\Http\Resources\CourseResource;
use App\Support\I18n\TranslationsValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** REQ-ACAD/api.md §2 (cursos de etapa). */
class CoursesController extends StructureController
{
    use ListsStructure;

    public function index(IndexCoursesRequest $request, StructureYears $years): JsonResponse
    {
        $year = $this->listingYear($request, $years);

        $query = Course::query()->with('educationLevel')
            ->when($request->list('education_level') !== [], fn ($q) => $q->whereIn(
                'education_level_id',
                EducationLevel::query()->whereIn('public_id', $request->list('education_level'))->select('id'),
            ));

        return $this->paginated($query, $request, $year->id, CourseResource::class);
    }

    public function store(StoreCourseRequest $request, TranslationsValidator $translations, CourseService $service): JsonResponse
    {
        $course = $service->create($request->payload($translations), $this->actor($request));

        return (new CourseResource($course))
            ->response()
            ->setStatusCode(201)
            ->header('Location', url("/api/v1/courses/{$course->public_id}"));
    }

    public function show(Request $request, string $publicId, CourseService $service): CourseResource
    {
        return new CourseResource($service->find($publicId, $this->actor($request)));
    }

    public function update(UpdateTranslatedNameRequest $request, string $publicId, CourseService $service): CourseResource
    {
        return new CourseResource($service->update($publicId, $request->payload(), $this->actor($request)));
    }

    public function destroy(Request $request, string $publicId, CourseService $service): Response
    {
        $service->delete($publicId, $this->actor($request));

        return response()->noContent();
    }
}
