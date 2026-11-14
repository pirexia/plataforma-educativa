<?php

namespace App\Modules\Backoffice\Http\Resources;

use App\Modules\Backoffice\Domain\TenantProvisioningState;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * api.md §2.4.1. `provisioning.state` es un enumerado de respuesta
 * derivado, nunca una columna (`ADR-034 OPEN-13`, api.md §2.4.1) —
 * cálculo en `TenantProvisioningState`, reutilizado también por la ficha
 * de salud de `1.6d` (`RN-BO-22` aplicado por analogía).
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
        return [
            'state' => TenantProvisioningState::resolve($this->resource),
            'started_at' => $this->created_at?->toJSON(),
        ];
    }
}
