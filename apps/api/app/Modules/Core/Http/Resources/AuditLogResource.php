<?php

namespace App\Modules\Core\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * api.md §8. `changes` se devuelve tal cual está almacenado (ADR-035):
 * la API no redacta nada por su cuenta ni "rellena" lo redactado.
 *
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'occurred_at' => $this->occurred_at,
            'actor' => $this->actor === null ? null : [
                'public_id' => $this->actor->public_id,
                'display_name' => trim($this->actor->person->given_name.' '.$this->actor->person->family_name_1),
            ],
            'actor_type' => $this->actor_type,
            'auditable_type' => $this->auditable_type,
            'auditable_public_id' => $this->auditable_public_id,
            'event' => $this->event,
            'changes' => $this->changes,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'request_id' => $this->request_id,
        ];
    }
}
