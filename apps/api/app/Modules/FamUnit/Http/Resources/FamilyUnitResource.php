<?php

namespace App\Modules\FamUnit\Http\Resources;

use App\Modules\FamUnit\Domain\Models\FamilyUnit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * REQ-FAM-UNIT/api.md §1.1. Los recuentos son atributos efímeros que pone `FamilyUnitService::attachCounts()`; para
 * un sujeto con ámbito `unidad_familiar` cuentan solo lo que alcanza (`RN-FAM-UNIT-36`). `deleted_at` solo aparece
 * en una unidad dada de baja (listado con `include_deleted`).
 *
 * @mixin FamilyUnit
 */
class FamilyUnitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'code' => $this->code,
            'principal_guardian' => $this->principalGuardian?->public_id,
            'guardians_count' => (int) $this->getAttribute('guardians_count'),
            'students_count' => (int) $this->getAttribute('students_count'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->when($this->trashed(), fn () => $this->deleted_at),
        ];
    }
}
