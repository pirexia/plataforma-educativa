<?php

namespace App\Modules\FamUnit\Http\Resources;

use App\Modules\FamUnit\Application\FamilyRefs;
use App\Modules\FamUnit\Domain\Models\Guardian;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * REQ-FAM-UNIT/api.md §1.2. **Nunca** correo, teléfono ni documento: son de `Core` y se consultan allí con su permiso
 * (`OPEN-FAM-UNIT-20`). `display_name` va vacío si el usuario está dado de baja (`OPEN-FAM-UNIT-16`).
 *
 * @mixin Guardian
 */
class GuardianResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $refs = app(FamilyRefs::class);
        $user = $refs->user((int) $this->person_id);

        return [
            'public_id' => $this->public_id,
            'family_unit' => $this->familyUnit?->public_id,
            'user' => $user?->userPublicId,
            'display_name' => $user === null ? '' : $user->displayName,
            'is_principal' => $this->familyUnit !== null && (int) $this->familyUnit->principal_guardian_id === (int) $this->id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
