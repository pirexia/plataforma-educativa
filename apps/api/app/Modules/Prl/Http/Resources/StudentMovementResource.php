<?php

namespace App\Modules\Prl\Http\Resources;

use App\Modules\Prl\Application\PrlRefs;
use App\Modules\Prl\Domain\Models\StudentMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * REQ-PRL/api.md §8.4, `StudentMovement`. El alumno y quien recoge se nombran por el `public_id` de su persona; quien entrega
 * y quien registra, por el de su usuario; `corrects`, por el del movimiento corregido (que precarga el controlador en `PrlRefs`). **No** se exponen las referencias internas al vínculo ni a la autorización (son evidencia en base de
 * datos, no un dato de la API: `INV-007`). Los `public_id` y los nombres los precarga el controlador en `PrlRefs`. Código
 * técnico sin traducir (`ADR-038 §3.2`).
 *
 * @mixin StudentMovement
 */
class StudentMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $refs = app(PrlRefs::class);
        $student = $refs->person((int) $this->student_person_id);
        $picker = $this->picked_up_by_person_id === null ? null : $refs->person((int) $this->picked_up_by_person_id);

        return [
            'public_id' => $this->public_id,
            'student_person' => $student?->publicId,
            'direction' => $this->direction,
            'off_schedule' => (bool) $this->off_schedule,
            'off_schedule_reason' => $this->off_schedule_reason,
            'notes' => $this->notes,
            'picked_up_by' => $this->picked_up_by_person_id === null ? null : ['person' => $picker?->publicId, 'display_name' => $picker->displayName ?? ''],
            'basis' => $this->basis,
            'verification_method' => $this->verification_method,
            'delivered_by' => $refs->userPublicId((int) $this->delivered_by),
            'recorded_by' => $refs->userPublicId((int) $this->recorded_by),
            'corrects' => $this->corrects_movement_id === null ? null : $refs->correctedPublicId((int) $this->corrects_movement_id),
            'correction_reason' => $this->correction_reason,
            'occurred_at' => $this->occurred_at,
        ];
    }
}
