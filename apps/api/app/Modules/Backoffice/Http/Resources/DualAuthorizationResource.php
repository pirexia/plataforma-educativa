<?php

namespace App\Modules\Backoffice\Http\Resources;

use App\Modules\Backoffice\Domain\Models\DualAuthorization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * api.md §2.8. Incluye el `payload` congelado, para que quien aprueba vea
 * exactamente qué aprueba.
 *
 * @mixin DualAuthorization
 */
class DualAuthorizationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'action' => $this->action->value,
            'payload' => $this->payload,
            'reason' => $this->reason,
            'requested_by' => $this->whenLoaded('requester', fn () => $this->requester?->public_id),
            'requested_at' => $this->requested_at->toJSON(),
            'expires_at' => $this->expires_at->toJSON(),
            'status' => $this->status->value,
            'approved_by' => $this->when(
                $this->approved_by !== null,
                fn () => $this->approver?->public_id,
            ),
            'approved_at' => $this->approved_at?->toJSON(),
            'resolution_reason' => $this->resolution_reason,
            'executed_at' => $this->executed_at?->toJSON(),
            'execution_error' => $this->execution_error,
        ];
    }
}
