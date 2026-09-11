<?php

namespace App\Modules\Backoffice\Application;

use App\Modules\Backoffice\Domain\AdminActionLogAction;
use App\Modules\Backoffice\Domain\BackofficeHostLabel;
use App\Modules\Backoffice\Domain\DualAuthorizationAction;
use App\Modules\Backoffice\Domain\DualAuthorizationStatus;
use App\Modules\Backoffice\Domain\Models\DualAuthorization;
use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Modules\Backoffice\Domain\Models\TenantLifecycleEvent;
use App\Modules\Backoffice\Domain\PlatformCapabilityMap;
use App\Modules\Backoffice\Domain\TenantTransitionCapability;
use App\Modules\Backoffice\Infrastructure\Jobs\CloneTenant;
use App\Modules\Backoffice\Infrastructure\Jobs\ProvisionTenant;
use App\Modules\Backoffice\Infrastructure\Jobs\RevokeTenantSessions;
use App\Modules\Core\Domain\TenantAdministrator;
use App\Modules\Core\Domain\TenantInitialSettings;
use App\Support\Api\ApiException;
use App\Support\Tenancy\PlatformAccessPurpose;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantResolutionCache;
use App\Support\Tenancy\TenantStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * REQ-BO-001. El ciclo de vida completo de un tenant: alta en dos fases
 * (funcional.md §5.3), las cinco transiciones de `RN-BO-12` (§5.4, §5.5),
 * clonación (§5.6) y cambio de `slug` (api.md §2.5). No aprovisiona ni
 * clona por sí mismo: se lo pide a `REQ-CORE` por `TenantProvisioner`
 * (`INV-007`, `ADR-048`) desde los trabajos en cola que este servicio
 * despacha.
 */
