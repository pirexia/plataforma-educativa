<?php

namespace App\Modules\Core\Domain;

/**
 * funcional.md §7: interfaz pública para que ningún módulo consulte
 * `audit_logs` directamente (INV-007).
 */
interface AuditQuery
{
    /**
     * @param  array<string, mixed>  $filters  from, to, actor_id, actor_type, event (list), auditable_type (list), auditable_id, module
     * @return array{logs: \Illuminate\Support\Collection, next_cursor: ?string, has_more: bool}
     */
    public function search(array $filters, ?string $cursor, int $limit): array;
}
