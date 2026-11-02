<?php

namespace App\Modules\FamUnit\Application;

use App\Support\Api\ApiException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\RateLimiter;

/**
 * REQ-FAM-UNIT/funcional.md §21.6 `RN-FAM-UNIT-93` (1.14b-a, `ADR-068 §5.5`) [DERIVADA, patrón de `RN-FAM-UNIT-66`]: límite de
 * las lecturas de la lista de recogida y de las fotografías, que frena la descarga masiva de fotos de terceros por una
 * cuenta comprometida. Cubo por centro y usuario (`fam_unit.pickup.read_rate_limit`, 300 por hora por defecto), contando
 * **todas** las lecturas. Al agotarse: `429 too-many-requests` con `Retry-After` y el texto
 * `fam_unit.errors.pickup_read_rate_limited` en el idioma de la petición. Se aplica **dentro** de la lectura
 * (`AuthorizedPickupList::entriesFor()`), no en el controlador, para que ningún consumidor pueda saltárselo.
 */
final class PickupReadRateLimit
{
    public function __construct(private readonly TenantContext $tenant) {}

    /** @param  int|null  $userId  el usuario autenticado; `null` (consola, trabajos) comparte un cubo propio */
    public function hit(?int $userId): void
    {
        $key = $this->tenant->rateLimitKey('fam-unit-pickup-read:'.($userId ?? 'system'));
        $attempts = (int) config('fam_unit.pickup.read_rate_limit.attempts', 300);
        $decay = (int) config('fam_unit.pickup.read_rate_limit.decay_seconds', 3600);

        if (RateLimiter::tooManyAttempts($key, $attempts)) {
            throw ApiException::tooManyRequests(RateLimiter::availableIn($key), 'fam_unit.errors.pickup_read_rate_limited', ['minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)]);
        }

        RateLimiter::hit($key, $decay);
    }
}