final class TenantLifecycleService
{
    public function __construct(
        private readonly AdminActionLogRecorder $recorder,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * funcional.md §5.3.3: fase 1, síncrona, dentro de la petición. Valida,
     * inserta la fila en `en_alta`, escribe su transición y su auditoría,
     * invalida la caché de resolución y encola la fase 2
     * (`ProvisionTenant`). Envuelta en `runAsPlatform(BackofficeEscritura,
     * …)`, tal como exige la especificación para esta operación concreta.
     */
    public function create(
        string $name,
        string $slug,
        string $reason,
        TenantInitialSettings $settings,
        TenantAdministrator $administrator,
        PlatformAdmin $actor,
    ): Tenant {
        $this->guardSlugAvailable($slug);
        $this->guardSlugNotReserved($slug);

        return $this->tenantContext->runAsPlatform(
            PlatformAccessPurpose::BackofficeEscritura,
            function () use ($name, $slug, $reason, $settings, $administrator, $actor): Tenant {
                return DB::connection('pgsql_platform')->transaction(function () use ($name, $slug, $reason, $settings, $administrator, $actor): Tenant {
                    $tenant = Tenant::create([
                        'slug' => $slug,
                        'name' => $name,
                        'status' => TenantStatus::EnAlta,
                    ]);

                    TenantLifecycleEvent::create([
                        'affected_tenant_id' => $tenant->id,
                        'from_status' => null,
                        'to_status' => TenantStatus::EnAlta,
                        'reason' => $reason,
                        'occurred_at' => now(),
                        'performed_by' => $actor->id,
                    ]);

                    $this->recorder->record(
                        action: AdminActionLogAction::TenantCreado,
                        subjectType: 'tenant',
                        subjectId: $tenant->id,
                        subjectPublicId: $tenant->public_id,
                        affectedTenantId: $tenant->id,
                        reason: $reason,
                    );

                    DB::connection('pgsql_platform')->afterCommit(function () use ($tenant, $settings, $administrator): void {
                        TenantResolutionCache::forget($tenant->slug);
                        ProvisionTenant::dispatch($tenant->public_id, $settings, $administrator);
                    });

                    return $tenant;
                });
            },
        );
    }

    /**
     * api.md §2.4.2: el único camino para cambiar de estado. Resuelve la
     * capacidad exacta con `TenantTransitionCapability` (que también
     * rechaza, con `409`, cualquier arista fuera de `RN-BO-12`) y
     * bifurca hacia la ejecución simple o hacia la solicitud de doble
     * autorización cuando el destino es `eliminado`.
     */
    public function transition(
        Tenant $tenant,
        TenantStatus $to,
        string $reason,
        ?string $suspensionMessage,
        ?string $confirmationName,
        PlatformAdmin $actor,
    ): TenantTransitionResult {
        $from = $tenant->status;

        $required = TenantTransitionCapability::requiredFor($from, $to);

        if (! PlatformCapabilityMap::grants($actor->roles(), $required)) {
            throw ApiException::forbidden();
        }

        if ($to === TenantStatus::Eliminado) {
            return TenantTransitionResult::pendingAuthorization(
                $this->requestDeletion($tenant, $reason, $confirmationName, $actor),
            );
        }

        return TenantTransitionResult::simple(
            $this->executeSimpleTransition($tenant, $from, $to, $reason, $suspensionMessage, $actor),
        );
    }

    /**
     * api.md §2.4, `PATCH /tenants/{public_id}`. Nombre y
     * `suspension_message` — el `slug` va aparte (§2.5). No es una
     * transición: no toca `tenant_lifecycle_events`.
     *
     * @param  array{name?: string, suspension_message?: ?string}  $attributes
     */
    public function update(Tenant $tenant, array $attributes): Tenant
    {
        return DB::connection('pgsql_platform')->transaction(function () use ($tenant, $attributes): Tenant {
            $before = $tenant->only(array_keys($attributes));

            $tenant->forceFill($attributes)->save();

            $changes = [];
            foreach ($attributes as $key => $newValue) {
                $changes[$key] = [$before[$key] ?? null, $newValue];
            }

            $this->recorder->record(
                action: AdminActionLogAction::TenantActualizado,
                subjectType: 'tenant',
                subjectId: $tenant->id,
                subjectPublicId: $tenant->public_id,
                affectedTenantId: $tenant->id,
                changes: $changes,
            );

            return $tenant;
        });
    }

    /**
     * api.md §2.5. Ruta propia, no un campo de `PATCH`: cambia el nombre
     * DNS del centro. Invalida las **dos** claves de caché, la vieja y
     * la nueva (`RN-BO-51`).
     */
    public function changeSlug(Tenant $tenant, string $newSlug, string $reason): Tenant
    {
        $this->guardSlugAvailable($newSlug);
        $this->guardSlugNotReserved($newSlug);

        $oldSlug = $tenant->slug;

        return DB::connection('pgsql_platform')->transaction(function () use ($tenant, $newSlug, $oldSlug, $reason): Tenant {
            $tenant->forceFill(['slug' => $newSlug])->save();

            $this->recorder->record(
                action: AdminActionLogAction::TenantSlugCambiado,
                subjectType: 'tenant',
                subjectId: $tenant->id,
                subjectPublicId: $tenant->public_id,
                affectedTenantId: $tenant->id,
                reason: $reason,
                changes: ['slug' => [$oldSlug, $newSlug]],
            );

            DB::connection('pgsql_platform')->afterCommit(function () use ($oldSlug, $newSlug): void {
                TenantResolutionCache::forget($oldSlug);
                TenantResolutionCache::forget($newSlug);
            });

            return $tenant;
        });
    }

    /**
     * funcional.md §5.6.3, §5.6.4, `RN-BO-59`. Fase 1 de la clonación:
     * misma forma que el alta (crea el destino en `en_alta`, escribe su
     * transición y su auditoría propia `tenant.clonado`) y encola la
     * fase 2 (`CloneTenant`).
     */
    public function clone(
        Tenant $source,
        string $name,
        string $slug,
        string $reason,
        TenantAdministrator $administrator,
        PlatformAdmin $actor,
    ): Tenant {
        if (in_array($source->status, [TenantStatus::Eliminado, TenantStatus::EnAlta], true)) {
            throw BoValidationError::forField('source', 'bo.tenant.clone_source_invalid');
        }

        $this->guardSlugAvailable($slug);
        $this->guardSlugNotReserved($slug);

        return $this->tenantContext->runAsPlatform(
            PlatformAccessPurpose::BackofficeEscritura,
            function () use ($source, $name, $slug, $reason, $administrator, $actor): Tenant {
                return DB::connection('pgsql_platform')->transaction(function () use ($source, $name, $slug, $reason, $administrator, $actor): Tenant {
                    $target = Tenant::create([
                        'slug' => $slug,
                        'name' => $name,
                        'status' => TenantStatus::EnAlta,
                    ]);

                    TenantLifecycleEvent::create([
                        'affected_tenant_id' => $target->id,
                        'from_status' => null,
                        'to_status' => TenantStatus::EnAlta,
                        'reason' => $reason,
                        'occurred_at' => now(),
                        'performed_by' => $actor->id,
                    ]);

                    $this->recorder->record(
                        action: AdminActionLogAction::TenantClonado,
                        subjectType: 'tenant',
                        subjectId: $target->id,
                        subjectPublicId: $target->public_id,
                        affectedTenantId: $target->id,
                        reason: $reason,
                        context: ['source_tenant_public_id' => $source->public_id],
                    );

                    DB::connection('pgsql_platform')->afterCommit(function () use ($source, $target, $administrator): void {
                        TenantResolutionCache::forget($target->slug);
                        CloneTenant::dispatch($source->public_id, $target->public_id, $administrator);
                    });

                    return $target;
                });
            },
        );
    }

    /**
     * `RN-BO-18`, §5.5.2 (los cuatro cerrojos): la confirmación por
     * nombre exacto (literal, `RN-BO-57`) y la creación de la solicitud
     * `pendiente`. La reautenticación y la capacidad ya se comprobaron
     * antes de llegar aquí.
     */
    private function requestDeletion(Tenant $tenant, string $reason, ?string $confirmationName, PlatformAdmin $actor): DualAuthorization
    {
        if ($confirmationName === null || $confirmationName !== $tenant->name) {
            throw BoValidationError::forField('confirmation_name', 'bo.tenant.name_mismatch');
        }

        $payload = ['tenant_public_id' => $tenant->public_id];
        $fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::connection('pgsql_platform')->transaction(function () use ($tenant, $reason, $payload, $fingerprint, $actor): DualAuthorization {
            try {
                $authorization = DualAuthorization::create([
                    'action' => DualAuthorizationAction::TenantEliminar,
                    'payload' => $payload,
                    'payload_fingerprint' => $fingerprint,
                    'reason' => $reason,
                    'requested_by' => $actor->id,
                    'requested_at' => now(),
                    'expires_at' => now()->addMinutes((int) config('backoffice.dual_authorization_ttl_minutes')),
                    'status' => DualAuthorizationStatus::Pendiente,
                ]);
            } catch (QueryException) {
                // Índice único parcial (action, payload_fingerprint) WHERE
                // status = 'pendiente': ya hay una solicitud viva idéntica.
                throw ApiException::conflict('bo.dual_auth.already_resolved');
            }

            $this->recorder->record(
                action: AdminActionLogAction::AutorizacionSolicitada,
                subjectType: 'dual_authorization',
                subjectId: $authorization->id,
                subjectPublicId: $authorization->public_id,
                affectedTenantId: $tenant->id,
                reason: $reason,
            );

            return $authorization;
        });
    }

    /**
     * §5.5.2, `RN-BO-56`. Se invoca desde `DualAuthorizationService`
     * cuando se aprueba una solicitud de `tenant.eliminar`, dentro de la
     * transacción que esa clase ya abre. Si el tenant ha dejado de estar
     * en condiciones (`RN-BO-20`: "si los parámetros hubieran dejado de
     * ser válidos, falla y queda fallida"), lanza y no ejecuta nada.
     */
    public function executeApprovedDeletion(DualAuthorization $authorization): void
    {
        $tenantPublicId = $authorization->payload['tenant_public_id'] ?? null;
        $tenant = $tenantPublicId !== null ? Tenant::query()->where('public_id', $tenantPublicId)->first() : null;

        if ($tenant === null || $tenant->status !== TenantStatus::EnBaja) {
            throw new RuntimeException(
                'El tenant ya no está en condiciones de ser eliminado: los parámetros congelados de la '.
                'solicitud ya no son válidos (RN-BO-20).'
            );
        }

        $tenant->forceFill(['status' => TenantStatus::Eliminado])->save();
        $tenant->delete();

        TenantLifecycleEvent::create([
            'affected_tenant_id' => $tenant->id,
            'from_status' => TenantStatus::EnBaja,
            'to_status' => TenantStatus::Eliminado,
            'reason' => $authorization->reason,
            'occurred_at' => now(),
            'performed_by' => $authorization->approved_by,
            'dual_authorization_id' => $authorization->id,
        ]);

        $this->recorder->record(
            action: AdminActionLogAction::TenantEliminado,
            subjectType: 'tenant',
            subjectId: $tenant->id,
            subjectPublicId: $tenant->public_id,
            affectedTenantId: $tenant->id,
            reason: $authorization->reason,
            context: ['dual_authorization_id' => $authorization->public_id],
        );

        DB::connection('pgsql_platform')->afterCommit(function () use ($tenant): void {
            TenantResolutionCache::forget($tenant->slug);
            RevokeTenantSessions::dispatch($tenant->id);
        });
    }

    /**
     * funcional.md §5.4, §5.5. Suspender/reactivar y dar de baja/rescatar
     * — las cuatro transiciones que no exigen doble autorización
     * (`RN-BO-62`). `RN-BO-58`: volver a `activo` limpia lo que puso la
     * transición que se deshace.
     */
    private function executeSimpleTransition(
        Tenant $tenant,
        TenantStatus $from,
        TenantStatus $to,
        string $reason,
        ?string $suspensionMessage,
        PlatformAdmin $actor,
    ): Tenant {
        return DB::connection('pgsql_platform')->transaction(function () use ($tenant, $from, $to, $reason, $suspensionMessage, $actor): Tenant {
            $attributes = ['status' => $to];
            $graceEndsAt = null;

            if ($to === TenantStatus::Suspendido) {
                $attributes['suspended_at'] = now();
                $attributes['suspension_message'] = $suspensionMessage;
            } elseif ($from === TenantStatus::Suspendido && $to === TenantStatus::Activo) {
                $attributes['suspended_at'] = null;
                $attributes['suspension_message'] = null;
            } elseif ($to === TenantStatus::EnBaja) {
                $graceEndsAt = now()->addDays(90);
                $attributes['grace_period_ends_at'] = $graceEndsAt;
            } elseif ($from === TenantStatus::EnBaja && $to === TenantStatus::Activo) {
                $attributes['grace_period_ends_at'] = null;
                $attributes['grace_period_expired_at'] = null;
            }

            $tenant->forceFill($attributes)->save();

            TenantLifecycleEvent::create([
                'affected_tenant_id' => $tenant->id,
                'from_status' => $from,
                'to_status' => $to,
                'reason' => $reason,
                'occurred_at' => now(),
                'performed_by' => $actor->id,
                'grace_period_ends_at' => $graceEndsAt,
            ]);

            $this->recorder->record(
                action: $this->actionFor($from, $to),
                subjectType: 'tenant',
                subjectId: $tenant->id,
                subjectPublicId: $tenant->public_id,
                affectedTenantId: $tenant->id,
                reason: $reason,
            );

            DB::connection('pgsql_platform')->afterCommit(fn () => TenantResolutionCache::forget($tenant->slug));

            return $tenant;
        });
    }

    private function actionFor(TenantStatus $from, TenantStatus $to): AdminActionLogAction
    {
        return match (true) {
            $to === TenantStatus::Suspendido => AdminActionLogAction::TenantSuspendido,
            $from === TenantStatus::Suspendido && $to === TenantStatus::Activo => AdminActionLogAction::TenantReactivado,
            $to === TenantStatus::EnBaja => AdminActionLogAction::TenantBajaIniciada,
            $from === TenantStatus::EnBaja && $to === TenantStatus::Activo => AdminActionLogAction::TenantRescatado,
            default => throw new RuntimeException("Transición sin acción de auditoría: {$from->value} -> {$to->value}."),
        };
    }

    private function guardSlugAvailable(string $slug): void
    {
        if (Tenant::query()->where('slug', $slug)->exists()) {
            throw BoValidationError::forField('slug', 'bo.tenant.slug_taken');
        }
    }

    private function guardSlugNotReserved(string $slug): void
    {
        if (BackofficeHostLabel::matches($slug)) {
            throw BoValidationError::forField('slug', 'bo.tenant.slug_reserved');
        }
    }
}
