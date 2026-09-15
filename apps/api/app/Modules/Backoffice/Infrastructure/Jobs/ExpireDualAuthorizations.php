<?php

namespace App\Modules\Backoffice\Infrastructure\Jobs;

use App\Modules\Backoffice\Application\AdminActionLogRecorder;
use App\Modules\Backoffice\Domain\AdminActionLogAction;
use App\Modules\Backoffice\Domain\DualAuthorizationStatus;
use App\Modules\Backoffice\Domain\Models\DualAuthorization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * operacion.md §6.2. Pasa a `caducada` lo vencido. `actor_type =
 * 'system'` (AdminActionLogRecorder lo resuelve solo: sin sesión de
 * plataforma y fuera de consola real de operador, cae en 'system'
 * cuando corre como job — ver AdminActionLogRecorder::resolveActorType()).
 *
 * En 1.6 no hay ningún consumidor que cree solicitudes con `action` del
 * vocabulario cerrado (llegan en 1.6b/1.6c): esta tarea corre sobre cero
 * filas hasta entonces, y es correcto que así sea — es infraestructura
 * genérica reutilizada, no lógica de negocio de este sub-paso.
 */
class ExpireDualAuthorizations implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct()
    {
        $this->onQueue('backoffice-maintenance');
    }

    public function handle(AdminActionLogRecorder $recorder): void
    {
        // Issue #210 (revisión de fix/REQ-BO-001-condiciones-de-carrera,
        // db-reviewer): el listado inicial no bloquea ninguna fila, así
        // que cada `save()` de más abajo corría fuera de transacción y
        // sin releer — si una autorización listada aquí como "vencida y
        // pendiente" se resolvía (aprobada/rechazada) justo antes de que
        // le tocara el turno, este job sobrescribía esa resolución con
        // `caducada`, corrompiendo el rastro de auditoría de una acción
        // destructiva (INV-003). Cada fila se releé bajo lockForUpdate()
        // dentro de su propia transacción y solo se marca caducada si
        // sigue pendiente y vencida en ese instante.
        $ids = DualAuthorization::query()
            ->where('status', DualAuthorizationStatus::Pendiente)
            ->where('expires_at', '<', now())
            ->pluck('id');

        foreach ($ids as $id) {
            DB::connection('pgsql_platform')->transaction(function () use ($id, $recorder): void {
                $authorization = DualAuthorization::query()->lockForUpdate()->find($id);

                if ($authorization === null
                    || $authorization->status !== DualAuthorizationStatus::Pendiente
                    || $authorization->expires_at->isFuture()
                ) {
                    return;
                }

                $authorization->forceFill(['status' => DualAuthorizationStatus::Caducada])->save();

                $recorder->record(
                    action: AdminActionLogAction::AutorizacionCaducada,
                    subjectPublicId: $authorization->public_id,
                );
            });
        }
    }
}
