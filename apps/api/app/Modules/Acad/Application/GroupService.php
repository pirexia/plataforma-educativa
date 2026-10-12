<?php

namespace App\Modules\Acad\Application;

use App\Models\User;
use App\Modules\Acad\Domain\Models\Course;
use App\Modules\Acad\Domain\Models\Group;
use App\Modules\Acad\Domain\Models\GroupSubject;
use App\Modules\Acad\Domain\Models\Subgroup;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD/funcional.md §4.3-§4.5 (grupos). `name` es texto simple, no
 * traducible y sí escribible (`RN-ACAD-13`).
 */
final class GroupService
{
    use WritesStructure;

    public function __construct(private readonly StructureYears $years) {}

    /** Detalle: `404` si no existe o es de otro centro; `404` también si su curso es de solo lectura sin `curso_historico.leer` (`RN-ACAD-08`). */
    public function find(string $publicId, User $actor): Group
    {
        $group = Group::query()->where('public_id', $publicId)->firstOrFail();
        $this->years->forRow($group->academic_year_id, $actor);

        return $group;
    }

    /**
     * @param  array{course: string, code: string, name: string, position: ?int}  $input
     */
    public function create(array $input, User $actor): Group
    {
        return $this->inTransaction(function () use ($input, $actor): Group {
            $course = Course::query()->where('public_id', $input['course'])->sharedLock()->first();

            if ($course === null) {
                throw StructureErrors::invalid('course', 'acad.validation.parent_not_found');
            }

            $this->years->forRow($course->academic_year_id, $actor);

            $siblings = Group::query()->where('course_id', $course->id);
            $this->assertCodeFree(clone $siblings, $input['code']);

            return Group::create([
                'academic_year_id' => $course->academic_year_id,
                'course_id' => $course->id,
                'code' => $input['code'],
                'name' => $input['name'],
                'position' => $input['position'] ?? $this->nextPosition(clone $siblings),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(string $publicId, array $input, User $actor): Group
    {
        $group = Group::query()->where('public_id', $publicId)->firstOrFail();
        $this->years->forRow($group->academic_year_id, $actor);

        if (array_key_exists('code', $input)) {
            $this->assertCodeFree(Group::query()->where('course_id', $group->course_id), $input['code'], $group->id);
        }

        return $this->inTransaction(function () use ($group, $input): Group {
            $group->fill(array_intersect_key($input, array_flip(['code', 'name', 'position'])))->save();

            return $group;
        });
    }

    /** `RN-ACAD-20`: no se borra un grupo con subgrupos o asignaturas asignadas vivos. */
    public function delete(string $publicId, User $actor): void
    {
        DB::transaction(function () use ($publicId, $actor): void {
            $group = Group::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            $this->years->forRow($group->academic_year_id, $actor);

            $dependents = [];

            if (Subgroup::query()->where('group_id', $group->id)->exists()) {
                $dependents[] = 'subgroups';
            }

            if (GroupSubject::query()->where('group_id', $group->id)->exists()) {
                $dependents[] = 'subjects';
            }

            if ($dependents !== []) {
                throw StructureErrors::hasDependents('group', $group->code, $dependents);
            }

            $group->delete();
        });
    }
}
