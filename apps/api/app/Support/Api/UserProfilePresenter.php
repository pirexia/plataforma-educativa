<?php

namespace App\Support\Api;

use App\Models\User;
use App\Modules\Auth\Domain\MfaObligationState;
use App\Modules\Auth\Domain\MfaPolicy;
use App\Support\Authorization\PermissionResolver;

/**
 * REQ-CORE/api.md §3 (`GET /me`) y REQ-AUTH/api.md §2 (`POST /auth/session`)
 * devuelven **el mismo recurso** (REQ-AUTH/funcional.md §4.2, punto 6.6),
 * para que la SPA no tenga que encadenar una segunda petición tras el
 * login. Extraído a `App\Support` — ni `MeController` (`REQ-CORE`) ni el
 * controlador de sesión (`REQ-AUTH`) importan código interno del otro
 * módulo (`INV-007`): ambos ensamblan el mismo resultado a partir de
 * piezas ya compartidas (`User`, `PermissionResolver`, y desde 1.3
 * `MfaPolicy`, la interfaz que `REQ-AUTH` expone para esto exacto —
 * funcional.md §C.9.2).
 */
final class UserProfilePresenter
{
    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly MfaPolicy $mfaPolicy,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(User $user): array
    {
        $user->loadMissing(['person', 'roles']);

        return [
            'public_id' => $user->public_id,
            'email' => $user->email,
            'status' => $user->status->value,
            'person' => [
                'public_id' => $user->person->public_id,
                'given_name' => $user->person->given_name,
                'family_name_1' => $user->person->family_name_1,
                'family_name_2' => $user->person->family_name_2,
                'contact_email' => $user->person->contact_email,
                'contact_phone' => $user->person->contact_phone,
                'locale' => $user->person->locale,
            ],
            'roles' => $user->roles->map(fn ($role) => [
                'public_id' => $role->public_id,
                'code' => $role->code,
                'name' => $role->name ?? __($role->name_key),
            ])->all(),
            'permissions' => $this->permissions->effectivePermissionCodes($user),
            'mfa' => $this->mfaBlock($user),
        ];
    }

    /**
     * REQ-AUTH/api.md §C.6, funcional.md §C.4.8: "avisos en cada acceso"
     * sin endpoint nuevo y sin correo. Ampliación aditiva del recurso
     * (`ADR-038 §7.3`): un cliente escrito contra 1.2 ignora esta clave.
     *
     * @return array<string, mixed>
     */
    private function mfaBlock(User $user): array
    {
        $obligation = $this->mfaPolicy->resolve($user);

        $daysRemaining = $obligation->state === MfaObligationState::EnGracia && $obligation->graceDeadlineAt !== null
            ? max(0, (int) now()->diffInDays($obligation->graceDeadlineAt))
            : null;

        return [
            'enrolled' => $this->mfaPolicy->hasUsableFactor($user),
            'obligated' => $obligation->isObligated(),
            'enforced' => $obligation->isEnforced(),
            'grace_deadline_at' => $obligation->graceDeadlineAt?->toISOString(),
            'days_remaining' => $daysRemaining,
        ];
    }
}
