<?php

namespace App\Modules\Acad\Http\Controllers;

use App\Modules\Acad\Application\StaffDirectory;
use App\Modules\Acad\Application\StructureErrors;
use App\Modules\Acad\Application\StructureYears;
use App\Modules\Acad\Application\TimetableSessionService;
use App\Modules\Acad\Domain\Models\Facility;
use App\Modules\Acad\Domain\Models\Group;
use App\Modules\Acad\Domain\Models\Subject;
use App\Modules\Acad\Domain\Models\TimetableFrame;
use App\Modules\Acad\Domain\Models\TimetableSession;
use App\Modules\Acad\Http\Requests\IndexTimetableSessionsRequest;
use App\Modules\Acad\Http\Requests\StoreTimetableSessionRequest;
use App\Modules\Acad\Http\Requests\UpdateTimetableSessionRequest;
use App\Modules\Acad\Http\Resources\TimetableSessionResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * REQ-ACAD/api.md §12 (sesiones y vistas semanales, 1.12-a). Permisos del
 * recurso `horarios` (`permisos.md §10.1`).
 */
class TimetableSessionsController extends StructureController
{
    private const RELATIONS = ['timeSlot.frame', 'teachingAssignment.group', 'teachingAssignment.groupSubject.subject', 'teachingAssignment.subgroup', 'facility'];

    /**
     * `RN-ACAD-127`: vista semanal. Un `public_id` inexistente, de otro curso o
     * de otro centro da una colección vacía (api.md §12.4).
     */
    public function index(IndexTimetableSessionsRequest $request, StructureYears $years, TimetableSessionService $service, StaffDirectory $staff): JsonResponse
    {
        $names = ['teacher', 'facility', 'group', 'subject'];

        if (array_filter($names, static fn (string $n): bool => $request->filled($n)) === []) {
            throw StructureErrors::invalid('teacher', 'acad.validation.view_filter_required');
        }

        $param = $request->query('academic_year');
        $year = $years->forListing(is_string($param) ? $param : null, $this->actor($request));

        $filters = [];
        $empty = false;

        if ($request->filled('teacher')) {
            $ref = $staff->findActive((string) $request->query('teacher'));
            $ref === null ? $empty = true : $filters['teacher_id'] = $ref->id;
        }

        $resolve = static function (string $name, string $model, string $key) use ($request, &$filters, &$empty): void {
            if (! $request->filled($name)) {
                return;
            }

            /** @var class-string<Model> $model */
            $id = $model::query()->where('public_id', (string) $request->query($name))->value('id');
            $id === null ? $empty = true : $filters[$key] = (int) $id;
        };

        $resolve('facility', Facility::class, 'facility_id');
        $resolve('group', Group::class, 'group_id');
        $resolve('subject', Subject::class, 'subject_id');
        $resolve('frame', TimetableFrame::class, 'frame_id');

        $sessions = $empty ? [] : $service->view($year->id, $filters);

        $collection = (new TimetableSession)->newCollection($sessions)->load(self::RELATIONS);
        $staff->preload($collection->map(static fn (TimetableSession $s): int => $s->teachingAssignment->user_id)->all());

        return response()->json(['data' => TimetableSessionResource::collection($collection)->resolve()]);
    }

    public function store(StoreTimetableSessionRequest $request, TimetableSessionService $service): JsonResponse
    {
        $session = $service->create($request->payload(), $this->actor($request));

        return (new TimetableSessionResource($session->load(self::RELATIONS)))
            ->response()
            ->setStatusCode(201)
            ->header('Location', url("/api/v1/timetable-sessions/{$session->public_id}"));
    }

    public function show(Request $request, string $publicId, TimetableSessionService $service): TimetableSessionResource
    {
        return new TimetableSessionResource($service->find($publicId, $this->actor($request))->load(self::RELATIONS));
    }

    public function update(UpdateTimetableSessionRequest $request, string $publicId, TimetableSessionService $service): TimetableSessionResource
    {
        return new TimetableSessionResource($service->update($publicId, $request->payload(), $this->actor($request))->load(self::RELATIONS));
    }

    public function destroy(Request $request, string $publicId, TimetableSessionService $service): Response
    {
        $service->delete($publicId, $this->actor($request));

        return response()->noContent();
    }
}
