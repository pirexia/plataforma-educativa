<?php

namespace App\Modules\Core\Domain;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Authorization\PermissionDecision;
use Illuminate\Support\Collection;

/**
 * funcional.md §7: interfaz pública para que ningún módulo consulte
 * `audit_logs` directamente (INV-007).
 *
 * REQ-PERM/funcional.md §6 (1.5): `$decision` es la resolución ya
 * calculada de `auditoria.leer`/`.exportar` para `$subject` — si no es
 * `todos`, se acota con la API sancionada (`App\Support\Authorization\
 * ScopedQuery`), nunca con un filtro ad hoc.
 */
interface AuditQuery
{
    /**
     * @param  array<string, mixed>  $filters  from, to, actor_id, actor_type, event (list), auditable_type (list), auditable_id, module
     * @return array{logs: Collection<int, AuditLog>, next_cursor: ?string, has_more: bool}
     */
    public function search(array $filters, ?string $cursor, int $limit, ?PermissionDecision $decision, User $subject): array;

    /**
     * `RN-PERM-14`, api.md §9.3: distingue «no hay ninguna entrada de esta
     * entidad» (200 con lista vacía, sin cambios) de «hay entradas pero el
     * ámbito del sujeto no las alcanza» (404, nunca 403 ni una lista vacía
     * que parezca lo primero) — el «detalle implícito» de `auditable_id`
     * (funcional.md §6.1).
     */
    public function isAuditableVisible(string $auditablePublicId, ?PermissionDecision $decision, User $subject): bool;
}
