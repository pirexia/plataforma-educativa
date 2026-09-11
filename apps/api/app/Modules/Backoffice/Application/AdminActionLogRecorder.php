<?php

namespace App\Modules\Backoffice\Application;

use App\Modules\Backoffice\Domain\AdminActionLogAction;
use App\Modules\Backoffice\Domain\AdminActionLogActorType;
use App\Modules\Backoffice\Domain\Models\AdminActionLog;
use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Support\Http\RequestId;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * REQ-BO-007, datos.md §4. El único camino para escribir en
 * `admin_action_logs` (CA-BO-020): toda operación de escritura del
 * backoffice, cuando termina con éxito, deja aquí su entrada — actor,
 * acción, sujeto, motivo, `affected_tenant_id` cuando proceda, IP,
 * user-agent y request_id (INV-003, INV-013).
 *
 * No implementa `Auditable`/`AuditRecorder` (ADR-035): esa maquinaria es
 * para `audit_logs`, tabla de tenant. `admin_action_logs` tiene su
 * propio vocabulario (RN-BO-30) y se escribe explícitamente desde cada
 * servicio, nunca desde un observer genérico de ciclo de vida.
 *
 * **Hallazgo de `1.6b`, severidad Media (issue #191)**: `runningInConsole()`
 * es verdadero durante **cualquier** proceso arrancado por `artisan`, sin
 * distinguir un operador tecleando un comando de un *worker* de cola
 * procesando un `ShouldQueue` o de la propia suite de tests (`vendor/bin/
 * pest` también es CLI) — así que la heurística por defecto no puede
 * producir `system` para un trabajo en cola o una tarea programada, pese
 * a que varios de ellos (`ExpireDualAuthorizations`,
 * `tenant.aprovisionamiento_fallido`, `tenant.gracia_vencida`,
 * `tenant.actualizado` de la fase 2 del alta) lo necesitan. `$actorType`
 * deja que el llamador lo declare explícitamente cuando su propio
 * contexto —y no el del proceso PHP— es lo que decide el tipo de actor;
 * `null` (todos los llamadores existentes del chasis) conserva la
 * heurística de siempre, sin cambiar su comportamiento.
 */
final class AdminActionLogRecorder
{
    public function __construct(
        private readonly RequestId $requestId,
    ) {}

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>|null  $changes
     * @param  array<string, mixed>|null  $context
     */
    public function record(
        AdminActionLogAction $action,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?string $subjectPublicId = null,
        ?int $affectedTenantId = null,
        ?string $reason = null,
        ?array $changes = null,
        ?array $context = null,
        ?AdminActionLogActorType $actorType = null,
    ): AdminActionLog {
        $admin = Auth::guard('platform')->user();

        return AdminActionLog::create([
            'public_id' => (string) Str::ulid(),
            'occurred_at' => now(),
            'actor_type' => $actorType ?? $this->resolveActorType($admin),
            'actor_platform_admin_id' => $admin instanceof PlatformAdmin ? $admin->id : null,
            'affected_tenant_id' => $affectedTenantId,
            'subject_type' => $subjectType ?? 'platform',
            'subject_id' => $subjectId,
            'subject_public_id' => $subjectPublicId,
            'action' => $action,
            'reason' => $reason,
            'changes' => $changes,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'request_id' => $this->requestId->current(),
            'context' => $context,
        ]);
    }

    private function resolveActorType(mixed $admin): AdminActionLogActorType
    {
        if ($admin instanceof PlatformAdmin) {
            return AdminActionLogActorType::PlatformAdmin;
        }

        return app()->runningInConsole() ? AdminActionLogActorType::Console : AdminActionLogActorType::System;
    }
}
