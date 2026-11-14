<?php

namespace App\Modules\FamUnit\Application;

use App\Models\User;
use App\Support\Api\ApiException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\RateLimiter;

/**
 * REQ-FAM-UNIT/api.md §2.5 (issue #470, decisión del usuario del 2026-10-27): límite de revocar y levantar la revocación,
 * con el patrón de `RolloverRateLimit` de `curso`. Clave por centro y usuario, un cubo propio por acción. Cuenta **todos**
 * los intentos. Límite en `config/fam_unit.php` (`revocation.rate_limit`, 30 por hora por defecto). Al agotarse:
 * `429 too-many-requests` con `Retry-After` y el texto `fam_unit.errors.revocation_rate_limited` en el idioma de la
 * petición. Solo estas dos acciones: lecturas y demás escrituras sin límite.
 */
final class RevocationRateLimit
{
    public function __construct(private readonly TenantContext $tenant) {}

    /** @param  'revoke'|'lift'  $bucket */
    public function hit(string $bucket, User $actor): void
    {
        $key = $this->tenant->rateLimitKey("fam-unit-revocation-{$bucket}:{$actor->id}");
        $attempts = (int) config('fam_unit.revocation.rate_limit.attempts', 30);
        $decay = (int) config('fam_unit.revocation.rate_limit.decay_seconds', 3600);

        if (RateLimiter::tooManyAttempts($key, $attempts)) {
            throw ApiException::tooManyRequests(RateLimiter::availableIn($key), 'fam_unit.errors.revocation_rate_limited', ['minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)]);
        }

        RateLimiter::hit($key, $decay);
    }
}
