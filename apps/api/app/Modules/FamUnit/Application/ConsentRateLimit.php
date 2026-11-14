<?php

namespace App\Modules\FamUnit\Application;

use App\Models\User;
use App\Support\Api\ApiException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\RateLimiter;

/**
 * REQ-FAM-UNIT/api.md §2.7, §4 (1.14-b, `RN-FAM-UNIT-76`, decisión del usuario del 2026-10-27, revisión de seguridad M1): límite
 * de registrar decisiones de imagen y de pedir el informe, con el patrón de `RevocationRateLimit`. Clave por centro y usuario, un
 * cubo propio por acción. Cuenta **todos** los intentos (también los rechazados). Límites en `config/fam_unit.php`
 * (`consent.rate_limit.decision` y `.export`). Al agotarse: `429 too-many-requests` con `Retry-After` y el texto
 * `fam_unit.errors.consent_rate_limited` en el idioma de la petición.
 */
final class ConsentRateLimit
{
    public function __construct(private readonly TenantContext $tenant) {}

    /** @param  'decision'|'export'  $bucket */
    public function hit(string $bucket, User $actor): void
    {
        $key = $this->tenant->rateLimitKey("fam-unit-consent-{$bucket}:{$actor->id}");
        $attempts = (int) config("fam_unit.consent.rate_limit.{$bucket}.attempts", 60);
        $decay = (int) config("fam_unit.consent.rate_limit.{$bucket}.decay_seconds", 3600);

        if (RateLimiter::tooManyAttempts($key, $attempts)) {
            throw ApiException::tooManyRequests(RateLimiter::availableIn($key), 'fam_unit.errors.consent_rate_limited', ['minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)]);
        }

        RateLimiter::hit($key, $decay);
    }
}
