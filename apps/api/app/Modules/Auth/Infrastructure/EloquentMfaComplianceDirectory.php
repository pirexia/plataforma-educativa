<?php

namespace App\Modules\Auth\Infrastructure;

use App\Models\Role;
use App\Models\User;
use App\Modules\Auth\Domain\MfaComplianceDirectory;
use App\Modules\Auth\Domain\MfaComplianceSummary;
use App\Modules\Auth\Domain\MfaObligationState;
use App\Modules\Auth\Domain\MfaPolicy;

/**
 * funcional.md §C.1.1 punto 9, `CA-AUTH-136`. Recorre los usuarios del rol
 * y delega en `MfaPolicy` (RN-AUTH-62): ninguna otra parte del sistema
 * decide quién está obligado, tampoco esta consulta administrativa.
 *
 * Sin caché ni consulta agregada en SQL a propósito: el número de personas
 * de un rol de un centro (decenas, a lo sumo unos pocos miles) hace que un
 * bucle sobre `MfaPolicy` sea legible y correcto antes que una proyección
 * SQL que duplicaría RN-AUTH-62 con sus propias reglas.
 */
final class EloquentMfaComplianceDirectory implements MfaComplianceDirectory
{
    public function __construct(
        private readonly MfaPolicy $policy,
    ) {}

    public function current(Role $role): MfaComplianceSummary
    {
        $users = $role->users()->with('person')->get();

        $enrolled = 0;
        $obligated = 0;
        $inGrace = 0;
        $enforced = 0;
        $exempt = 0;

        foreach ($users as $user) {
            /** @var User $user */
            if ($this->policy->hasUsableFactor($user)) {
                $enrolled++;

                continue;
            }

            if ($this->policy->hasLiveExemption($user)) {
                $exempt++;

                continue;
            }

            $obligation = $this->policy->resolve($user);

            if ($obligation->state === MfaObligationState::EnGracia) {
                $inGrace++;
            } elseif ($obligation->state === MfaObligationState::Exigible) {
                $enforced++;
            }

            if ($obligation->isObligated()) {
                $obligated++;
            }
        }

        return new MfaComplianceSummary(
            rolePublicId: $role->public_id,
            roleCode: $role->code,
            mfaRequired: (bool) $role->mfa_required,
            preview: false,
            usersTotal: $users->count(),
            usersEnrolled: $enrolled,
            usersObligated: $obligated,
            usersInGrace: $inGrace,
            usersEnforced: $enforced,
            usersExempt: $exempt,
        );
    }

    /**
     * `$hypotheticalMfaRequired = false`: apagar la obligación de un rol
     * nunca obliga a nadie por sí solo (un usuario puede seguir obligado
     * por otro rol, pero eso no lo decide **este** rol) — 0 sin consultar
     * nada más.
     */
    public function preview(Role $role, bool $hypotheticalMfaRequired): MfaComplianceSummary
    {
        $users = $role->users()->with('person')->get();

        if (! $hypotheticalMfaRequired) {
            return new MfaComplianceSummary(
                rolePublicId: $role->public_id,
                roleCode: $role->code,
                mfaRequired: false,
                preview: true,
                usersTotal: $users->count(),
                usersEnrolled: 0,
                usersObligated: 0,
                usersInGrace: 0,
                usersEnforced: 0,
                usersExempt: 0,
            );
        }

        $enrolled = 0;
        $wouldBeObligated = 0;
        $exempt = 0;

        foreach ($users as $user) {
            /** @var User $user */
            if ($this->policy->hasUsableFactor($user)) {
                $enrolled++;

                continue;
            }

            // funcional.md §C.4.7 punto 1: una excepción viva exime
            // también en la hipótesis — es del usuario, no del rol.
            if ($this->policy->hasLiveExemption($user)) {
                $exempt++;

                continue;
            }

            $wouldBeObligated++;
        }

        return new MfaComplianceSummary(
            rolePublicId: $role->public_id,
            roleCode: $role->code,
            mfaRequired: true,
            preview: true,
            usersTotal: $users->count(),
            usersEnrolled: $enrolled,
            usersObligated: $wouldBeObligated,
            usersInGrace: 0,
            usersEnforced: 0,
            usersExempt: $exempt,
        );
    }
}
