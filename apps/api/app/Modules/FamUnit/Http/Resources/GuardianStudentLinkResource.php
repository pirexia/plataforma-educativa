<?php

namespace App\Modules\FamUnit\Http\Resources;

use App\Modules\FamUnit\Application\FamilyRefs;
use App\Modules\FamUnit\Domain\Models\GuardianStudentLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * REQ-FAM-UNIT/api.md §1.4. `revocation` solo se incluye si el sujeto tiene `revocacion_tutor.leer` (el controlador
 * deja el indicador en `FamilyRefs::showRevocation()`, memoizado por petición); si no, el campo se omite. Un sujeto con ámbito
 * `unidad_familiar` nunca recibe vínculos revocados (`RN-FAM-UNIT-36`, lo garantiza el resolutor). Enumerados sin
 * traducir (`ADR-038 §3.2`).
 *
 * @mixin GuardianStudentLink
 */
class GuardianStudentLinkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $refs = app(FamilyRefs::class);
        $withRevocation = $refs->showsRevocation();
        $student = $refs->person((int) $this->student_person_id);

        $data = [
            'public_id' => $this->public_id,
            'guardian' => $this->guardian?->public_id,
            'student_person' => $student?->publicId,
            'relationship' => $this->relationship,
            'is_legal_guardian' => (bool) $this->is_legal_guardian,
            'legal_guardian_verified_at' => $this->legal_guardian_verified_at,
            'access_level' => $this->access_level,
            'restricted_categories' => array_values($this->restricted_categories ?? []),
            'is_emergency_contact' => (bool) $this->is_emergency_contact,
            'status' => $this->status,
        ];

        if ($withRevocation) {
            $data['revocation'] = $this->status === 'revocado' ? [
                'reason' => $this->revocation_reason,
                'revoked_at' => $this->revoked_at,
                'revoked_by' => $this->revoked_by === null ? null : $refs->actorPublicId((int) $this->revoked_by),
            ] : null;
        }

        return $data + [
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
