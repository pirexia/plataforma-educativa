<?php

namespace App\Modules\Acad\Application;

use App\Models\User;
use App\Modules\Acad\Domain\Models\Facility;
use App\Modules\Acad\Domain\Models\Group;
use App\Modules\Acad\Domain\Models\TeachingAssignment;
use App\Modules\Acad\Domain\Models\TimeSlot;
use App\Modules\Acad\Domain\Models\TimetableFrame;
use App\Modules\Acad\Domain\Models\TimetableSession;
use App\Support\Api\ApiException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD/funcional.md §18.4.5 (`RN-ACAD-120` a `-128`), 1.12-a: sesiones de
 * horario y detección de conflictos.
 *
 * **Único servicio que escribe sesiones** (`RN-ACAD-125`). El motor no
 * garantiza los conflictos (sin `btree_gist`, `datos.md §8.3`), así que toda
 * escritura toma `FOR UPDATE` de la fila `timetables` del curso **antes** de
 * leer con qué compara. Orden de bloqueos (`datos.md §9.5`): `FOR SHARE` de los
 * padres (franja, asignación, aula) → `FOR UPDATE` del ancla → lecturas y
 * escritura. Nunca `FOR UPDATE` sobre la fila del curso.
 *
 * Los `FOR SHARE` de padres cierran las carreras con sus borrados: borrar la
 * asignación (`RN-ACAD-95`), una franja (`RN-ACAD-114`) o un aula
 * (`RN-ACAD-102`, `-128`) toma `FOR UPDATE` del padre y mira si hay sesiones.
 *
 * Coincidencia (`RN-ACAD-124`): mismo `weekday` e intervalos `[inicio, fin)` que
 * se cortan, comparados **por hora y no por franja**, entre marcos que
 * **coexisten** (`FrameCoexistence`, `1.12-b`): dos `ordinaria` siempre; un
 * `especial` sustituye al `ordinaria` de su etapa y coexiste con los demás
 * `ordinaria` y con los `especial` de fechas que se cortan.
 */
final class TimetableSessionService
{
    /** `RN-ACAD-127`: tope de filas de una vista semanal. */
    public const MAX_VIEW_ROWS = 500;

    public function __construct(
        private readonly StructureYears $years,
        private readonly TimetableAnchor $anchor,
    ) {}

    /** Detalle: `404` si no existe o es de otro centro; `404` también si su curso es de solo lectura sin `curso_historico.leer` (`RN-ACAD-90`). */
    public function find(string $publicId, User $actor): TimetableSession
    {
        $session = TimetableSession::query()->where('public_id', $publicId)->firstOrFail();
        $this->years->forRow($session->academic_year_id, $actor);

        return $session;
    }

    /**
     * @param  array{time_slot: string, teaching_assignment: string, facility: ?string}  $input
     */
    public function create(array $input, User $actor): TimetableSession
    {
        return DB::transaction(function () use ($input, $actor): TimetableSession {
            $slot = TimeSlot::query()->where('public_id', $input['time_slot'])->sharedLock()->first();

            if ($slot === null) {
                throw StructureErrors::invalid('time_slot', 'acad.validation.parent_not_found');
            }

            $this->years->forBodyParent($slot->academic_year_id, $actor, 'time_slot');

            $assignment = TeachingAssignment::query()->where('public_id', $input['teaching_assignment'])->sharedLock()->first();

            // Otro curso académico que el de la franja ⇒ indistinguible de inexistente (RN-ACAD-120).
            if ($assignment === null || $assignment->academic_year_id !== $slot->academic_year_id) {
                throw StructureErrors::invalid('teaching_assignment', 'acad.validation.parent_not_found');
            }

            $facility = $input['facility'] === null ? null : $this->room($input['facility']);

            $this->assertSlotForGroup($slot, $assignment);

            $this->anchor->lock($slot->academic_year_id);
            $this->assertNoConflict($slot, $assignment, $facility, null);

            return TimetableSession::create([
                'academic_year_id' => $slot->academic_year_id,
                'time_slot_id' => $slot->id,
                'teaching_assignment_id' => $assignment->id,
                'facility_id' => $facility?->id,
                'facility_type' => $facility?->facility_type,
            ]);
        });
    }

