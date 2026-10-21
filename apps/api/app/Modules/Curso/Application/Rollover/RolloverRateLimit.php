<?php

namespace App\Modules\Curso\Application\Rollover;

use App\Support\Api\ApiException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\RateLimiter;

/**
 * REQ-CURSO/api.md §2.1 (1.12b, revisión de seguridad M1): límite por centro de las dos solicitudes que encolan un trabajo
 * largo (`POST /academic-year-rollovers` y `POST …/revert`), con un cubo propio cada una. Cuenta **todos** los intentos
 * (también los rechazados por validación) para que no sirvan de sondeo. Límite en `config/curso.php`
 * (`rollover.rate_limit`, 6 por hora por defecto). Al agotarse: `429 too-many-requests` con `Retry-After` y el texto
 * `curso.errors.rollover_rate_limited` en el idioma de la petición.
 */
final class RolloverRateLimit
{
    public function __construct(private readonly TenantContext $tenant) {}

    /** @param  'request'|'revert'  $bucket */
    public function hit(string $bucket): void
    {
        $key = $this->tenant->rateLimitKey("curso-rollover-{$bucket}");
        $attempts = (int) config('curso.rollover.rate_limit.attempts', 6);
        $decay = (int) config('curso.rollover.rate_limit.decay_seconds', 3600);

        if (RateLimiter::tooManyAttempts($key, $attempts)) {
            throw ApiException::tooManyRequests(RateLimiter::availableIn($key), 'curso.errors.rollover_rate_limited', ['minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)]);
        }

        RateLimiter::hit($key, $decay);
    }
}
