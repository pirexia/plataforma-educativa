<?php

namespace App\Modules\Backoffice\Http\Resources;

use App\Modules\Backoffice\Domain\Models\AdminActionLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * api.md §2.9. Vista del backoffice: corre con `plataforma_platform`
 * (BYPASSRLS) y ve la tabla entera, a diferencia de la proyección de seis
 * columnas que consulta el propio centro (`GET /api/v1/platform-actions`,
 * REQ-CORE), que no usa este *resource*.
 *
 * @mixin AdminActionLog
 */
class AdminActionLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'occurred_at' => $this->occurred_at,
            'actor_type' => $this->actor_type->value,
            'actor_platform_admin_public_id' => $this->actor?->public_id,
            'affected_tenant_public_id' => $this->affectedTenant?->public_id,
            'subject_type' => $this->subject_type,
            'subject_public_id' => $this->subject_public_id,
            'action' => $this->action->value,
            'reason' => $this->reason,
            'context' => $this->context,
        ];
    }
}
