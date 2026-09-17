<?php

namespace App\Modules\Backoffice\Application;

use App\Modules\Backoffice\Domain\AdminActionLogAction;
use App\Modules\Backoffice\Domain\Models\AdminActionLog;
use App\Modules\Backoffice\Domain\TenantProvisioningState;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * `GET /tenants/{public_id}/health`, `REQ-BO-004` reducido, sub-paso
 * `1.6d`. `funcional.md §5.9`, `api.md §2.10.1`. Ensambla los cuatro
 * bloques de la ficha, cada uno con su alcance declarado — mezclar en
 * una sola ficha datos de plataforma y datos del centro es cómo alguien
 * acaba buscando «la versión de este centro» (`RN-BO-97`).
 *
 * Enteramente de lectura (`RN-BO-96`): ninguna llamada de esta clase
 * escribe nada. No pasa por `runAsPlatform()` para las consultas
 * directas por `pgsql_platform` (`jobs`, `failed_jobs`, `migrations`):
 * ninguna de esas tres tablas es `TenantModel`, así que no hay
 * `TenantScope` del que escapar. La única que sí lo necesita
 * (`module_subscriptions`) la resuelve `ModuleSubscriptionsService`.
 */
final class TenantHealthService
{
    /**
     * `RN-BO-83`: el bloque «última incidencia de plataforma» sólo
     * cubre lo que `jobs`/`failed_jobs` no pueden — los cuatro trabajos
     * que el backoffice despacha sin tenant activo (`RN-BO-90`,
     * `funcional.md §5.9.3`).
     *
     * @var list<AdminActionLogAction>
     */
    private const PLATFORM_INCIDENT_ACTIONS = [
        AdminActionLogAction::TenantAprovisionamientoFallido,
        AdminActionLogAction::TenantGraciaVencida,
    ];

    /**
     * `api.md §2.10.1`: las últimas migraciones de plataforma que se
     * muestran. No hay un número fijado por la especificación; diez es
     * suficiente para ver el despliegue más reciente sin convertir la
     * ficha de un centro en un listado de migraciones.
     */
    private const MIGRATIONS_SHOWN = 10;

    public function __construct(
        private readonly ModuleSubscriptionsService $modules,
        private readonly TenantJobsSummary $jobsSummary,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forTenant(Tenant $tenant): array
    {
        $subscriptions = $this->modules->subscriptionsFor($tenant);
        $contracted = $subscriptions->filter(fn ($subscription): bool => (bool) $subscription->enabled)->count();

        return array_filter([
            'platform' => [
                'version' => (string) config('app.version'),
                'migrations' => $this->recentMigrations(),
            ],
            'tenant' => [
                'status' => $tenant->status->value,
                'suspended_at' => $tenant->suspended_at?->toJSON(),
                'grace_period_ends_at' => $tenant->grace_period_ends_at?->toJSON(),
                'grace_period_expired_at' => $tenant->grace_period_expired_at?->toJSON(),
                'provisioning' => ['state' => TenantProvisioningState::resolve($tenant)],
            ],
            'jobs' => $this->jobsSummary->forTenant($tenant, (int) config('backoffice.health_failed_jobs_window_hours')),
            'platform_incident' => $this->platformIncident($tenant),
            'modules' => [
                'contracted' => $contracted,
                'dependency_inconsistencies' => $this->modules->dependencyInconsistenciesFor($tenant),
            ],
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return list<array{name: string, batch: int}>
     */
    private function recentMigrations(): array
    {
        return DB::connection('pgsql_platform')->table('migrations')
            ->orderByDesc('id')
            ->limit(self::MIGRATIONS_SHOWN)
            ->get(['migration', 'batch'])
            ->map(fn (object $row): array => ['name' => $row->migration, 'batch' => (int) $row->batch])
            ->all();
    }

    /**
     * `RN-BO-83`: se omite el bloque entero si no hay ninguna incidencia.
     *
     * @return array{action: string, occurred_at: string}|null
     */
    private function platformIncident(Tenant $tenant): ?array
    {
        $incident = AdminActionLog::query()
            ->where('affected_tenant_id', $tenant->id)
            ->whereIn('action', self::PLATFORM_INCIDENT_ACTIONS)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();

        if ($incident === null) {
            return null;
        }

        return [
            'action' => $incident->action->value,
            'occurred_at' => $incident->occurred_at->toJSON(),
        ];
    }
}
