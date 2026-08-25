<?php

namespace App\Modules\Auth\Application;

use App\Support\Api\ApiException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\RateLimiter;

/**
 * operacion.md §6. Es la única defensa activa de los seis endpoints
 * anónimos del módulo. Toda clave incluye el `tenant_id`
 * (`TenantContext::rateLimitKey()`, `ADR-033 §9`): un límite compartido
 * entre centros sería una fuga de disponibilidad entre tenants.
 */
final class RateLimitGuard
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @throws ApiException tooManyRequests() con Retry-After
     */
    public function hit(string $bucket, string $keySuffix): void
    {
        /** @var array{max: int, decay: int} $limit */
        $limit = config("auth-local.rate_limits.{$bucket}");
        $key = $this->tenantContext->rateLimitKey("{$bucket}:{$keySuffix}");

        if (RateLimiter::tooManyAttempts($key, $limit['max'])) {
            throw ApiException::tooManyRequests(RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, $limit['decay']);
    }
}
