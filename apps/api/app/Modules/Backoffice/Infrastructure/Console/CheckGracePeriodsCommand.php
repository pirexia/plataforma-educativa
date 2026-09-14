<?php

namespace App\Modules\Backoffice\Infrastructure\Console;

use App\Modules\Backoffice\Application\AdminActionLogRecorder;
use App\Modules\Backoffice\Domain\AdminActionLogAction;
use App\Modules\Backoffice\Domain\AdminActionLogActorType;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantStatus;
use Illuminate\Console\Command;

/**
 * RN-BO-17, RN-BO-60, operacion.md §6.2, §6.4 (1.6b). Diaria. Para cada
 * tenant con `status = 'en_baja'`, `grace_period_ends_at < now()` y
 * `grace_period_expired_at IS NULL`:
 *
 * 1. Escribe `grace_period_expired_at = now()`, **una sola vez** — la
 *    condición de la consulta lo garantiza (`CA-BO-122`).
 * 2. Escribe **una** entrada en `admin_action_logs` con
 *    `action = 'tenant.gracia_vencida'`, `actor_type = 'system'`.
 *
 * Y no hace nada más: no borra, no anonimiza, no transita a `eliminado`
 * y no escribe en `tenant_lifecycle_events` (no ha habido transición).
 * "Avisar" es exactamente esto más el filtro `grace_expired` del
 * inventario (`api.md §3.3`): no hay infraestructura de notificaciones
 * hasta `REQ-COM` (1.19).
 */
class CheckGracePeriodsCommand extends Command
{
    protected $signature = 'bo:check-grace-periods';

    protected $description = 'Marca como vencidos los periodos de gracia de los tenants en_baja que han superado su plazo (RN-BO-17)';

    public function handle(AdminActionLogRecorder $recorder): int
    {
        $expired = Tenant::query()
            ->where('status', TenantStatus::EnBaja)
            ->whereNotNull('grace_period_ends_at')
            ->where('grace_period_ends_at', '<', now())
            ->whereNull('grace_period_expired_at')
            ->get();

        foreach ($expired as $tenant) {
            $tenant->forceFill(['grace_period_expired_at' => now()])->save();

            $recorder->record(
                action: AdminActionLogAction::TenantGraciaVencida,
                subjectType: 'tenant',
                subjectId: $tenant->id,
                subjectPublicId: $tenant->public_id,
                affectedTenantId: $tenant->id,
                actorType: AdminActionLogActorType::System,
            );
        }

        $this->info("Periodos de gracia vencidos marcados: {$expired->count()}.");

        return self::SUCCESS;
    }
}
