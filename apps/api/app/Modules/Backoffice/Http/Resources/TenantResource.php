<?php

namespace App\Modules\Backoffice\Http\Resources;

use App\Modules\Backoffice\Domain\AdminActionLogAction;
use App\Modules\Backoffice\Domain\Models\AdminActionLog;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * api.md §2.4.1. `provisioning.state` es un enumerado de respuesta
 * derivado, nunca una columna (`ADR-034 OPEN-13`, api.md §2.4.1): con
 * `status = 'en_alta'` más la existencia o no de
 * `tenant.aprovisionamiento_fallido` está todo dicho. La consulta extra
 * solo se ejecuta para tenants en `en_alta` — la inmensa mayoría no la
 * paga.
 *
 * @mixin Tenant
 */
class TenantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'slug' => $this->slug,
            'name' => $this->name,
            'status' => $this->status->value,
            'provisioning' => $this->provisioning(),
            'suspended_at' => $this->suspended_at?->toJSON(),
            'suspension_message' => $this->suspension_message,
            'grace_period_ends_at' => $this->grace_period_ends_at?->toJSON(),
            'grace_period_expired_at' => $this->grace_period_expired_at?->toJSON(),
            'created_at' => $this->created_at?->toJSON(),
        ];
    }

    /**
     * @return array{state: string, started_at: ?string}
     */
    private function provisioning(): array
    {
        if ($this->status !== TenantStatus::EnAlta) {
            return ['state' => 'completado', 'started_at' => $this->created_at?->toJSON()];
        }

        $failed = AdminActionLog::query()
            ->where('affected_tenant_id', $this->id)
            ->where('action', AdminActionLogAction::TenantAprovisionamientoFallido)
            ->exists();

        return [
            'state' => $failed ? 'fallido' : 'en_curso',
            'started_at' => $this->created_at?->toJSON(),
        ];
    }
}