    /**
     * `RN-ACAD-122`: `time_slot` y `facility` (`null` quita el aula).
     *
     * @param  array<string, mixed>  $input  solo las claves presentes en el `PATCH`
     */
    public function update(string $publicId, array $input, User $actor): TimetableSession
    {
        $found = $this->find($publicId, $actor);

        return DB::transaction(function () use ($found, $input): TimetableSession {
            $slot = null;

            if (array_key_exists('time_slot', $input)) {
                $slot = TimeSlot::query()->where('public_id', $input['time_slot'])->sharedLock()->first();

                // Otro curso académico que el de la sesión ⇒ indistinguible de inexistente.
                if ($slot === null || $slot->academic_year_id !== $found->academic_year_id) {
                    throw StructureErrors::invalid('time_slot', 'acad.validation.parent_not_found');
                }
            }

            $facility = null;

            if (array_key_exists('facility', $input) && $input['facility'] !== null) {
                $facility = $this->room((string) $input['facility']);
            }

            $this->anchor->lock($found->academic_year_id);

            // Se relee ya con el ancla tomada: otra escritura pudo mover o borrar la sesión.
            $session = TimetableSession::query()->whereKey($found->getKey())->lockForUpdate()->firstOrFail();
            $assignment = TeachingAssignment::query()->withTrashed()->whereKey($session->teaching_assignment_id)->firstOrFail();
            $target = $slot ?? TimeSlot::query()->whereKey($session->time_slot_id)->firstOrFail();

            if (! array_key_exists('facility', $input)) {
                $facility = $session->facility_id === null ? null : Facility::query()->withTrashed()->whereKey($session->facility_id)->first();
            }

            if ($slot !== null) {
                $this->assertSlotForGroup($slot, $assignment);
            }

            $this->assertNoConflict($target, $assignment, $facility, $session->id);

            $session->fill([
                'time_slot_id' => $target->id,
                'facility_id' => $facility?->id,
                'facility_type' => $facility?->facility_type,
            ])->save();

            return $session;
        });
    }

    public function delete(string $publicId, User $actor): void
    {
        $found = $this->find($publicId, $actor);

        DB::transaction(function () use ($found): void {
            TimetableSession::query()->whereKey($found->getKey())->lockForUpdate()->firstOrFail()->delete();
        });
    }

    /**
     * Vista semanal (`RN-ACAD-127`). Los filtros ya vienen resueltos a ids
     * internos; el orden es fijo: `weekday`, `starts_at`, `id`.
     *
     * @param  array{teacher_id?: int, facility_id?: int, group_id?: int, subject_id?: int, frame_id?: int}  $filters
     * @return list<TimetableSession>
     */
    public function view(int $academicYearId, array $filters): array
    {
        $query = TimetableSession::query()
            ->select('timetable_sessions.*')
            ->join('time_slots as ts', 'ts.id', '=', 'timetable_sessions.time_slot_id')
            ->join('teaching_assignments as ta', 'ta.id', '=', 'timetable_sessions.teaching_assignment_id')
            ->join('group_subjects as gs', 'gs.id', '=', 'ta.group_subject_id')
            ->where('timetable_sessions.academic_year_id', $academicYearId)
            ->when(isset($filters['teacher_id']), fn (Builder $q) => $q->where('ta.user_id', $filters['teacher_id']))
            ->when(isset($filters['facility_id']), fn (Builder $q) => $q->where('timetable_sessions.facility_id', $filters['facility_id']))
            // La vista de grupo incluye las sesiones de sus subgrupos: sus asignaciones llevan el mismo `group_id`.
            ->when(isset($filters['group_id']), fn (Builder $q) => $q->where('ta.group_id', $filters['group_id']))
            ->when(isset($filters['subject_id']), fn (Builder $q) => $q->where('gs.subject_id', $filters['subject_id']))
            ->when(isset($filters['frame_id']), fn (Builder $q) => $q->where('ts.timetable_frame_id', $filters['frame_id']))
            ->orderBy('ts.weekday')
            ->orderBy('ts.starts_at')
            ->orderBy('timetable_sessions.id')
            ->limit(self::MAX_VIEW_ROWS + 1);

        $rows = $query->get();

        if ($rows->count() > self::MAX_VIEW_ROWS) {
            throw StructureErrors::invalidWith('teacher', 'acad.validation.view_too_large', ['max' => self::MAX_VIEW_ROWS]);
        }

        return $rows->all();
    }

    /** `RN-ACAD-121`: aula viva y de tipo `aula`; `FOR SHARE` para que su borrado concurrente espere o pierda (`RN-ACAD-128`). */
    private function room(string $publicId): Facility
    {
        $facility = Facility::query()->where('public_id', $publicId)->sharedLock()->first();

        if ($facility === null) {
            throw StructureErrors::invalid('facility', 'acad.validation.facility_not_found');
        }

        if ($facility->facility_type !== 'aula') {
            throw StructureErrors::invalid('facility', 'acad.validation.facility_not_room');
        }

        return $facility;
    }

    /** `RN-ACAD-120`: la etapa del grupo de la asignación es la del marco de la franja. */
    private function assertSlotForGroup(TimeSlot $slot, TeachingAssignment $assignment): void
    {
        $frameLevel = TimetableFrame::query()->withTrashed()->whereKey($slot->timetable_frame_id)->value('education_level_id');
        $groupLevel = Group::query()->withTrashed()->with('course')->whereKey($assignment->group_id)->firstOrFail()->course->education_level_id;

        if ($frameLevel !== $groupLevel) {
            throw StructureErrors::invalid('time_slot', 'acad.validation.slot_not_for_group');
        }
    }

