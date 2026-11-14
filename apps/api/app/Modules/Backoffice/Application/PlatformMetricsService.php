<?php

namespace App\Modules\Backoffice\Application;

use App\Models\Module;
use App\Models\ModuleSubscription;
use App\Modules\Backoffice\Domain\Models\TenantLifecycleEvent;
use App\Modules\Core\Domain\ModuleCatalog;
use App\Support\Tenancy\PlatformAccessPurpose;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantStatus;
use Illuminate\Support\Carbon;

/**
 * `GET /metrics/platform`, `GET /metrics/module-adoption`, `REQ-BO-006`
 * reducido, sub-paso `1.6d`. `funcional.md §5.10`, `api.md §2.10.4`,
 * `§2.10.5`.
 *
 * `RN-BO-94`: **las dos métricas corren dentro de una sola llamada a
 * `runAsPlatform(PlatformAccessPurpose::BackofficeLectura, …)`.** Fuera de
 * ese bloque, `module_subscriptions` (tabla de tenant, `TenantModel`) no
 * falla: `TenantScope` la filtra al tenant activo y el agregado sale
 * silenciosamente reducido a un solo centro. Es el modo de fallo que
 * `CA-BO-162` existe para atrapar.
 *
 * `RN-BO-95`: ninguna métrica se cachea ni se materializa — cada llamada
 * recalcula desde las tablas vivas.
 */
final class PlatformMetricsService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ModuleCatalog $catalog,
    ) {}

    /**
     * `RN-BO-91`: `tenants_by_status` incluye los borrados lógicos —
     * excluirlos dejaría `eliminado` siempre a cero, porque la
     * eliminación escribe `status` y `deleted_at` a la vez.
     *
     * `RN-BO-92`: altas, bajas y eliminaciones son tres series separadas
     * y nunca se suman. No hay `churn` (`REQ-SAAS-004`, fase 2).
     *
     * @return array<string, mixed>
     */
    public function platform(Carbon $from, Carbon $to): array
    {
        return $this->tenantContext->runAsPlatform(
            PlatformAccessPurpose::BackofficeLectura,
            function () use ($from, $to): array {
                $byStatus = Tenant::withTrashed()
                    ->selectRaw('status, count(*) as aggregate')
                    ->groupBy('status')
                    ->pluck('aggregate', 'status');

                $tenantsByStatus = [];

                foreach (TenantStatus::cases() as $status) {
                    $tenantsByStatus[$status->value] = (int) ($byStatus[$status->value] ?? 0);
                }

                $inWindow = fn () => TenantLifecycleEvent::query()
                    ->where('occurred_at', '>=', $from)
                    ->where('occurred_at', '<=', $to);

                return [
                    'tenants_by_status' => $tenantsByStatus,
                    'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                    // RN-BO-92: alta = from_status IS NULL, la única
                    // transición que lo tiene.
                    'created' => $inWindow()->whereNull('from_status')->count(),
                    'closed' => $inWindow()->where('to_status', TenantStatus::EnBaja->value)->count(),
                    'deleted' => $inWindow()->where('to_status', TenantStatus::Eliminado->value)->count(),
                ];
            },
        );
    }

    /**
     * `RN-BO-93`: la lista la marca el catálogo declarado
     * (`ModuleCatalog::all()`), no las filas existentes de
     * `module_subscriptions` — un módulo sin ninguna contratación
     * aparece con `0` (medido), y un esencial aparece marcado y sin
     * recuento (nunca `0`, que mentiría).
     *
     * **Un módulo `retired_at` ya no tiene `ModuleDescriptor`** —
     * `platform:sync-registry` lo marca precisamente porque su
     * `ServiceProvider` ha dejado de declararse (`ADR-034 §5`,
     * `SyncModuleRegistry::run()`), así que `ModuleCatalog::all()` nunca
     * lo devuelve. Para que RN-BO-93 se cumpla de verdad —«sigue
     * apareciendo, con su recuento real»— la tabla `modules` se añade
     * como segunda fuente, sólo para los códigos retirados: el catálogo
     * declarado marca **qué está vivo**, la tabla marca **qué hubo y ya
     * no se declara**. Un retirado no tiene `essential` que leer (no
     * hay código que lo diga): se trata como no esencial, que es lo
     * único que la tabla puede sostener.
     *
     * @return array<string, mixed>
     */
    public function moduleAdoption(): array
    {
        return $this->tenantContext->runAsPlatform(
            PlatformAccessPurpose::BackofficeLectura,
            function (): array {
                // El scope por defecto de Tenant (SoftDeletes) ya excluye
                // los `eliminado`: la eliminación escribe status y
                // deleted_at a la vez (funcional.md §5.5.2). Suspendidos y
                // en_baja siguen siendo clientes y cuentan.
                $ofTenants = Tenant::query()->count();

                $contractedByModule = ModuleSubscription::query()
                    ->where('enabled', true)
                    ->selectRaw('module_code, count(*) as aggregate')
                    ->groupBy('module_code')
                    ->pluck('aggregate', 'module_code');

                $declared = $this->catalog->all();
                $declaredCodes = array_map(static fn ($descriptor) => $descriptor->code, $declared);

                $data = [];

                foreach ($declared as $descriptor) {
                    $entry = [
                        'module_code' => $descriptor->code,
                        'phase' => $descriptor->phase,
                        'essential' => $descriptor->essential,
                        'retired' => false,
                    ];

                    if (! $descriptor->essential) {
                        $entry['contracted_tenants'] = (int) ($contractedByModule[$descriptor->code] ?? 0);
                    }

                    $data[] = $entry;
                }

                $retired = Module::query()->whereNotNull('retired_at')->whereNotIn('code', $declaredCodes)->pluck('phase', 'code');

                foreach ($retired as $code => $phase) {
                    $data[] = [
                        'module_code' => $code,
                        'phase' => $phase,
                        'essential' => false,
                        'retired' => true,
                        'contracted_tenants' => (int) ($contractedByModule[$code] ?? 0),
                    ];
                }

                return ['of_tenants' => $ofTenants, 'data' => $data];
            },
        );
    }
}
