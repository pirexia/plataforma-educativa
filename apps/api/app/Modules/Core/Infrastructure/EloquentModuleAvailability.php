<?php

namespace App\Modules\Core\Infrastructure;

use App\Models\ModuleSubscription;
use App\Support\Modules\ModuleAvailability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;

/**
 * REQ-PERM/operacion.md §6.2, ADR-044 §4.9: extrae a una interfaz propia lo
 * que antes solo consultaba `EnsureModuleEnabled` directamente contra
 * `ModuleSubscription`. Con esta clase hay una sola definición de
 * «utilizable» compartida por el middleware (RMOD-009) y por
 * `App\Support\Authorization\PermissionResolver` (filtro de inercia
 * `inerte_modulo`, funcional.md §4.1) — sin ella, las dos tendrían lecturas
 * independientes del mismo booleano, con dos oportunidades de divergir.
 *
 * `core` y `auth` no son desactivables (`REQ-CORE/operacion.md §1`,
 * `REQ-AUTH/operacion.md`): siempre utilizables, sin necesidad de fila en
 * `module_subscriptions`. El resto sigue el mismo mecanismo de
 * `EnsureModuleEnabled` desde 1.1: ausencia de fila = desactivado (falla en
 * cerrado), caché de prefijo de tenant con TTL corto.
 */
final class EloquentModuleAvailability implements ModuleAvailability
{
    /** @var list<string> */
    private const ALWAYS_ENABLED = ['core', 'auth'];

    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function isEnabled(string $moduleCode): bool
    {
        if (in_array($moduleCode, self::ALWAYS_ENABLED, true)) {
            return true;
        }

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
