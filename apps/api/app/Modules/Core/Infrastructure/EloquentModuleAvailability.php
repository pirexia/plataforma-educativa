<?php

namespace App\Modules\Core\Infrastructure;

use App\Models\ModuleSubscription;
use App\Modules\Core\Domain\ModuleCatalog;
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
 * `RN-BO-63`, `CA-BO-036` (1.6c): `essential` sale del descriptor
 * declarado (`ModuleCatalog`, resuelto una sola vez por proceso), no de
 * la constante `ALWAYS_ENABLED` que existía hasta este sub-paso y que
 * **no se sustituye por otra constante** — con dos listas la divergencia
 * es cuestión de tiempo. El resto sigue el mismo mecanismo de
 * `EnsureModuleEnabled` desde 1.1: ausencia de fila = desactivado (falla en
 * cerrado), caché de prefijo de tenant con TTL corto.
 */
final class EloquentModuleAvailability implements ModuleAvailability
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ModuleCatalog $catalog,
    ) {}

    public function isEnabled(string $moduleCode): bool
    {
        if ($this->catalog->find($moduleCode)?->essential === true) {
            return true;
        }

        if (! $this->tenantContext->hasTenant()) {
            return false;
        }

        return Cache::remember(
            "modules:{$moduleCode}:enabled",
            300,
            // `->select('id')` (1.6c, datos.md §7.7): sin proyección
            // explícita, `exists()` compila un `select *` interno que
            // exige privilegio sobre TODAS las columnas — `plataforma_app`
            // ya no lo tiene tras el `REVOKE SELECT` de la migración de
            // privilegios. `id` y las dos columnas del `where` están
            // entre las concedidas.
            static fn (): bool => ModuleSubscription::query()
                ->select('id')
                ->where('module_code', $moduleCode)
                ->where('enabled', true)
                ->exists(),
        );
    }
}
