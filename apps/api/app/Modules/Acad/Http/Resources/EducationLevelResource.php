<?php

namespace App\Modules\Acad\Http\Resources;

use App\Modules\Acad\Domain\Models\EducationLevel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * REQ-ACAD/api.md §1.1.
 *
 * @mixin EducationLevel
 */
class EducationLevelResource extends JsonResource
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
            'code' => $this->code,
            'name' => $this->resolvedName($this->name_translations),
            'name_translations' => $this->name_translations,
            'stage' => $this->stage,
            'regime' => $this->regime,
            'position' => $this->position,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
