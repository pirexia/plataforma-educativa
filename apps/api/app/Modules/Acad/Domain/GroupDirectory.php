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

    /**
     * REQ-ALUM/funcional.md §5.2 (`RN-ALUM-26`, `RN-ACAD-21`), `1.15-b` (revisión de seguridad M2; ampliación aditiva): el
     * resumen del group **vivo** de ese id interno, releído **con bloqueo compartido** (`SELECT … FOR SHARE`) hasta el final
     * de la transacción en curso; `null` si no existe, está borrado o es de otro centro. Sin memoización: lee siempre de
     * la base. El borrado de `acad` bloquea la fila `FOR UPDATE` antes de consultar los vetos, así que un alta de matrícula
     * que llega aquí primero hace esperar al borrado (que luego ve la matrícula y la veta) y uno que llega después hace
     * que esta lectura devuelva `null`.
     *
     * @throws \LogicException si no hay una transacción abierta: un bloqueo sin transacción no protege nada
     */
    public function lockAliveForShare(int $id): ?GroupSummary;
}
