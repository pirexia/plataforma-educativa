<?php

namespace App\Modules\Acad\Application;

use App\Modules\Core\Domain\ActiveUserRef;
use App\Modules\Core\Domain\UserDirectory;

/**
 * REQ-ACAD/funcional.md §18.4.1 (`RN-ACAD-92`), §18.7: resolución de los
 * usuarios a los que se asigna docencia, siempre por la interfaz de
 * `Core\Domain` (`AR-01`), nunca consultando `users`. Memoizada por petición
 * (se registra con `scoped`): las representaciones piden el nombre de cada
 * profesor sin una consulta por fila.
 */
final class StaffDirectory
{
    /** @var array<int, ActiveUserRef> */
    private array $refs = [];

    public function __construct(private readonly UserDirectory $users) {}

    /** `RN-ACAD-92`: usuario activo del centro por `public_id`; `null` si no existe, es de otro centro o no está activo. */
    public function findActive(string $publicId): ?ActiveUserRef
    {
        $ref = $this->users->findActiveByPublicId($publicId);

        if ($ref !== null) {
            $this->refs[$ref->id] = $ref;
        }

        return $ref;
    }

    /**
     * Carga de una vez los usuarios de una página de resultados.
     *
     * @param  list<int>  $ids
     */
    public function preload(array $ids): void
    {
        $missing = array_values(array_diff(array_unique($ids), array_keys($this->refs)));

        foreach ($this->users->refsByIds($missing) as $id => $ref) {
            $this->refs[$id] = $ref;
        }
    }

    /** `{public_id, name}` del usuario con ese id interno, o `null` si ya no existe. */
    /**
     * @return array{public_id: string, name: string}|null
     */
    public function summary(int $id): ?array
    {
        $this->preload([$id]);

        return isset($this->refs[$id])
            ? ['public_id' => $this->refs[$id]->publicId, 'name' => $this->refs[$id]->displayName !== '' ? $this->refs[$id]->displayName : __('acad.staff.unnamed')]
            : null;
    }
}
