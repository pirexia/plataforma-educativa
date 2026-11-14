<?php

namespace App\Modules\Backoffice\Application;

use App\Modules\Backoffice\Domain\AdminActionLogAction;
use App\Modules\Backoffice\Domain\AdminActionLogActorType;
use App\Modules\Backoffice\Domain\FailedJobPresentation;
use App\Support\Api\ApiException;
use App\Support\Tenancy\PlatformAccessPurpose;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantStatus;
use Illuminate\Support\Facades\DB;

/**
 * `REQ-BO-004`, `REQ-SUP-004`, sub-paso `1.6d`. `funcional.md §5.9.4`,
 * `api.md §2.10.3`. La única escritura de todo el sub-paso: localizar por
 * `uuid`, reencolar el *payload* literal (`RN-BO-85`) y borrar la fila,
 * todo por `pgsql_platform` (`RN-BO-86`) — el proveedor de trabajos
 * fallidos del framework (`queue:retry`) apunta al rol sin privilegios
 * desde `0.7` y no puede usarse.
 *
 * Dos caminos, una sola implementación (`funcional.md §5.9.4` última
 * línea, `RN-BO-22` aplicado por analogía): la API (`retryForTenant()`,
 * con comprobación de tenant y `404` si no coincide) y la consola
 * (`retryByUuid()`, usada por `bo:retry-provisioning`, sin comprobación
 * de tenant porque el llamador ya localizó la fila exacta).
 */
