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
}
