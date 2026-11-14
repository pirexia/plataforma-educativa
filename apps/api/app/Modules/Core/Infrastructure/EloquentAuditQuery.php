<?php

namespace App\Modules\Core\Infrastructure;

use App\Models\AuditLog;
use App\Models\User;
use App\Modules\Core\Application\CursorCodec;
use App\Modules\Core\Domain\AuditQuery;
use App\Support\Authorization\PermissionDecision;
use App\Support\Authorization\ScopedQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * api.md §8: orden `(occurred_at DESC, id DESC)`, paginación por cursor
 * cifrado (ADR-038 §4.4). Los filtros se aplican con `AuditLogFilter`
 * (compartido con `GenerateAuditLogExport`, issue #267).
 *
 * REQ-PERM/funcional.md §6 (1.5): el resolutor real de este paso —
 * `propios` sobre `auditoria` acota a `actor_user_id = subject`. La
 * restricción de ámbito **no** entra en la huella del cursor
 * (`CursorCodec::fingerprint()`, api.md §10): no la envía el cliente y no
 * puede cambiarla, así que un cambio de rol a mitad de paginación
 * devuelve menos filas en vez de invalidar el cursor con un 422 confuso.
 */
final class EloquentAuditQuery implements AuditQuery
{
    public function __construct(
        private readonly CursorCodec $cursorCodec,
        private readonly TenantContext $tenantContext,
        private readonly ScopedQuery $scopedQuery,
    ) {}

    /**
     * @return array{logs: Collection<int, AuditLog>, next_cursor: ?string, has_more: bool}
     */
    public function search(array $filters, ?string $cursor, int $limit, ?PermissionDecision $decision, User $subject): array
    {
        $fingerprint = $this->cursorCodec->fingerprint($filters);
        $query = $this->baseQuery($filters);

        if ($decision !== null) {
            $query = $this->scopedQuery->constrain($query, 'auditoria', $decision, $subject);
        }

        if ($cursor !== null) {
            [$occurredAt, $id] = $this->cursorCodec->decode($cursor, $fingerprint, $this->tenantContext->tenantId());

            $query->where(function (Builder $q) use ($occurredAt, $id): void {
                $q->where('occurred_at', '<', $occurredAt)
                    ->orWhere(function (Builder $q2) use ($occurredAt, $id): void {
                        $q2->where('occurred_at', '=', $occurredAt)->where('id', '<', $id);
                    });
            });
        }

        $logs = $query->orderByDesc('occurred_at')->orderByDesc('id')->limit($limit + 1)->get();

        $hasMore = $logs->count() > $limit;
        $logs = $logs->take($limit);

        $nextCursor = null;

        if ($hasMore && $logs->isNotEmpty()) {
            $last = $logs->last();
            $nextCursor = $this->cursorCodec->encode(
                $last->occurred_at->toJSON(),
                $last->id,
                $fingerprint,
                $this->tenantContext->tenantId(),
            );
        }

        return ['logs' => $logs, 'next_cursor' => $nextCursor, 'has_more' => $hasMore];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<AuditLog>
     */
    private function baseQuery(array $filters): Builder
    {
        return AuditLogFilter::apply(AuditLog::query()->with('actor'), $filters);
    }

    /**
     * RN-PERM-14, CA-PERM-011: si no existe ninguna entrada para esta
     * entidad (dentro del tenant), es una historia genuinamente vacía —
     * visible, `200` con lista vacía, sin cambios. Si existe **al menos
     * una** pero el ámbito del sujeto no la alcanza, no es visible: `404`.
     */
    public function isAuditableVisible(string $auditablePublicId, ?PermissionDecision $decision, User $subject): bool
    {
        $existsAtAll = AuditLog::query()->where('auditable_public_id', $auditablePublicId)->exists();

        if (! $existsAtAll) {
            return true;
        }

        if ($decision === null || $decision->isUnrestricted()) {
            return true;
        }

        $query = AuditLog::query()->where('auditable_public_id', $auditablePublicId);

        return $this->scopedQuery->constrain($query, 'auditoria', $decision, $subject)->exists();
    }
}
