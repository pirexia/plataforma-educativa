<?php

namespace App\Modules\Auth\Domain;

use Illuminate\Support\Carbon;

/**
 * datos.md §A.3. Objeto de valor: `password_reset_tokens` no es un
 * TenantModel (clave primaria compuesta (tenant_id, email), sin `id` ni
 * `public_id`) y su repositorio consulta por query builder, no por
 * Eloquent — issue #18, funcional.md §7.2.
 */
final class PasswordResetToken
{
    public function __construct(
        public readonly string $email,
        public readonly Carbon $expiresAt,
    ) {}
}
