<?php

namespace App\Modules\Acad\Application;

use App\Models\User;
use App\Modules\Acad\Domain\Models\Group;
use App\Modules\Acad\Domain\Models\Subgroup;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD/funcional.md §4.3-§4.5 (subgrupos). El grupo es recurso de la
 * ruta: inexistente, borrado, de otro centro o de un curso cerrado sin
 * `curso_historico.leer` ⇒ `404`, nunca `422` (`CA-ACAD-007`).
 */
final class SubgroupService
{
    use WritesStructure;

    public function __construct(private readonly StructureYears $years) {}

    /** Detalle: `404` si no existe o es de otro centro; `404` también si su curso es de solo lectura sin `curso_historico.leer` (`RN-ACAD-08`). */
    public function find(string $publicId, User $actor): Subgroup
    {
        $subgroup = Subgroup::query()->where('public_id', $publicId)->firstOrFail();
        $this->years->forRow($subgroup->academic_year_id, $actor);

        return $subgroup;
    }

    /** El grupo de la ruta, ya comprobado: vivo, del centro y de un curso legible. */
    public function groupOf(string $groupPublicId, User $actor): Group
    {
        $group = Group::query()->where('public_id', $groupPublicId)->firstOrFail();
        $this->years->forRow($group->academic_year_id, $actor);

        return $group;
    }

    /**
     * @param  array{code: string, name: string, position: ?int}  $input
     */
    public function create(string $groupPublicId, array $input, User $actor): Subgroup
    {
        return $this->inTransaction(function () use ($groupPublicId, $input, $actor): Subgroup {
            $group = Group::query()->where('public_id', $groupPublicId)->sharedLock()->firstOrFail();
            $this->years->forRow($group->academic_year_id, $actor);

            $siblings = Subgroup::query()->where('group_id', $group->id);
            $this->assertCodeFree(clone $siblings, $input['code']);

            return Subgroup::create([
                'academic_year_id' => $group->academic_year_id,
                'group_id' => $group->id,
                'code' => $input['code'],
                'name' => $input['name'],
                'position' => $input['position'] ?? $this->nextPosition(clone $siblings),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(string $publicId, array $input, User $actor): Subgroup
    {
        $subgroup = Subgroup::query()->where('public_id', $publicId)->firstOrFail();
        $this->years->forRow($subgroup->academic_year_id, $actor);

        if (array_key_exists('code', $input)) {
            $this->assertCodeFree(Subgroup::query()->where('group_id', $subgroup->group_id), $input['code'], $subgroup->id);
        }

        return $this->inTransaction(function () use ($subgroup, $input): Subgroup {
            $subgroup->fill(array_intersect_key($input, array_flip(['code', 'name', 'position'])))->save();

            return $subgroup;
        });
    }

    /** Un subgrupo no tiene hijos en 1.11 (`RN-ACAD-16`). */
    public function delete(string $publicId, User $actor): void
    {
        DB::transaction(function () use ($publicId, $actor): void {
            $subgroup = Subgroup::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            $this->years->forRow($subgroup->academic_year_id, $actor);

            $subgroup->delete();
        });
    }
}
