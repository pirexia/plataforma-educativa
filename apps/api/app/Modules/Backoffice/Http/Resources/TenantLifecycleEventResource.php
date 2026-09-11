<?php

namespace App\Modules\Backoffice\Http\Resources;

use App\Modules\Backoffice\Domain\Models\TenantLifecycleEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * api.md §2.4, `GET /tenants/{public_id}/lifecycle-events`. Vista del
 * *backoffice* (conexión `pgsql_platform`, BYPASSRLS): a diferencia de la
 * consulta del propio centro (que no existe como *endpoint* en 1.6b),
 * aquí no hay restricción de columnas — es la herramienta interna del
 * operador.
 *
 * @mixin TenantLifecycleEvent
 */
class TenantLifecycleEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'from_status' => $this->from_status?->value,
            'to_status' => $this->to_status->value,
            'reason' => $this->reason,
            'occurred_at' => $this->occurred_at->toJSON(),
            'performed_by' => $this->whenLoaded('performer', fn () => $this->performer?->public_id),
            'dual_authorization_id' => $this->whenLoaded('dualAuthorization', fn () => $this->dualAuthorization?->public_id),
            'grace_period_ends_at' => $this->grace_period_ends_at?->toJSON(),
        ];
    }
}
