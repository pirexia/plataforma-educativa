<?php

namespace App\Modules\Acad\Domain;

/**
 * REQ-ACAD/funcional.md §7.2 (primer consumidor: `ALUM`, `1.15-b`): resuelve un
 * grupo a su resumen. Solo filas vivas del centro actual (INV-001): un
 * identificador de otro centro o de un grupo borrado es indistinguible de uno
 * inexistente (`null`).
 */
interface GroupDirectory
{
    public function findByPublicId(string $publicId): ?GroupSummary;

    public function findById(int $id): ?GroupSummary;
}
