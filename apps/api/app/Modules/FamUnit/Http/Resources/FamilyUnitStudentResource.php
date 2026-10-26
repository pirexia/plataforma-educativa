<?php

namespace App\Modules\FamUnit\Http\Resources;

use App\Modules\FamUnit\Application\FamilyRefs;
use App\Modules\FamUnit\Domain\Models\FamilyUnitStudent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * REQ-FAM-UNIT/api.md §1.3. El alumno se nombra por el `public_id` de su **persona** (`OPEN-FAM-UNIT-01`).
 *
 * @mixin FamilyUnitStudent
 */
class FamilyUnitStudentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $person = app(FamilyRefs::class)->person((int) $this->student_person_id);

        return [
            'public_id' => $this->public_id,
            'family_unit' => $this->familyUnit?->public_id,
            'student_person' => $person?->publicId,
            'display_name' => $person?->displayName ?? '',
            'created_at' => $this->created_at,
        ];
    }
}
