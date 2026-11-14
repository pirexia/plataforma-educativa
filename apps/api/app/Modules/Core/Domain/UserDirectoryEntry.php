<?php

namespace App\Modules\Core\Domain;

/**
 * funcional.md §7. Objeto de valor devuelto por `UserDirectory`: expone
 * solo lo que un consumidor externo (`REQ-COM`) necesita para dirigirse a
 * un usuario, no el modelo Eloquent completo (`INV-007`: interfaz, no
 * acoplamiento a la forma interna de `App\Models\User`).
 */
final class UserDirectoryEntry
{
    public function __construct(
        public readonly string $publicId,
        public readonly string $email,
        public readonly string $displayName,
        public readonly string $preferredLocale,
    ) {}
}
