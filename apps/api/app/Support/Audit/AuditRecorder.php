<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Support\Http\RequestId;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use RuntimeException;

/**
 * ADR-035 §4/§9: orquesta la escritura de una fila en `audit_logs` desde
 * el ciclo de vida del ORM (RecordsAuditTrail). `changes` es NULL cuando
 * no hay diff que registrar (p. ej. delete/restore sin deleted_at
 * resoluble); nunca se llama fuera de un contexto de tenant activo.
 */
final class AuditRecorder
{
    public function __construct(
        private readonly AuditChangeBuilder $changeBuilder,
    ) {}

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $rawChanges
     */
    public function record(Model&Auditable $model, string $event, array $rawChanges = []): void
    {
        $tenantContext = app(TenantContext::class);

        if ($tenantContext->isPlatformMode()) {
            // TenantContext::runAsPlatform() no limpia tenantId(): un
            // futuro uso que combine "tenant activo" + "modo plataforma"
            // (hoy no ocurre, ver AuditRecorderTest) escribiría con el
            // tenant_id equivocado o violaría el NOT NULL si no hay
            // ninguno. Falla en cerrado: este mecanismo es para
            // audit_logs (tabla de tenant); el rastro de una operación de
            // plataforma es cosa de admin_action_logs (ADR-033 §7,
            // pendiente de 1.6), no de aquí.
            throw new RuntimeException(
                'AuditRecorder no escribe en modo plataforma (TenantContext::runAsPlatform()); '.
                'el rastro de esa operación corresponde a admin_action_logs, no a audit_logs.'
            );
        }

        if (! $tenantContext->hasTenant()) {
            // audit_logs es tabla de tenant (ADR-034 §3): sin contexto no
            // hay a qué tenant_id escribir. No ocurre hoy con los 5
            // modelos del núcleo (todos exigen tenant activo para
            // guardarse a sí mismos), documentado por si un futuro modelo
            // Auditable se creara alguna vez fuera de contexto.
            return;
        }

        $alias = Relation::getMorphAlias($model::class);

        if ($alias === $model::class) {
            throw new RuntimeException(
                $model::class.' no está en el morph map (ADR-034 §3): no se puede auditar con un alias estable.'
            );
        }

        AuditLog::create([
            'occurred_at' => now(),
            'actor_user_id' => AuditActor::resolveUserId(),
            'actor_type' => AuditActor::resolveType(),
            'auditable_type' => $alias,
            'auditable_id' => $model->getKey(),
            'auditable_public_id' => $model->public_id ?? null,
            'event' => $event,
            'changes' => $rawChanges === [] ? null : $this->changeBuilder->build($model, $rawChanges),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'request_id' => app(RequestId::class)->current(),
        ]);
    }
}
