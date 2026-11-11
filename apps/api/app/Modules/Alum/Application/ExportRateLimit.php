<?php

namespace App\Modules\Alum\Application;

use App\Models\User;
use App\Support\Api\ApiException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\RateLimiter;

/**
 * REQ-ALUM/api.md §6 (1.15-b, decisión del usuario del 2026-11-11), patrón de `FamUnit\Application\ConsentRateLimit`:
 * límite de pedir la exportación de matrículas. Clave por centro y usuario. Cuenta **todos** los intentos (también los
 * rechazados). Límites en `config/alum.php` (`export.rate_limit`). Al agotarse: `429 too-many-requests` con
 * `Retry-After` y el texto `alum.errors.export_rate_limited` en el idioma de la petición.
 */
final class ExportRateLimit
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function hit(User $actor): void
    {
        $key = $this->tenant->rateLimitKey("alum-export:{$actor->id}");
        $attempts = (int) config('alum.export.rate_limit.attempts', 10);
        $decay = (int) config('alum.export.rate_limit.decay_seconds', 3600);

        if (RateLimiter::tooManyAttempts($key, $attempts)) {
            throw ApiException::tooManyRequests(RateLimiter::availableIn($key), 'alum.errors.export_rate_limited', ['minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)]);
        }

        RateLimiter::hit($key, $decay);
    }
}
