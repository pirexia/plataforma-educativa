<?php

namespace App\Modules\Prl\Http\Resources;

use App\Modules\FamUnit\Domain\StudentMatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * REQ-PRL/api.md §8.4, `GET /door/students`: un alumno del buscador de conserjería. El alumno se nombra por el `public_id` de su
 * persona (`OPEN-FAM-UNIT-01`); `family_unit_code` es el código de su unidad familiar, o `null` si solo tiene vínculos.
 *
 * @mixin StudentMatch
 */
class DoorStudentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var StudentMatch $student */
        $student = $this->resource;

        return [
            'student_person' => $student->studentPublicId,
            'display_name' => $student->displayName,
            'family_unit_code' => $student->familyUnitCode,
        ];
    }
}
