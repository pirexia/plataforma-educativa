<?php

namespace App\Http\Middleware;

use App\Models\ModuleSubscription;
use App\Support\Api\ApiException;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * RMOD-009 (funcional.md §8, operacion.md §1). No aplica a REQ-CORE, que
 * es siempre-activo por diseño: la usan los DEMÁS módulos, declarada en
 * su ruta como `module-enabled:<code>`.
 *
 * Falla en cerrado: ausencia de fila en module_subscriptions = módulo
 * desactivado (ADR-034 §5). Caché de prefijo de tenant (ADR-033 §9, vía
 * el prefijo automático de TenantContext), TTL corto, invalidada en la
 * escritura de la suscripción — no solo por TTL (issue #7).
 */
class EnsureModuleEnabled
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function handle(Request $request, Closure $next, string $moduleCode): Response
    {
        if (! $this->isEnabled($moduleCode)) {
            throw ApiException::moduleDisabled();
        }

        return $next($request);
    }

    private function isEnabled(string $moduleCode): bool
    {
        if (! $this->tenantContext->hasTenant()) {
            return false;
        }

        return Cache::remember(
            "modules:{$moduleCode}:enabled",
            300,
            static fn (): bool => ModuleSubscription::query()
                ->where('module_code', $moduleCode)
                ->where('enabled', true)
                ->exists(),
        );
    }
}
