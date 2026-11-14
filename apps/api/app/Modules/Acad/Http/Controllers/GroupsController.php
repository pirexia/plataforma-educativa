<?php

namespace App\Modules\Acad\Http\Controllers;

use App\Modules\Acad\Application\GroupService;
use App\Modules\Acad\Application\StructureYears;
use App\Modules\Acad\Domain\Models\Course;
use App\Modules\Acad\Domain\Models\EducationLevel;
use App\Modules\Acad\Domain\Models\Group;
use App\Modules\Acad\Http\Requests\IndexByCourseRequest;
use App\Modules\Acad\Http\Requests\StoreGroupRequest;
use App\Modules\Acad\Http\Requests\UpdateSimpleNameRequest;
use App\Modules\Acad\Http\Resources\GroupResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** REQ-ACAD/api.md §2 (grupos). */
class GroupsController extends StructureController
{
    use ListsStructure;

    public function index(IndexByCourseRequest $request, StructureYears $years): JsonResponse
    {
        $year = $this->listingYear($request, $years);

        $query = Group::query()->with('course')
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

        return $this->paginated($query, $request, $year->id, GroupResource::class);
    }

    public function store(StoreGroupRequest $request, GroupService $service): JsonResponse
    {
        $group = $service->create($request->payload(), $this->actor($request));

        return (new GroupResource($group))
            ->response()
            ->setStatusCode(201)
            ->header('Location', url("/api/v1/groups/{$group->public_id}"));
    }

    public function show(Request $request, string $publicId, GroupService $service): GroupResource
    {
        return new GroupResource($service->find($publicId, $this->actor($request)));
    }

    public function update(UpdateSimpleNameRequest $request, string $publicId, GroupService $service): GroupResource
    {
        return new GroupResource($service->update($publicId, $request->payload(), $this->actor($request)));
    }

    public function destroy(Request $request, string $publicId, GroupService $service): Response
    {
        $service->delete($publicId, $this->actor($request));

        return response()->noContent();
    }
}
