<?php

namespace App\Modules\Acad\Http\Resources;

use App\Modules\Acad\Domain\Models\Facility;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * REQ-ACAD/api.md §12.1. Un espacio es del centro: sin `academic_year`.
 *
 * @mixin Facility
 */
class FacilityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'facility_type' => $this->facility_type,
            'code' => $this->code,
            'name' => $this->name,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
