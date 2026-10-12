<?php

namespace App\Modules\Acad\Application;

use App\Models\User;
use App\Modules\Acad\Domain\Models\Group;
use App\Modules\Acad\Domain\Models\GroupSubject;
use App\Modules\Acad\Domain\Models\Subject;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD/funcional.md §4.6, `RN-ACAD-30..32`: asignaturas que cursa un
 * grupo. `PUT` sustituye el conjunto completo, idempotente, con altas y bajas
 * por instancia (`AR-15`) en una transacción; un `PUT` sin cambios no escribe.
 */
final class GroupSubjectsService
{
    public function __construct(private readonly StructureYears $years) {}

    /**
     * Las asignaturas asignadas (representación `Subject`), en el orden de
     * `position` y luego `code`.
     *
     * @return Collection<int, Subject>
     */
    public function list(string $groupPublicId, User $actor): Collection
    {
        $group = Group::query()->where('public_id', $groupPublicId)->firstOrFail();
        $this->years->forRow($group->academic_year_id, $actor);

        return $this->assigned($group);
    }

    /**
     * @param  list<string>  $subjectPublicIds
     * @return Collection<int, Subject>
     */
    public function replace(string $groupPublicId, array $subjectPublicIds, User $actor): Collection
    {
        $wanted = array_values(array_unique($subjectPublicIds));

        return DB::transaction(function () use ($groupPublicId, $wanted, $actor): Collection {
            // Serializa dos `PUT` sobre el mismo grupo y el borrado concurrente del grupo.
            $group = Group::query()->where('public_id', $groupPublicId)->lockForUpdate()->firstOrFail();
            $this->years->forRow($group->academic_year_id, $actor);

            // `RN-ACAD-30`: solo asignaturas vivas del mismo curso de etapa.
            $subjects = $wanted === []
                ? new Collection
                : Subject::query()->where('course_id', $group->course_id)->whereIn('public_id', $wanted)->get();

            if ($subjects->count() !== count($wanted)) {
                throw StructureErrors::invalid('subjects', 'acad.validation.subject_not_in_course');
            }

            $current = GroupSubject::query()->where('group_id', $group->id)->get()->keyBy('subject_id');
            $wantedIds = $subjects->pluck('id')->all();

            foreach ($subjects as $subject) {
                if (! $current->has($subject->id)) {
                    GroupSubject::create([
                        'academic_year_id' => $group->academic_year_id,
                        'course_id' => $group->course_id,
                        'group_id' => $group->id,
                        'subject_id' => $subject->id,
                    ]);
                }
            }

            foreach ($current as $subjectId => $assignment) {
                if (! in_array($subjectId, $wantedIds, true)) {
                    $assignment->delete();
                }
            }

            return $this->assigned($group);
        });
    }

    /**
     * @return Collection<int, Subject>
     */
    private function assigned(Group $group): Collection
    {
        $ids = GroupSubject::query()->where('group_id', $group->id)->pluck('subject_id');

        return Subject::query()
            ->whereIn('id', $ids)
            ->orderBy('position')
            ->orderBy('code')
            ->orderBy('id')
            ->get();
    }
}
