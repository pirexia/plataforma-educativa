<?php

namespace App\Modules\Acad\Http\Controllers;

use App\Modules\Acad\Application\StaffDirectory;
use App\Modules\Acad\Application\StructureYears;
use App\Modules\Acad\Application\TeachingAssignmentService;
use App\Modules\Acad\Domain\Models\Group;
use App\Modules\Acad\Domain\Models\GroupSubject;
use App\Modules\Acad\Domain\Models\Subject;
use App\Modules\Acad\Domain\Models\TeachingAssignment;
use App\Modules\Acad\Http\Requests\IndexTeachingAssignmentsRequest;
use App\Modules\Acad\Http\Requests\StoreTeachingAssignmentRequest;
use App\Modules\Acad\Http\Resources\TeachingAssignmentResource;
use App\Support\Api\PagePaginatedResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * REQ-ACAD/api.md §12 (asignación docente, 1.12-a). Permisos del recurso
 * `asignacion_docente` (`permisos.md §10.1`). Sin `PATCH`: inmutable
 * (`RN-ACAD-95`), la ruta no existe (`405`).
 */
class TeachingAssignmentsController extends StructureController
{
    private const RELATIONS = ['group', 'groupSubject.subject', 'subgroup'];

    public function index(IndexTeachingAssignmentsRequest $request, StructureYears $years, StaffDirectory $staff): JsonResponse
    {
        $param = $request->query('academic_year');
        $year = $years->forListing(is_string($param) ? $param : null, $this->actor($request));

        $query = TeachingAssignment::query()->with(self::RELATIONS)
            ->where('academic_year_id', $year->id)
            ->when($request->list('group') !== [], fn ($q) => $q->whereIn(
                'group_id',
                Group::query()->whereIn('public_id', $request->list('group'))->select('id'),
            ))
            ->when($request->list('subject') !== [], fn ($q) => $q->whereIn(
                'group_subject_id',
                GroupSubject::query()->whereIn('subject_id', Subject::query()->whereIn('public_id', $request->list('subject'))->select('id'))->select('id'),
            ));

        if ($request->list('teacher') !== []) {
            // `Core` solo resuelve usuarios activos por `public_id`: un profesor dado de baja no devuelve filas.
            $ids = [];

            foreach ($request->list('teacher') as $publicId) {
                $ref = $staff->findActive($publicId);

                if ($ref !== null) {
                    $ids[] = $ref->id;
                }
            }

            $query->whereIn('user_id', $ids);
        }

        $query->orderBy('id', str_starts_with($request->string('sort', 'id')->value(), '-') ? 'desc' : 'asc');

        $page = $query->paginate($request->integer('per_page', 25))->withQueryString();
        $staff->preload(array_map(static fn (TeachingAssignment $a): int => $a->user_id, $page->items()));

        return PagePaginatedResponse::make($page, TeachingAssignmentResource::class);
    }

    public function store(StoreTeachingAssignmentRequest $request, TeachingAssignmentService $service): JsonResponse
    {
        $assignment = $service->create($request->payload(), $this->actor($request));

        return (new TeachingAssignmentResource($assignment->load(self::RELATIONS)))
            ->response()
            ->setStatusCode(201)
            ->header('Location', url("/api/v1/teaching-assignments/{$assignment->public_id}"));
    }

    public function show(Request $request, string $publicId, TeachingAssignmentService $service): TeachingAssignmentResource
    {
        return new TeachingAssignmentResource($service->find($publicId, $this->actor($request))->load(self::RELATIONS));
    }

    public function destroy(Request $request, string $publicId, TeachingAssignmentService $service): Response
    {
        $service->delete($publicId, $this->actor($request));

        return response()->noContent();
    }
}
