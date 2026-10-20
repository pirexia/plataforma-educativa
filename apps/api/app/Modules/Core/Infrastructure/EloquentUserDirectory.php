<?php

namespace App\Modules\Core\Infrastructure;

use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Core\Domain\ActiveUserRef;
use App\Modules\Core\Domain\UserDirectory;
use App\Modules\Core\Domain\UserDirectoryEntry;

final class EloquentUserDirectory implements UserDirectory
{
    public function findByPublicId(string $publicId): ?UserDirectoryEntry
    {
        $user = User::query()->with('person')->where('public_id', $publicId)->first();

        if ($user === null) {
            return null;
        }

        $person = $user->person;
        $displayName = trim(($person->given_name ?? '').' '.($person->family_name_1 ?? ''));

        return new UserDirectoryEntry(
            publicId: $user->public_id,
            email: $user->email,
            displayName: $displayName !== '' ? $displayName : $user->email,
            preferredLocale: $person->locale ?? 'es-ES',
        );
    }

    /**
     * REQ-AUTH/funcional.md §8.1. RN-AUTH-06: la búsqueda ya es por el
     * tenant activo (RLS + *scope* de `TenantModel`), nunca global.
     */
    public function findActiveByEmail(string $email): ?User
    {
        return User::query()->with('person')->where('email', $email)->first();
    }

    public function findActiveByPublicId(string $publicId): ?ActiveUserRef
    {
        $user = User::query()->with('person')->where('public_id', $publicId)->where('status', UserStatus::Activo)->first();

        return $user === null ? null : new ActiveUserRef((int) $user->id, (string) $user->public_id, self::displayName($user));
    }

    public function displayNamesByIds(array $ids): array
    {
        return array_map(static fn (ActiveUserRef $ref): string => $ref->displayName, $this->refsByIds($ids));
    }

    public function refsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $refs = [];

        foreach (User::query()->withTrashed()->with('person')->whereIn('id', array_values(array_unique($ids)))->get() as $user) {
            // Un usuario borrado conserva la referencia (id, public_id) pero nunca el nombre (#446, B-3).
            $removed = $user->trashed();

            $refs[(int) $user->id] = new ActiveUserRef(
                (int) $user->id,
                (string) $user->public_id,
                $removed ? '' : self::displayName($user),
                $removed,
            );
        }

        return $refs;
    }

    /**
     * Nombre visible de las referencias `ActiveUserRef` (`REQ-ACAD-002`, `RN-ACAD-92`):
     * nombre y primer apellido de la persona, o cadena vacía si no tiene. **Nunca el
     * correo**: lo muestran módulos cuyos lectores no tienen `usuario.leer`; el
     * consumidor pone su propio texto neutro traducido.
     */
    private static function displayName(User $user): string
    {
        $person = $user->person;

        return trim(($person->given_name ?? '').' '.($person->family_name_1 ?? ''));
    }
}
