<?php

namespace App\Modules\Backoffice\Http\Resources;

use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Modules\Backoffice\Domain\PlatformRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * api.md §2.2. `password` nunca viaja (ADR-029): `PlatformAdmin::$hidden`
 * ya lo excluye, y este resource no lo lista de todos modos.
 *
 * @mixin PlatformAdmin
 */
class PlatformAdminResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'email' => $this->email,
            'name' => $this->name,
            'status' => $this->status->value,
            'locale' => $this->locale,
            'roles' => array_map(fn (PlatformRole $role) => $role->value, $this->roles()),
            'mfa_enrolled' => $this->hasMfaEnrolled(),
            'last_login_at' => $this->last_login_at,
            'created_at' => $this->created_at,
        ];
    }
}