final class FailedJobRetryService
{
    /**
     * `RN-BO-87`: `eliminado` no admite reintento — escribir en un
     * centro que ya no se opera. Los otros cuatro sí, `en_alta` incluido
     * (reparar un aprovisionamiento a medias es justo lo que hace
     * falta), y `suspendido`/`en_baja` porque suspender bloquea el
     * acceso, no la maquinaria (`RN-BO-16`).
     *
     * @var list<TenantStatus>
     */
    private const RETRYABLE_STATUSES = [
        TenantStatus::EnAlta,
        TenantStatus::Activo,
        TenantStatus::Suspendido,
        TenantStatus::EnBaja,
    ];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AdminActionLogRecorder $recorder,
        private readonly TenantJobsSummary $jobsSummary,
    ) {}

    /**
     * `POST /tenants/{public_id}/failed-jobs/{uuid}/retry`. Capacidad
     * (`job.reintentar`) y reautenticación viva (`OPEN-BO-21`) ya las
     * comprobó el middleware de ruta.
     *
     * @return array<string, mixed> el bloque `jobs` actualizado (api.md §2.10.1)
     */
    public function retryForTenant(Tenant $tenant, string $uuid, ?string $reason): array
    {
        // funcional.md §5.9.4, "el orden, sin margen": estado del tenant
        // (RN-BO-87) antes que el motivo (RN-BO-85's guardReasonPresent).
        $this->guardTenantRetryable($tenant);
        $this->guardReasonPresent($reason);

        return $this->tenantContext->runAsPlatform(
            PlatformAccessPurpose::BackofficeEscritura,
            fn (): array => DB::connection('pgsql_platform')->transaction(
                function () use ($tenant, $uuid, $reason): array {
                    $row = DB::connection('pgsql_platform')->table('failed_jobs')
                        ->where('uuid', $uuid)
                        ->lockForUpdate()
                        ->first();

                    // RN-BO-90, CA-BO-153: inexistente, de otro centro (o
                    // sin tenant — los cuatro trabajos del backoffice) o ya
                    // reintentado (la fila ya no existe) responden todos
                    // igual: 404, nunca 403 — confirmaría que el uuid
                    // existe en algún sitio.
                    if ($row === null || FailedJobPresentation::tenantIdOf($row) !== $tenant->id) {
                        throw ApiException::notFound();
                    }

                    $this->reenqueue($row);

                    $this->recorder->record(
                        action: AdminActionLogAction::JobReintentado,
                        subjectType: 'failed_job',
                        subjectPublicId: $uuid,
                        affectedTenantId: $tenant->id,
                        reason: $reason,
                    );

                    return $this->jobsSummary->forTenant($tenant, (int) config('backoffice.health_failed_jobs_window_hours'));
                },
            ),
        );
    }

    /**
     * `bo:retry-provisioning`, `operacion.md §5.1`, `funcional.md §5.9.1`
     * última fila (hallazgo 2 de §5.9.6, `CA-BO-164`). El llamador ya
     * localizó el `uuid` exacto (búsqueda por `payload` serializado); no
     * hay tenant que comprobar porque `ProvisionTenant`/`CloneTenant` se
     * despachan sin tenant activo (`payload.tenant_id` nulo, `RN-BO-90`).
     *
     * Sin `runAsPlatform()`: no hay sesión de plataforma que la
     * primitiva pueda comprobar (`BackofficeAccessCheck::before()` exige
     * un admin autenticado incluso para `BackofficeEscritura`) y el
     * comando sigue sin necesitar capacidad — mismo precedente que
     * `CloseOrphanedPlatformSessions` (`operacion.md §6.3`): escritura
     * directa por `pgsql_platform`.
     */
    public function retryByUuid(string $uuid, ?int $affectedTenantId): bool
    {
        return DB::connection('pgsql_platform')->transaction(function () use ($uuid, $affectedTenantId): bool {
            $row = DB::connection('pgsql_platform')->table('failed_jobs')
                ->where('uuid', $uuid)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return false;
            }

            $this->reenqueue($row);

            $this->recorder->record(
                action: AdminActionLogAction::JobReintentado,
                subjectType: 'failed_job',
                subjectPublicId: $uuid,
                affectedTenantId: $affectedTenantId,
                reason: __('bo.job.reason.retried_via_console'),
                actorType: app()->runningInConsole() ? AdminActionLogActorType::Console : null,
            );

            return true;
        });
    }

    /**
     * `RN-BO-85`: el *payload* se reencola literal, sobre la conexión y
     * la cola declaradas por la propia fila (`failed_jobs.queue`), con
     * los intentos reiniciados — nunca recompuesto. Recomponerlo desde
     * el backoffice (que por construcción no tiene tenant) produciría un
     * *payload* con `tenant_id` nulo, corriendo sin filtro de RLS.
     *
     * Mismo formato que `DatabaseQueue::pushToDatabase()`, pero insertado
     * a mano por `pgsql_platform` (`RN-BO-86`) en vez de por
     * `Queue::connection('database')`, que resolvería la conexión
     * `plataforma_app` configurada en `queue.connections.database`.
     */
    private function reenqueue(object $row): void
    {
        $payload = json_decode((string) $row->payload, true);

        if (is_array($payload) && array_key_exists('attempts', $payload)) {
            $payload['attempts'] = 0;
        }

        DB::connection('pgsql_platform')->table('jobs')->insert([
            'queue' => $row->queue,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->getTimestamp(),
            'created_at' => now()->getTimestamp(),
        ]);

        DB::connection('pgsql_platform')->table('failed_jobs')->where('id', $row->id)->delete();
    }

    /**
     * `RN-BO-87`. `409 bo.job.tenant_state_invalid` para `eliminado`.
     */
    private function guardTenantRetryable(Tenant $tenant): void
    {
        if (! in_array($tenant->status, self::RETRYABLE_STATUSES, true)) {
            throw ApiException::conflict('bo.job.tenant_state_invalid', ['tenant_status' => $tenant->status->value]);
        }
    }

    /**
     * `422 bo.job.reason_required`: obligatorio y no vacío.
     */
    private function guardReasonPresent(?string $reason): void
    {
        if ($reason === null || trim($reason) === '') {
            throw BoValidationError::forField('reason', 'bo.job.reason_required');
        }
    }
}
