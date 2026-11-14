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
        if ($this->exceeded($bucket, $keySuffix)) {
            throw ApiException::tooManyRequests(RateLimiter::availableIn($this->key($bucket, $keySuffix)));
        }
    }

    /**
     * REQ-AUTH-002 (1.4), `api.md §E.4.1`/`§E.7.1`, `RN-AUTH-93`: igual
     * que `hit()`, pero sin lanzar. El *callback* de OAuth es el único
     * *endpoint* del producto que no puede responder `problem+json` bajo
     * ningún concepto —siempre `302` con un código de la lista cerrada de
     * `§E.4.2`—, así que no puede usar `hit()` tal cual. El llamante
     * decide a qué código de resultado cerrado mapea el límite excedido
     * (`GoogleOAuthCallbackController` usa `error_proveedor`, el mismo
     * criterio con el que ya se presenta cualquier fallo transitorio del
     * proveedor sin distinguir la causa exacta al usuario).
     */
    public function exceeded(string $bucket, string $keySuffix): bool
    {
        /** @var array{max: int, decay: int} $limit */
        $limit = config("auth-local.rate_limits.{$bucket}");
        $key = $this->key($bucket, $keySuffix);

        if (RateLimiter::tooManyAttempts($key, $limit['max'])) {
            return true;
        }

        RateLimiter::hit($key, $limit['decay']);

        return false;
    }

    private function key(string $bucket, string $keySuffix): string
    {
        return $this->tenantContext->rateLimitKey("{$bucket}:{$keySuffix}");
    }
}
