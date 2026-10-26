<?php

namespace App\Modules\FamUnit\Application;

use App\Modules\Core\Domain\PersonDirectory;
use App\Modules\Core\Domain\PersonRef;
use App\Modules\Core\Domain\UserDirectory;
use App\Modules\Core\Domain\UserPersonRef;

/**
 * REQ-FAM-UNIT/api.md §1.2 a §1.4: resolución de nombres y `public_id` de personas y usuarios por la interfaz de
 * `Core\Domain` (`INV-007`), nunca leyendo `people` ni `users`. Memoizada **solo dentro de la petición** (se registra
 * con `scoped`, `RN-FAM-UNIT-34`): las representaciones piden el nombre de cada fila sin una consulta por fila.
 */
final class FamilyRefs
{
    /** @var array<int, PersonRef> */
    private array $persons = [];

    /** @var array<int, UserPersonRef> */
    private array $users = [];

    /** @var array<int, string> id interno de usuario => `public_id` */
    private array $actors = [];

    /** `revocacion_tutor.leer` del sujeto de la petición: si ve el campo `revocation` de los vínculos (`api.md §1.4`). */
    private bool $withRevocation = false;

    public function __construct(
        private readonly PersonDirectory $people,
        private readonly UserDirectory $userDirectory,
    ) {}

    /**
     * @param  list<int>  $personIds
     */
    public function preloadPersons(array $personIds): void
    {
        $missing = array_values(array_diff(array_unique($personIds), array_keys($this->persons)));

        foreach ($this->people->refsByIds($missing) as $id => $ref) {
            $this->persons[$id] = $ref;
        }
    }

    /**
     * @param  list<int>  $personIds  ids de persona de los tutores
     */
    public function preloadUsers(array $personIds): void
    {
        $missing = array_values(array_diff(array_unique($personIds), array_keys($this->users)));

        foreach ($this->people->usersByPersonIds($missing) as $personId => $ref) {
            $this->users[$personId] = $ref;
        }
    }

    /**
     * `public_id` de los usuarios que verificaron o revocaron un vínculo.
     *
     * @param  list<int>  $userIds
     */
    public function preloadActors(array $userIds): void
    {
        $missing = array_values(array_diff(array_unique($userIds), array_keys($this->actors)));

        foreach ($this->userDirectory->refsByIds($missing) as $id => $ref) {
            $this->actors[$id] = $ref->publicId;
        }
    }

    public function actorPublicId(int $userId): ?string
    {
        $this->preloadActors([$userId]);

        return $this->actors[$userId] ?? null;
    }

    public function showRevocation(bool $show): void
    {
        $this->withRevocation = $show;
    }

    public function showsRevocation(): bool
    {
        return $this->withRevocation;
    }

    public function person(int $personId): ?PersonRef
    {
        $this->preloadPersons([$personId]);

        return $this->persons[$personId] ?? null;
    }

    public function user(int $personId): ?UserPersonRef
    {
        $this->preloadUsers([$personId]);

        return $this->users[$personId] ?? null;
    }
}
