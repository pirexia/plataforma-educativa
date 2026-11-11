<?php

namespace App\Modules\Alum\Http\Resources;

use App\Modules\Alum\Domain\Models\GroupChangeRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * REQ-ALUM/api.md §3: solicitud de cambio de grupo con su matrícula (`public_id` y alumno), curso académico, grupos de
 * origen y destino, motivo, estado y quién y cuándo decidió. Los atributos `*_view` los pone
 * `GroupChangeService::attachViews()` (datos de otros módulos, leídos por sus interfaces).
 *
 * @mixin GroupChangeRequest
 */
class GroupChangeRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'enrollment' => $this->getAttribute('enrollment_view') ?? ['public_id' => null, 'student' => ['public_id' => null, 'display_name' => '']],
            'academic_year' => $this->getAttribute('year_view') ?? ['public_id' => null, 'code' => null],
            'from_group' => $this->getAttribute('from_group_view'),
            'to_group' => $this->getAttribute('to_group_view'),
            'reason' => $this->reason,
            'status' => $this->status,
            'requested_by' => $this->getAttribute('requested_by_view'),
            'decided_by' => $this->getAttribute('decided_by_view'),
            'decided_at' => $this->decided_at,
            'decision_note' => $this->decision_note,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
