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
    ): AdminActionLog {
        $admin = Auth::guard('platform')->user();

        return AdminActionLog::create([
            'public_id' => (string) Str::ulid(),
            'occurred_at' => now(),
            'actor_type' => $this->resolveActorType($admin),
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
