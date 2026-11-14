<?php

namespace App\Modules\Acad\Application;

use App\Models\User;
use App\Modules\Acad\Domain\Models\Group;
use App\Modules\Acad\Domain\Models\GroupSubject;
use App\Modules\Acad\Domain\Models\Subgroup;
use App\Modules\Acad\Domain\Models\Subject;
use App\Modules\Acad\Domain\Models\TeachingAssignment;
use App\Modules\Acad\Domain\Models\TimetableSession;
use App\Support\Api\ValidationErrorBag;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD/funcional.md §18.4.2 (`RN-ACAD-92` a `-95`), 1.12-a: asignación
 * docente. Un usuario imparte una asignatura que cursa un grupo, con subgrupo
 * opcional. **Inmutable** (`RN-ACAD-95`): se crea y se borra, no se edita.
 *
 * El curso es el del grupo (`RN-ACAD-91`). Las escrituras sobre un curso de
 * solo lectura las rechaza el disparador (`409`, `RN-ACAD-90`). El motor
 * refuerza la pertenencia de asignatura y subgrupo al grupo con FK compuestas
 * (`datos.md §9.5`); aquí se comprueba además para dar el `422`.
 */
final class TeachingAssignmentService
{
    private const UNIQUE = 'teaching_assignments_tenant_user_gsubject_subgroup_unique';

    public function __construct(
        private readonly StructureYears $years,
        private readonly StaffDirectory $staff,
    ) {}

    /** Detalle: `404` si no existe o es de otro centro; `404` también si su curso es de solo lectura sin `curso_historico.leer` (`RN-ACAD-90`). */
    public function find(string $publicId, User $actor): TeachingAssignment
    {
        $assignment = TeachingAssignment::query()->where('public_id', $publicId)->firstOrFail();
        $this->years->forRow($assignment->academic_year_id, $actor);

        return $assignment;
    }

    /**
     * @param  array{teacher: string, group: string, subject: string, subgroup: ?string}  $input
     */
    public function create(array $input, User $actor): TeachingAssignment
    {
        try {
            return DB::transaction(function () use ($input, $actor): TeachingAssignment {
                // `FOR SHARE`: el borrado del grupo y el `PUT` de sus asignaturas (que toman `FOR UPDATE`) esperan o pierden.
                $group = Group::query()->where('public_id', $input['group'])->sharedLock()->first();

                if ($group === null) {
                    throw StructureErrors::invalid('group', 'acad.validation.parent_not_found');
                }

                $this->years->forBodyParent($group->academic_year_id, $actor, 'group');

                $errors = new ValidationErrorBag;

                $teacher = $this->staff->findActive($input['teacher']);

                if ($teacher === null) {
                    $errors->add('teacher', 'acad.validation.user_not_found', 'acad.validation.user_not_found');
                }

                $groupSubject = GroupSubject::query()
                    ->where('group_id', $group->id)
                    ->whereIn('subject_id', Subject::query()->where('public_id', $input['subject'])->select('id'))
                    ->first();

                if ($groupSubject === null) {
                    $errors->add('subject', 'acad.validation.subject_not_in_group', 'acad.validation.subject_not_in_group');
                }

                $subgroup = null;

                if ($input['subgroup'] !== null) {
                    $subgroup = Subgroup::query()->where('group_id', $group->id)->where('public_id', $input['subgroup'])->first();

                    if ($subgroup === null) {
                        $errors->add('subgroup', 'acad.validation.subgroup_not_in_group', 'acad.validation.subgroup_not_in_group');
                    }
                }

                $errors->throwIfAny();

                $taken = TeachingAssignment::query()
                    ->where('user_id', $teacher?->id)
                    ->where('group_subject_id', $groupSubject?->id)
                    ->when($subgroup === null, fn ($q) => $q->whereNull('subgroup_id'), fn ($q) => $q->where('subgroup_id', $subgroup?->id))
                    ->exists();

                if ($taken) {
                    throw StructureErrors::invalid('teacher', 'acad.validation.assignment_exists');
                }

                // Savepoint propio: la violación del único (carrera, `23505`) no aborta la transacción.
                return DB::transaction(fn (): TeachingAssignment => TeachingAssignment::create([
                    'academic_year_id' => $group->academic_year_id,
                    'user_id' => $teacher?->id,
                    'group_id' => $group->id,
                    'group_subject_id' => $groupSubject?->id,
                    'subgroup_id' => $subgroup?->id,
                ]));
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23505' && str_contains((string) $e->getMessage(), self::UNIQUE)) {
                throw StructureErrors::invalid('teacher', 'acad.validation.assignment_exists');
            }

            throw $e;
        }
    }

    /** `RN-ACAD-95`: con sesiones vivas ⇒ `409 has_dependents` con `timetable_sessions`. */
    public function delete(string $publicId, User $actor): void
    {
        DB::transaction(function () use ($publicId, $actor): void {
            // FOR UPDATE: una sesión concurrente toma FOR SHARE de su asignación, así que o la ve el borrado o ella ve la asignación borrada.
            $assignment = TeachingAssignment::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            $this->years->forRow($assignment->academic_year_id, $actor);

            if (TimetableSession::query()->where('teaching_assignment_id', $assignment->id)->exists()) {
                throw StructureErrors::hasDependents('teaching_assignment', (string) $assignment->public_id, ['timetable_sessions']);
            }

            $assignment->delete();
        });
    }
}
