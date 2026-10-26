<?php

namespace App\Modules\Core\Infrastructure;

use App\Models\Person;
use App\Models\User;
use App\Modules\Core\Domain\PersonDirectory;
use App\Modules\Core\Domain\PersonRef;
use App\Modules\Core\Domain\UserPersonRef;

/**
 * `OPEN-FAM-UNIT-03` (1.14-a). Una consulta por llamada, sin memoización. `Person::query()` y `User::query()`
 * aplican `TenantScope` y excluyen los borrados (`SoftDeletes`); RLS lo refuerza en la base de datos (`INV-001`).
 */
final class EloquentPersonDirectory implements PersonDirectory
{
    public function findByPublicId(string $publicId): ?PersonRef
    {
        $person = Person::query()->where('public_id', $publicId)->first();

        return $person === null ? null : self::ref($person);
    }

    public function refsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $refs = [];

        foreach (Person::query()->withTrashed()->whereIn('id', array_values(array_unique($ids)))->get() as $person) {
            $refs[(int) $person->id] = self::ref($person);
        }

        return $refs;
    }

    public function personIdForUser(int $userId): ?int
    {
        $personId = User::query()->whereKey($userId)->value('person_id');

        return $personId === null ? null : (int) $personId;
    }

    public function findUserByPublicId(string $userPublicId): ?UserPersonRef
    {
        $user = User::query()->with('person')->where('public_id', $userPublicId)->first();

        return $user === null || $user->person === null ? null : self::userRef($user);
    }

    public function usersByPersonIds(array $personIds): array
    {
        if ($personIds === []) {
            return [];
        }

        $refs = [];

        foreach (User::query()->withTrashed()->with(['person' => fn ($q) => $q->withTrashed()])->whereIn('person_id', array_values(array_unique($personIds)))->get() as $user) {
            if ($user->person === null) {
                continue;
            }

            // Si hay un usuario vivo y otro borrado de la misma persona, gana el vivo.
            $personId = (int) $user->person_id;

            if (isset($refs[$personId]) && ! $refs[$personId]->removed) {
                continue;
            }

            $refs[$personId] = self::userRef($user);
        }

        return $refs;
    }

    private static function ref(Person $person): PersonRef
    {
        $removed = $person->trashed();

        return new PersonRef((int) $person->id, (string) $person->public_id, $removed ? '' : self::name($person), $removed);
    }

    private static function userRef(User $user): UserPersonRef
    {
        /** @var Person $person */
        $person = $user->person;
        $removed = $user->trashed();

        return new UserPersonRef(
            (int) $user->id,
            (string) $user->public_id,
            (int) $person->id,
            (string) $person->public_id,
            $user->status->value,
            $removed ? '' : self::name($person),
            $removed,
        );
    }

    /** Nombre y primer apellido, o cadena vacía: nunca el correo (mismo criterio que `UserDirectory`). */
    private static function name(Person $person): string
    {
        return trim(($person->given_name ?? '').' '.($person->family_name_1 ?? ''));
    }
}
