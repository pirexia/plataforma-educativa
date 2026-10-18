<?php

namespace App\Modules\Core\Domain;

use App\Models\User;

/**
 * funcional.md §7: resolución de un usuario por `public_id` y su idioma
 * preferido, para que `REQ-COM` (1.19) no consulte `users`/`people`
 * directamente (`INV-007`).
 *
 * REQ-AUTH/funcional.md §8.1 amplía la interfaz con `findActiveByEmail()`:
 * el login necesita el `User` completo (para verificar la contraseña,
 * reamasarla si hace falta, etc.), no un objeto de valor de presentación
 * — `User` es un modelo del núcleo compartido (`App\Models`), no interno
 * de este módulo, así que exponerlo aquí no incumple `INV-007`.
 */
interface UserDirectory
{
    public function findByPublicId(string $publicId): ?UserDirectoryEntry;

    /**
     * Solo devuelve un usuario **vivo** (sin `deleted_at`) del tenant
     * activo, exista o no un `status` utilizable — la comprobación de
     * `activo`/`pendiente`/`inactivo` es responsabilidad del llamador
     * (RN-AUTH-23).
     */
    public function findActiveByEmail(string $email): ?User;

    /**
     * REQ-ACAD-002 `RN-ACAD-92` (ampliación aprobada de `1.12-a`): usuario del
     * centro **activo** (`status = activo`, sin `deleted_at`) por `public_id`,
     * con su id interno para las FK de los módulos consumidores. `null` si no
     * existe, es de otro centro, está inactivo, pendiente o borrado. No se
     * comprueba ningún rol.
     */
    public function findActiveByPublicId(string $publicId): ?ActiveUserRef;

    /**
     * Nombre visible (el de `findByPublicId`) de usuarios por id interno,
     * vivos o no, del centro activo. Los ids que no existen no aparecen.
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    public function displayNamesByIds(array $ids): array;

    /**
     * Igual que `displayNamesByIds()` pero con el `public_id` además del nombre
     * (`ActiveUserRef`, sin garantía de que el usuario siga activo): los
     * consumidores que guardan el id interno necesitan el `public_id` para
     * representar al usuario (`REQ-ACAD-002`, `teacher.public_id`).
     *
     * @param  list<int>  $ids
     * @return array<int, ActiveUserRef>
     */
    public function refsByIds(array $ids): array;
}
