<?php

namespace App\Modules\FamUnit\Application;

use App\Support\Api\ApiException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\RateLimiter;

/**
 * REQ-FAM-UNIT/funcional.md §21.6 `RN-FAM-UNIT-92` enmendada, `§22` D-66 (1.14b-a, decisión del usuario del 2026-11-02): límite
 * propio de la **búsqueda por documento** (`GET /pickup-persons?document_*`) y del **alta con reutilización**
 * (`POST /pickup-persons`), que de otro modo permitirían sondear documentos de personas del centro sin freno. Un cubo por centro,
 * usuario y acción (`fam_unit.pickup.person_rate_limit.lookup|register`, 60 por hora por defecto [DERIVADA]), distinto del de la
 * lectura de la lista (`PickupReadRateLimit`); cuenta todos los intentos. Al agotarse: `429` con `Retry-After`.
 */
final class PickupPersonRateLimit
{
    public function __construct(private readonly TenantContext $tenant) {}

    /** @param  'lookup'|'register'  $bucket */
    public function hit(string $bucket, int $userId): void
    {
        $key = $this->tenant->rateLimitKey("fam-unit-pickup-person-{$bucket}:{$userId}");
        $attempts = (int) config("fam_unit.pickup.person_rate_limit.{$bucket}.attempts", 60);
        $decay = (int) config("fam_unit.pickup.person_rate_limit.{$bucket}.decay_seconds", 3600);

        if (RateLimiter::tooManyAttempts($key, $attempts)) {
            throw ApiException::tooManyRequests(RateLimiter::availableIn($key), 'fam_unit.errors.pickup_person_rate_limited', ['minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)]);
        }

        RateLimiter::hit($key, $decay);
    }
}
