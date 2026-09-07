<?php

namespace App\Modules\Core\Application;

use App\Models\Permission;
use App\Models\User;
use App\Support\Authorization\PermissionDecision;
use App\Support\Authorization\PermissionResolver;
use App\Support\Authorization\PermissionSource;
use App\Support\Authorization\Scope;

/**
 * REQ-PERM/funcional.md §7.10/§7.11, api.md §7 (`RPERM-009`). Un solo
 * cálculo, compartido por `GET /users/{id}/effective-permissions`
 * (administración) y `GET /me/effective-permissions` (autoservicio) —
 * `RN-PERM-22`: la vista previa se calcula con el mismo código que la
 * aplicación real, verificable por lectura, no una segunda implementación
 * de la resolución.
 */
final class ComputeEffectivePermissions
{
    public function __construct(
        private readonly PermissionResolver $permissions,
    ) {}

    /**
     * @param  ?list<string>  $moduleCodes
     * @param  ?list<string>  $resources
     * @return list<array<string, mixed>>
     */
    public function rows(User $subject, ?array $moduleCodes, ?array $resources, bool $includeInert): array
    {
        $decisions = $this->permissions->decideAll($subject, $moduleCodes, $resources);
        $catalog = Permission::query()->whereIn('code', $decisions->keys())->get()->keyBy('code');

        return $decisions->map(function (PermissionDecision $decision, string $code) use ($catalog, $includeInert) {
            $permission = $catalog->get($code);

            return [
                'code' => $code,
                'resource' => $permission?->resource,
                'action' => $permission?->action,
                'module_code' => $permission?->module_code,
                'is_special_category' => (bool) $permission?->is_special_category,
                'decision' => $decision->permitted ? 'permitido' : 'denegado',
                'scopes' => array_map(fn (Scope $scope): string => $scope->value, $decision->scopes),
                'unrestricted' => $decision->isUnrestricted(),
                'sources' => $this->sources($decision, $includeInert),
            ];
        })->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sources(PermissionDecision $decision, bool $includeInert): array
    {
        $sources = $decision->sources;

        if (! $includeInert) {
            $sources = array_values(array_filter($sources, fn (PermissionSource $source) => ! $source->inert));
        }

        return array_map(fn (PermissionSource $source): array => [
            'role' => [
                'public_id' => $source->role->public_id,
                'code' => $source->role->code,
                'name' => $source->role->name ?? __($source->role->name_key),
            ],
            'effect' => $source->effect,
            'scope' => $source->scope?->value,
            'inert' => $source->inert,
            'inert_reason' => $source->inertReason,
        ], $sources);
    }
}
