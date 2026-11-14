<?php

namespace App\Modules\Alum\Application;

use App\Support\Api\ApiException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\RateLimiter;

/**
 * REQ-ALUM/funcional.md §4.6 `RN-ALUM-52` (1.15-c T2b) [DERIVADA, patrón de `PickupReadRateLimit` de `fam_unit`]: límite de las
 * lecturas de la fotografía de un alumno, que frena la descarga masiva por una cuenta comprometida. Cubo por centro y usuario
 * (`alum.photo.read_rate_limit`, 300 por hora), contando todas las lecturas. Al agotarse: `429 too-many-requests` con
 * `Retry-After` y `alum.errors.photo_read_rate_limited`.
 */
final class PhotoReadRateLimit
{
    public function __construct(private readonly TenantContext $tenant) {}

    /** @param  int|null  $userId  el usuario autenticado; `null` (consola, trabajos) comparte un cubo propio */
    public function hit(?int $userId): void
    {
        $key = $this->tenant->rateLimitKey('alum-photo-read:'.($userId ?? 'system'));
        $attempts = (int) config('alum.photo.read_rate_limit.attempts', 300);
        $decay = (int) config('alum.photo.read_rate_limit.decay_seconds', 3600);

        if (RateLimiter::tooManyAttempts($key, $attempts)) {
            throw ApiException::tooManyRequests(RateLimiter::availableIn($key), 'alum.errors.photo_read_rate_limited', ['minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)]);
        }

        RateLimiter::hit($key, $decay);
    }
}
