<?php

namespace App\Modules\FamUnit\Http\Resources;

use App\Modules\FamUnit\Application\FamilyRefs;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * REQ-FAM-UNIT/api.md §1.5: el estado vigente de un alumno en un nivel. **Representación calculada** (`ADR-066 §5.2`):
 * no hay tabla ni `public_id`; se identifica por `(student_person, level)`. El recurso envuelve una fila de
 * `ImageConsentService` más el `public_id` de su unidad (`family_unit`, `null` si el alumno no pertenece a ninguna
 * unidad viva).
 *
 * @property array{student_person_id: int, level: string, state: string, last_decision_at: ?CarbonImmutable, family_unit?: ?string} $resource
 */
class ImageConsentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $person = app(FamilyRefs::class)->person($this->resource['student_person_id']);

        return [
            'student_person' => $person?->publicId,
            'display_name' => $person === null || $person->removed ? '' : $person->displayName,
            'family_unit' => $this->resource['family_unit'] ?? null,
            'level' => $this->resource['level'],
            'state' => $this->resource['state'],
            'last_decision_at' => $this->resource['last_decision_at'],
        ];
    }
}
