<?php

namespace App\Modules\Core\Application;

use App\Models\Role;
use App\Models\User;
use App\Modules\Core\Domain\Events\RoleMfaRequirementChanged;
use App\Support\Api\ApiException;
use App\Support\Api\ValidationErrorBag;
use App\Support\Authorization\PermissionResolver;
use App\Support\Tenancy\TenantContext;

/**
 * REQ-PERM/api.md §4, funcional.md §7.6 (`PATCH /roles/{public_id}`,
 * `ADR-044 §4.10`). La misma ruta y el mismo permiso base (`rol.actualizar`)
 * que 1.3 dejó acotados a `mfa_required` — 1.5 abre `name` y
 * `special_data_access`. `code` no es editable: es la referencia estable
 * del rol.
 */
final class PatchRole
{
    /** @var list<string> */
    private const ALLOWED_KEYS = ['name', 'mfa_required', 'special_data_access'];

    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly SpecialDataAccessGuard $specialDataAccessGuard,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public function apply(Role $role, array $body, User $actor): Role
    {
        $extraKeys = array_diff(array_keys($body), self::ALLOWED_KEYS);

        if (in_array('code', $extraKeys, true)) {
            (new ValidationErrorBag)
                ->add('code', 'core.validation.role_code_immutable', 'core.validation.role_code_immutable')
                ->throwIfAny();
        }

        if ($extraKeys !== []) {
            (new ValidationErrorBag)
                ->add('body', 'core.validation.role_patch_field_not_allowed', 'core.validation.role_patch_field_not_allowed')
                ->throwIfAny();
        }

        if (array_key_exists('name', $body)) {
            if ($role->is_system) {
                (new ValidationErrorBag)
                    ->add('name', 'core.validation.role_name_system', 'core.validation.role_name_system')
                    ->throwIfAny();
            }

            $role->name = $body['name'];
        }

        if (array_key_exists('special_data_access', $body)) {
            $this->applySpecialDataAccess($role, (bool) $body['special_data_access'], $actor);
        }

        $wasMfaRequired = (bool) $role->mfa_required;

        if (array_key_exists('mfa_required', $body)) {
            $role->mfa_required = (bool) $body['mfa_required'];
        }

        $role->save();

        // funcional.md §C.4.8 (1.3, sin cambios de semántica en 1.5): solo
        // cuando la obligación EMPIEZA (false→true) hace falta
        // materializar de inmediato.
        if ($role->mfa_required && ! $wasMfaRequired) {
            event(new RoleMfaRequirementChanged($this->tenantContext->tenantId(), $role->public_id));
        }

        return $role->refresh();
    }

    /**
     * `special_data_access` no se cambia con `rol.actualizar` a secas
     * (`funcional.md §5.2`): exige `rol_datos_especiales.actualizar`
     * siempre que se envía la clave, y además la posesión del atributo
     * (`RPERM-013` sobre el atributo, §5.3) cuando lo que se pide es
     * **activarlo**. Desactivarlo no exige poseerlo — no es "activar".
     */
    private function applySpecialDataAccess(Role $role, bool $wantsEnabled, User $actor): void
    {
        if (! $this->permissions->can($actor, 'rol_datos_especiales.actualizar')) {
            throw ApiException::forbidden();
        }

        if ($wantsEnabled && ! $this->specialDataAccessGuard->holds($actor)) {
            throw ApiException::forbidden('core.authorization.special_data_access_not_held');
        }

        $role->special_data_access = $wantsEnabled;
    }
}