    /**
     * `RN-ACAD-123`/`-124`: rechaza el alta o el movimiento que dejaría dos
     * sesiones vivas coincidentes con choque de profesor, aula o grupo. Las
     * lee ya acotadas por curso, día e intervalo (índices de `datos.md §9.4`)
     * y por coexistencia de marcos (`FrameCoexistence`).
     */
    private function assertNoConflict(TimeSlot $slot, TeachingAssignment $assignment, ?Facility $facility, ?int $exceptSessionId): void
    {
        $frame = TimetableFrame::query()->withTrashed()->whereKey($slot->timetable_frame_id)->firstOrFail();

        $conflicts = $this->conflicts(
            $slot,
            $frame->frame_type,
            $frame->education_level_id,
            $frame->valid_from?->toDateString(),
            $frame->valid_to?->toDateString(),
            $assignment,
            $facility,
            $exceptSessionId,
        );

        $this->throwIfConflicts('time_slot', $conflicts);
    }

    /**
     * `RN-ACAD-125`, `1.12-b`: el cambio de fechas de un marco `especial` puede
     * hacer que sus sesiones coincidan con las de otro marco que antes no
     * coexistía. Se comprueba cada sesión del marco contra las demás con las
     * fechas **nuevas**; el primer choque se rechaza con `422 timetable_conflict`
     * en `valid_from`. Quien llama ya tiene el ancla del horario (`FOR UPDATE`).
     */
    public function assertFrameDatesFree(TimetableFrame $frame, string $validFrom, string $validTo): void
    {
        $sessions = TimetableSession::query()
            ->select('timetable_sessions.*')
            ->join('time_slots as ts', 'ts.id', '=', 'timetable_sessions.time_slot_id')
            ->where('ts.timetable_frame_id', $frame->id)
            ->orderBy('timetable_sessions.id')
            ->with(['timeSlot', 'teachingAssignment', 'facility'])
            ->get();

        foreach ($sessions as $session) {
            $conflicts = $this->conflicts(
                $session->timeSlot,
                'especial',
                $frame->education_level_id,
                $validFrom,
                $validTo,
                $session->teachingAssignment,
                $session->facility,
                $session->id,
            );

            $this->throwIfConflicts('valid_from', $conflicts);
        }
    }

    /**
     * @return list<array{kind: string, session: string}>
     */
    private function conflicts(TimeSlot $slot, string $frameType, int $levelId, ?string $validFrom, ?string $validTo, TeachingAssignment $assignment, ?Facility $facility, ?int $exceptSessionId): array
    {
        $query = TimetableSession::query()
            ->select('timetable_sessions.public_id', 'timetable_sessions.facility_id', 'ta.user_id', 'ta.group_id', 'ta.subgroup_id')
            ->join('time_slots as ts', 'ts.id', '=', 'timetable_sessions.time_slot_id')
            ->join('timetable_frames as f', 'f.id', '=', 'ts.timetable_frame_id')
            ->join('teaching_assignments as ta', 'ta.id', '=', 'timetable_sessions.teaching_assignment_id')
            ->where('timetable_sessions.academic_year_id', $slot->academic_year_id)
            ->where('ts.weekday', $slot->weekday)
            ->where('ts.starts_at', '<', $slot->ends_at)
            ->where('ts.ends_at', '>', $slot->starts_at)
            ->when($exceptSessionId !== null, fn (Builder $q) => $q->where('timetable_sessions.id', '!=', $exceptSessionId))
            ->where(function (Builder $q) use ($assignment, $facility): void {
                $q->where('ta.user_id', $assignment->user_id)->orWhere('ta.group_id', $assignment->group_id);

                if ($facility !== null) {
                    $q->orWhere('timetable_sessions.facility_id', $facility->id);
                }
            });

        FrameCoexistence::constrain($query, $frameType, $levelId, $validFrom, $validTo);

        $others = $query
            ->orderBy('ts.starts_at')
            ->orderBy('timetable_sessions.id')
            ->get();

        $conflicts = [];

        foreach ($others as $other) {
            if ((int) $other->getAttribute('user_id') === $assignment->user_id) {
                $conflicts[] = ['kind' => 'teacher', 'session' => (string) $other->public_id];
            }

            if ($facility !== null && (int) $other->facility_id === $facility->id) {
                $conflicts[] = ['kind' => 'facility', 'session' => (string) $other->public_id];
            }

            if ((int) $other->getAttribute('group_id') === $assignment->group_id && ! $this->distinctSubgroups($assignment->subgroup_id, $other->getAttribute('subgroup_id'))) {
                $conflicts[] = ['kind' => 'group', 'session' => (string) $other->public_id];
            }
        }

        return $conflicts;
    }

    /**
     * @param  list<array{kind: string, session: string}>  $conflicts
     */
    private function throwIfConflicts(string $field, array $conflicts): void
    {
        if ($conflicts !== []) {
            $code = 'acad.validation.timetable_conflict';

            throw ApiException::validation([$field => [[
                'code' => $code,
                'message' => __($code),
                'params' => ['conflicts' => $conflicts],
            ]]]);
        }
    }

    /** `RN-ACAD-123`: dos sesiones del mismo grupo no chocan solo si son de subgrupos distintos (desdoble). */
    private function distinctSubgroups(mixed $a, mixed $b): bool
    {
        return $a !== null && $b !== null && (int) $a !== (int) $b;
    }
}
