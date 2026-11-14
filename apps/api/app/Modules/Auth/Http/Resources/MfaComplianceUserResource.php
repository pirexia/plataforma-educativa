<?php

namespace App\Modules\Auth\Http\Resources;

use App\Modules\Auth\Domain\MfaComplianceUserRow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * api.md §C.5, `GET /mfa-compliance/users`. `user` lleva solo campos
 * públicos (nombre, correo) — nunca secretos, hashes ni recuento de
 * códigos de respaldo restantes (`permisos.md §C.6.1`).
 *
 * @mixin MfaComplianceUserRow
 */
class MfaComplianceUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MfaComplianceUserRow $row */
        $row = $this->resource;

        return [
            'user' => [
                'public_id' => $row->userPublicId,
                'given_name' => $row->givenName,
                'family_name_1' => $row->familyName1,
                'family_name_2' => $row->familyName2,
                'email' => $row->email,
            ],
            'state' => $row->state,
            'grace_deadline_at' => $row->graceDeadlineAt,
            'enrolled_methods' => $row->enrolledMethods,
            'required_by_roles' => $row->requiredByRoles,
        ];
    }
}
