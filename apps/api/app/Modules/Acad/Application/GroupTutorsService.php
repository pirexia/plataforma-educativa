<?php

namespace App\Modules\Acad\Application;

use App\Models\User;
use App\Modules\Acad\Domain\Models\Group;
use App\Modules\Acad\Domain\Models\GroupTutor;
use App\Support\Api\ValidationErrorBag;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD/funcional.md §18.4.2 (`RN-ACAD-97`): tutores de un grupo. `PUT`
 * sustituye el conjunto completo, idempotente, con altas y bajas por instancia
 * (`AR-15`) en una transacción; un `PUT` sin cambios no escribe (precedente
 * `GroupSubjectsService`, `RN-ACAD-32`).
 */
final class GroupTutorsService
{
    public function __construct(
        private readonly StructureYears $years,
        private readonly StaffDirectory $staff,
    ) {}

    /**
     * Usuarios tutores del grupo, por orden de alta.
     *
     * @return list<array{public_id: string, name: string}>
     */
    public function list(string $groupPublicId, User $actor): array
    {
        $group = Group::query()->where('public_id', $groupPublicId)->firstOrFail();
        $this->years->forRow($group->academic_year_id, $actor);

        return $this->summaries($group);
    }

    /**
     * @param  list<string>  $userPublicIds
     * @return list<array{public_id: string, name: string}>
     */
    public function replace(string $groupPublicId, array $userPublicIds, User $actor): array
    {
        return DB::transaction(function () use ($groupPublicId, $userPublicIds, $actor): array {
            // Serializa dos `PUT` sobre el mismo grupo y el borrado concurrente del grupo.
            $group = Group::query()->where('public_id', $groupPublicId)->lockForUpdate()->firstOrFail();
            $this->years->forRow($group->academic_year_id, $actor);

            // RN-ACAD-92: cada tutor es un usuario activo del centro; los repetidos son un conjunto.
            $errors = new ValidationErrorBag;
            $wanted = [];

            foreach ($userPublicIds as $index => $publicId) {
                $user = $this->staff->findActive($publicId);

                if ($user === null) {
                    $errors->add("tutors.{$index}", 'acad.validation.user_not_found', 'acad.validation.user_not_found');

                    continue;
                }

                $wanted[$user->id] = true;
            }

            $errors->throwIfAny();

            $current = GroupTutor::query()->where('group_id', $group->id)->get()->keyBy('user_id');

            foreach (array_keys($wanted) as $userId) {
                if (! $current->has($userId)) {
                    GroupTutor::create([
                        'academic_year_id' => $group->academic_year_id,
                        'group_id' => $group->id,
                        'user_id' => $userId,
                    ]);
                }
            }

            foreach ($current as $userId => $tutor) {
                if (! isset($wanted[$userId])) {
                    $tutor->delete();
                }
            }

            return $this->summaries($group);
        });
    }

    /**
     * @return list<array{public_id: string, name: string}>
     */
    private function summaries(Group $group): array
    {
        $ids = GroupTutor::query()->where('group_id', $group->id)->orderBy('id')->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
        $this->staff->preload($ids);

        $summaries = [];

        foreach ($ids as $id) {
            $summary = $this->staff->summary($id);

            if ($summary !== null) {
                $summaries[] = $summary;
            }
        }

        return $summaries;
    }
}
