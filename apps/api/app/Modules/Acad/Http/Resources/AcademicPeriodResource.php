<?php

namespace App\Modules\Acad\Http\Resources;

use App\Modules\Acad\Domain\Models\AcademicPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * REQ-ACAD/api.md §11.1. `period_type` viaja sin traducir (`ADR-038 §3.2`);
 * las fechas, `AAAA-MM-DD`; `name` resuelto y de solo lectura (`ADR-062 §5.4`).
 * Ni `id` ni `*_id` internos, ni autoría, ni `deleted_at`.
 *
 * @mixin AcademicPeriod
 */
class AcademicPeriodResource extends JsonResource
{
    use ResolvesStructure;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'academic_year' => $this->academicYearPublicId($this->academic_year_id),
            'education_level' => $this->educationLevel->public_id,
            'period_type' => $this->period_type,
            'position' => $this->position,
            'name' => $this->resolvedName($this->name_translations),
            'name_translations' => $this->name_translations,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
