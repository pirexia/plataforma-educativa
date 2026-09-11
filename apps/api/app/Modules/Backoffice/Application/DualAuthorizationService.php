<?php

namespace App\Modules\Backoffice\Application;

use App\Modules\Backoffice\Domain\AdminActionLogAction;
use App\Modules\Backoffice\Domain\DualAuthorizationAction;
use App\Modules\Backoffice\Domain\DualAuthorizationCapability;
use App\Modules\Backoffice\Domain\DualAuthorizationStatus;
use App\Modules\Backoffice\Domain\Models\DualAuthorization;
use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Modules\Backoffice\Domain\PlatformCapabilityMap;
use App\Support\Api\ApiException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * REQ-BO-007, funcional.md §5.7, datos.md §3. Mecanismo genérico:
 * solicitar, aprobar, rechazar y ejecutar una operación congelada.
 * `RN-BO-19` (aprobador distinto del solicitante) vive en el motor —el
 * `CHECK dual_authorizations_distinct_approver`— y este servicio no la
 * sustituye, la respeta: la comprobación de aquí es solo para dar un
 * mensaje mejor (`CA-BO-062` comprueba la del motor por SQL directo).
 */
final class DualAuthorizationService
{
    public function __construct(
        private readonly AdminActionLogRecorder $recorder,
        private readonly TenantLifecycleService $tenantLifecycle,
    ) {}

    public function approve(DualAuthorization $authorization, PlatformAdmin $approver, ?string $resolutionReason): DualAuthorization
    {
        $this->guardResolvable($authorization);
        $this->guardCapability($authorization, $approver);

        if ($authorization->requested_by === $approver->id) {
            throw ApiException::conflict('bo.dual_auth.same_actor');
        }

        DB::connection('pgsql_platform')->transaction(function () use ($authorization, $approver, $resolutionReason): void {
            try {
                $authorization->forceFill([
                    'status' => DualAuthorizationStatus::Aprobada,
                    'approved_by' => $approver->id,
                    'approved_at' => now(),
                    'resolution_reason' => $resolutionReason,
                ])->save();
            } catch (QueryException) {
                throw ApiException::conflict('bo.dual_auth.same_actor');
            }

            $this->recorder->record(
                action: AdminActionLogAction::AutorizacionAprobada,
                subjectType: 'dual_authorization',
                subjectId: $authorization->id,
                subjectPublicId: $authorization->public_id,
                reason: $resolutionReason,
            );
        });

        $this->execute($authorization);

        return $authorization->refresh();
    }

    public function reject(DualAuthorization $authorization, PlatformAdmin $rejecter, string $resolutionReason): DualAuthorization
    {
        $this->guardResolvable($authorization);
        $this->guardCapability($authorization, $rejecter);

        DB::connection('pgsql_platform')->transaction(function () use ($authorization, $resolutionReason): void {
            $authorization->forceFill([
                'status' => DualAuthorizationStatus::Rechazada,
                'resolution_reason' => $resolutionReason,
            ])->save();

            $this->recorder->record(
                action: AdminActionLogAction::AutorizacionRechazada,
                subjectType: 'dual_authorization',
                subjectId: $authorization->id,
                subjectPublicId: $authorization->public_id,
                reason: $resolutionReason,
            );
        });

        return $authorization->refresh();
    }

    private function guardResolvable(DualAuthorization $authorization): void
    {
        if ($authorization->status !== DualAuthorizationStatus::Pendiente) {
            throw ApiException::conflict('bo.dual_auth.already_resolved');
        }

        if ($authorization->expires_at->isPast()) {
            throw ApiException::conflict('bo.dual_auth.expired');
        }
    }

    /** permisos.md §5.2: no hay `autorizacion.aprobar`; se exige la capacidad de la acción autorizada. */
    private function guardCapability(DualAuthorization $authorization, PlatformAdmin $actor): void
    {
        $required = DualAuthorizationCapability::requiredFor($authorization->action);

        if (! PlatformCapabilityMap::grants($actor->roles(), $required)) {
            throw ApiException::forbidden();
        }
    }

    /**
     * `RN-BO-20`: si la ejecución falla porque los parámetros congelados
     * ya no son válidos, la solicitud queda `fallida` con su motivo — en
     * una escritura **separada** de la transacción que falló, para que
     * el registro de "aprobada" y el de "fallida" sobrevivan aunque la
     * operación en sí se revierta.
     */
    private function execute(DualAuthorization $authorization): void
    {
        try {
            DB::connection('pgsql_platform')->transaction(function () use ($authorization): void {
                match ($authorization->action) {
                    DualAuthorizationAction::TenantEliminar => $this->tenantLifecycle->executeApprovedDeletion($authorization),
                    default => throw new LogicException(
                        "Ejecución no implementada todavía para {$authorization->action->value}."
                    ),
                };

                $authorization->forceFill([
                    'status' => DualAuthorizationStatus::Ejecutada,
                    'executed_at' => now(),
                ])->save();

                $this->recorder->record(
                    action: AdminActionLogAction::AutorizacionEjecutada,
                    subjectType: 'dual_authorization',
                    subjectId: $authorization->id,
                    subjectPublicId: $authorization->public_id,
                );
            });
        } catch (Throwable $e) {
            $authorization->forceFill([
                'status' => DualAuthorizationStatus::Fallida,
                'execution_error' => mb_substr($e->getMessage(), 0, 1000),
            ])->save();

            $this->recorder->record(
                action: AdminActionLogAction::AutorizacionFallida,
                subjectType: 'dual_authorization',
                subjectId: $authorization->id,
                subjectPublicId: $authorization->public_id,
                context: ['error' => $e->getMessage()],
            );

            // RN-BO-20: los parámetros congelados han dejado de ser
            // válidos (por ejemplo, el tenant volvió a `activo` entre la
            // solicitud y la aprobación) — la solicitud queda `fallida`
            // arriba, y la respuesta lo dice con su propio código.
            throw ApiException::conflict('bo.dual_auth.payload_mismatch');
        }
    }
}
