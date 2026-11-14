<?php

namespace App\Modules\Core\Infrastructure;

use App\Models\AuditLog;
use App\Modules\Core\Domain\AuditCatalog;
use Illuminate\Database\Eloquent\Builder;

/**
 * api.md §8: los filtros estructurados de `audit_logs`, en un único sitio.
 * Los usan el listado (`EloquentAuditQuery`) y la exportación
 * (`GenerateAuditLogExport`) para que no puedan divergir (ADR-054 §8.2,
 * issue #267). No acota por ámbito: eso lo hace `ScopedQuery` aparte.
 *
 * Claves: `occurred_at_from`, `occurred_at_to` (ADR-038 §5.2, issue #266),
 * `actor_id`, `actor_type` (lista), `event` (lista), `auditable_type` (lista),
 * `auditable_id`, `module` (lista, 1.9d S7). `module` se resuelve a los alias
 * del morph map que esos módulos declaran (`AuditCatalog`) — en 1.1 solo
 * existe `core`.
 */
final class AuditLogFilter
{
    /**
     * @param  Builder<AuditLog>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<AuditLog>
     */
    public static function apply(Builder $query, array $filters): Builder
    {
        if (isset($filters['occurred_at_from'])) {
            $query->where('occurred_at', '>=', $filters['occurred_at_from']);
        }

        if (isset($filters['occurred_at_to'])) {
            $query->where('occurred_at', '<=', $filters['occurred_at_to']);
        }

        if (isset($filters['actor_id'])) {
            $query->whereHas('actor', fn (Builder $q) => $q->where('public_id', $filters['actor_id']));
        }

        if (isset($filters['actor_type'])) {
            $query->whereIn('actor_type', (array) $filters['actor_type']);
        }

        if (isset($filters['event']) && $filters['event'] !== []) {
            $query->whereIn('event', (array) $filters['event']);
        }

        if (isset($filters['auditable_type']) && $filters['auditable_type'] !== []) {
            $query->whereIn('auditable_type', (array) $filters['auditable_type']);
        }

        if (isset($filters['auditable_id'])) {
            $query->where('auditable_public_id', $filters['auditable_id']);
        }

        if (isset($filters['module'])) {
            $query->whereIn('auditable_type', AuditCatalog::aliasesOf((array) $filters['module']) ?: ['__none__']);
        }

        return $query;
    }
}
