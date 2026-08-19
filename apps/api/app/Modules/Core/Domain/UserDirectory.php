<?php

namespace App\Modules\Core\Domain;

/**
 * funcional.md §7: resolución de un usuario por `public_id` y su idioma
 * preferido, para que `REQ-COM` (1.19) no consulte `users`/`people`
 * directamente (`INV-007`).
 */
interface UserDirectory
{
    public function findByPublicId(string $publicId): ?UserDirectoryEntry;
}
