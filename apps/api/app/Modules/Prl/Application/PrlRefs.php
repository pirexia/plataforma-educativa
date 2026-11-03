<?php

namespace App\Modules\Prl\Application;

use App\Modules\Core\Domain\PersonDirectory;
use App\Modules\Core\Domain\PersonRef;
use App\Modules\Core\Domain\UserDirectory;

/**
 * REQ-PRL/api.md §8.4: resolución de nombres y `public_id` de personas y usuarios por las interfaces de `Core\Domain`
 * (`INV-007`), nunca leyendo `people` ni `users`. Memoizada **solo dentro de la petición** (se registra con `scoped`): las
 * representaciones piden el nombre de cada fila sin una consulta por fila.
 */
final class PrlRefs
{
    /** @var array<int, PersonRef> */
    private array $persons = [];

    /** @var array<int, string> id interno de usuario => `public_id` */
    private array $users = [];

    /** @var array<int, string> id interno de usuario => nombre visible (vacío si el usuario está borrado) */
    private array $userNames = [];

    /** @var array<int, string> id interno de movimiento => `public_id`, de los movimientos que algún otro corrige */
    private array $corrected = [];

    public function __construct(
        private readonly PersonDirectory $people,
        private readonly UserDirectory $userDirectory,
    ) {}

    /** @param  list<int>  $personIds */
    public function preloadPersons(array $personIds): void
    {
        $missing = array_values(array_diff(array_unique($personIds), array_keys($this->persons)));

        foreach ($this->people->refsByIds($missing) as $id => $ref) {
            $this->persons[$id] = $ref;
        }
    }

    /** @param  list<int>  $userIds */
    public function preloadUsers(array $userIds): void
    {
        $missing = array_values(array_diff(array_unique($userIds), array_keys($this->users)));

        foreach ($this->userDirectory->refsByIds($missing) as $id => $ref) {
            $this->users[$id] = $ref->publicId;
            $this->userNames[$id] = $ref->displayName;
        }
    }

    /** @param  array<int, string>  $corrected */
    public function setCorrected(array $corrected): void
    {
        $this->corrected = $corrected;
    }

    public function correctedPublicId(int $movementId): ?string
    {
        return $this->corrected[$movementId] ?? null;
    }

    public function person(int $personId): ?PersonRef
    {
        $this->preloadPersons([$personId]);

        return $this->persons[$personId] ?? null;
    }

    public function userPublicId(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        $this->preloadUsers([$userId]);

        return $this->users[$userId] ?? null;
    }

    /** Nombre visible del usuario (REQ-PRL-004, 1.14b-c2): vacío si el usuario está borrado o no existe; `null` si no hay usuario. */
    public function userDisplayName(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        $this->preloadUsers([$userId]);

        return $this->userNames[$userId] ?? '';
    }
}
