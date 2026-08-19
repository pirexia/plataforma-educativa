<?php

namespace App\Modules\Core\Http\Resources;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * api.md §5, `GET /roles`. `GET /roles/{id}` añade `permissions` (ver
 * `RoleController::show()`).
 *
 * @mixin Role
 */
class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'public_id' => $this->public_id,
            'code' => $this->code,
            'name' => $this->name ?? __($this->name_key),
            'is_system' => $this->is_system,
            'mfa_required' => $this->mfa_required,
            'special_data_access' => $this->special_data_access,
            'users_count' => $this->whenCounted('users'),
        ];

        if ($this->relationLoaded('permissionGrants')) {
            $data['permissions'] = $this->permissionGrants->map(fn ($grant) => [
                'code' => $grant->permission_code,
                'resource' => $grant->permission?->resource,
                'action' => $grant->permission?->action,
                'effect' => $grant->effect,
                'scope' => $grant->scope,
            ])->all();
        }

        return $data;
    }
}
