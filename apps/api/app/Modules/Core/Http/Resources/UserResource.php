<?php

namespace App\Modules\Core\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * api.md §3, `GET /users`. Recurso desnudo (ADR-038 §3.1). `roles[].name`
 * se resuelve en servidor: literal si el rol es personalizado,
 * traducción de `name_key` si es del sistema (ADR-034 §2, INV-009).
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'email' => $this->email,
            'status' => $this->status->value,
            'person' => [
                'public_id' => $this->person->public_id,
                'given_name' => $this->person->given_name,
                'family_name_1' => $this->person->family_name_1,
                'family_name_2' => $this->person->family_name_2,
                'contact_email' => $this->person->contact_email,
                'contact_phone' => $this->person->contact_phone,
                'document_type' => $this->person->document_type,
                'document_number' => $this->person->document_number,
                'birth_date' => $this->person->birth_date?->format('Y-m-d'),
                'locale' => $this->person->locale,
            ],
            'roles' => $this->roles->map(fn ($role) => [
                'public_id' => $role->public_id,
                'code' => $role->code,
                'name' => $role->name ?? __($role->name_key),
            ])->all(),
            'email_verified_at' => $this->email_verified_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
        ];
    }
}
