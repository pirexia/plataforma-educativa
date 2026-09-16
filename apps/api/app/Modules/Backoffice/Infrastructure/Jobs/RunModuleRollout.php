<?php

namespace App\Modules\Backoffice\Infrastructure\Jobs;

use App\Modules\Backoffice\Application\AdminActionLogRecorder;
use App\Modules\Backoffice\Application\ModuleSubscriptionsService;
use App\Modules\Backoffice\Domain\AdminActionLogAction;
use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Modules\Core\Domain\ModuleChange;
use App\Support\Api\ApiException;
use App\Support\Tenancy\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * `REQ-BO-002`, funcional.md §5.8.7, operacion.md §6.1. Recorre la lista
 * **congelada** de centros y, para cada uno, ejecuta las fases 1 y 2
 * completas de `ModuleSubscriptionsService::applyChange()` — una
 * transacción por centro, no una sobre N, en orden ascendente de
 * `tenants.id` (`RN-BO-78`).
 *
 * Corre sin sesión de administrador (es un trabajo en cola, `RN-BO-78`):
 * `Auth::guard('platform')->setUser($admin)` es lo que hace que
 * `runAsPlatform(BackofficeEscritura, …)` sea alcanzable desde aquí y que
 * `admin_action_logs` atribuya correctamente al operador que disparó (o
 * aprobó) el lote — mismo patrón que `ExecuteUserImport::handle()` usa
 * con `Auth::setUser($actor)` para el guard por defecto
 * (`RecordsAuthorship`).
 */
class RunModuleRollout implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<string>  $tenantPublicIds
     */
    public function __construct(
        public readonly string $moduleCode,
        public readonly bool $enabled,
        public readonly string $reason,
        public readonly bool $cascade,
        public readonly array $tenantPublicIds,
        public readonly string $platformAdminPublicId,
        public readonly ?string $dualAuthorizationPublicId = null,
    ) {
        $this->onQueue('backoffice-maintenance');
    }

    public function handle(ModuleSubscriptionsService $service, AdminActionLogRecorder $recorder): void
    {
        $admin = PlatformAdmin::query()->where('public_id', $this->platformAdminPublicId)->first();

        if ($admin === null) {
            return;
        }

        Auth::guard('platform')->setUser($admin);

        // RN-BO-78: orden ascendente de `tenants.id`, para que dos lotes
        // concurrentes con centros en común no se interbloqueen.
        // `withTrashed()` (RN-BO-81, CA-BO-144): el conjunto ejecutado es
        // el congelado en la solicitud — un centro eliminado entre la
        // solicitud y la aprobación tiene que seguir apareciendo aquí
        // para que `applyChange()` lo rechace por su estado y quede
        // "omitido y reportado"; con el scope por defecto (que excluye
        // los borrados lógicamente) desaparecería del lote en silencio,
        // sin dejar rastro de que se omitió.
        $tenants = Tenant::withTrashed()->whereIn('public_id', $this->tenantPublicIds)->orderBy('id')->get();

        $change = new ModuleChange($this->moduleCode, $this->enabled, $this->reason, $this->cascade);

        $applied = 0;
        $unchanged = 0;
        $omitted = [];
        $failed = [];

        foreach ($tenants as $tenant) {
            try {
                $result = $service->applyChange($tenant, $change);

                // issue #225 (Media, `/codex:review`): RN-BO-69 dice que
                // contratar lo ya contratado (o descontratar lo ya
                // descontratado) es no-operación completa, y no audita —
                // pero seguía contando como `applied` en este resumen,
                // exagerando cuántos centros cambiaron de verdad en un
                // lote reintentado.
                if ($result['outcome']->isEmpty()) {
                    $unchanged++;
                } else {
                    $applied++;
                }
            } catch (ApiException $e) {
                // RN-BO-71, RN-BO-78: una regla de negocio impide la
                // operación sobre ESTE centro (estado incompatible,
                // esencial, retirado, arrastre sin confirmar…) — se omite
                // y se reporta, el lote sigue.
                $omitted[] = ['tenant_public_id' => $tenant->public_id, 'reason_code' => $e->detailKey ?? $e->type];
            } catch (Throwable $e) {
                report($e);
                $failed[] = ['tenant_public_id' => $tenant->public_id, 'error' => $e->getMessage()];
            }
        }

        $recorder->record(
            action: AdminActionLogAction::ModuloMasivoEjecutado,
            subjectType: 'module_subscription',
            subjectPublicId: $this->moduleCode,
            reason: $this->reason,
            context: [
                'module_code' => $this->moduleCode,
                'enabled' => $this->enabled,
                'requested' => count($this->tenantPublicIds),
                'applied' => $applied,
                'unchanged' => $unchanged,
                'omitted' => $omitted,
                'failed' => $failed,
                'dual_authorization_id' => $this->dualAuthorizationPublicId,
            ],
        );
    }
}
