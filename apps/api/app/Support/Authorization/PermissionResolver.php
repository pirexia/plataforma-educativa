<?php

namespace App\Support\Authorization;

use App\Models\Permission;
use App\Models\PermissionRole;
use App\Models\User;
use App\Support\Modules\ModuleAvailability;
use Illuminate\Support\Collection;

/**
 * ADR-044 §4.1-§4.8, funcional.md §4: el motor de resolución multi-rol
 * completo de 1.5, sucesor del resolutor provisional de 1.1-1.4c (que leía
 * `effect` e ignoraba `scope`). Algoritmo exacto de `funcional.md §4.1`:
 *
 * 1. Roles vivos del sujeto.
 * 2. Veto por `deny`: una sola fila `deny` para el código, en cualquier rol
 *    y con cualquier ámbito, vacía el conjunto (RN-PERM-06). Un `deny`
 *    nunca se hace inerte (RN-PERM-07) — los cuatro filtros de inercia de
 *    abajo se aplican solo a `allow`.
 * 3. Concesiones `allow`: cada una pasa cuatro filtros de inercia
 *    (catálogo/retirado, módulo, categoría especial, resolutor). Si falla
 *    alguno, no aporta su ámbito.
 * 4. Unión de los ámbitos supervivientes.
 * 5. `todos` absorbe (RN-PERM-09).
 * 6. Conjunto vacío ⇒ denegado (RPERM-011, RN-PERM-08).
 *
 * Memoización por instancia (ADR-044 §4.7): registrado `scoped()`
 * (`AuthorizationServiceProvider`), una consulta de concesiones por sujeto
 * y toda la resolución de la petición se calcula sobre ese resultado en
 * memoria. Sin caché compartida — el modo de fallo de una caché mal
 * invalidada es conceder lo ya revocado, la única dirección que `INV-002`
 * no admite.
 */
final class PermissionResolver
{
    /** @var array<int, Collection<string, Collection<int, PermissionRole>>> grants agrupados por permission_code, por user id */
    private array $grantsByUser = [];

    /** @var Collection<string, Permission>|null catálogo completo, keyed por code */
    private ?Collection $catalog = null;

    public function __construct(
        private readonly ScopeResolverRegistry $scopeResolvers,
        private readonly ModuleAvailability $moduleAvailability,
    ) {}

    public function decide(User $subject, string $code): PermissionDecision
    {
        $permission = $this->catalog()->get($code);
        $grants = $this->grantsFor($subject)->get($code, collect());

        $sources = [];
        $scopes = [];
        $denied = false;

        foreach ($grants as $grant) {
            $scope = Scope::tryFrom($grant->scope);

            if ($grant->effect === 'deny') {
                $denied = true;
                $sources[] = new PermissionSource($grant->role, 'deny', $scope, false, null);

                continue;
            }

            [$inert, $reason] = $this->inertiaReason($permission, $grant, $scope);
            $sources[] = new PermissionSource($grant->role, 'allow', $scope, $inert, $reason);

            if (! $inert && $scope !== null) {
                $scopes[] = $scope;
            }
        }

        if ($denied) {
            return new PermissionDecision($code, false, [], $sources);
        }

        $uniqueScopes = array_values(array_unique($scopes, SORT_REGULAR));

        return new PermissionDecision($code, $uniqueScopes !== [], $uniqueScopes, $sources);
    }

    /**
     * `RPERM-009`, `GET /users/{id}/effective-permissions` y
     * `GET /me/effective-permissions` (api.md §7): la resolución completa
     * sobre el catálogo utilizable (no retirado), opcionalmente acotada por
     * `module_code`/`resource` (mismos filtros que `GET /permissions`).
     * Se calcula con el mismo `decide()` que autoriza cada endpoint real
     * (RN-PERM-22): no hay una segunda implementación de la resolución.
     *
     * @param  ?list<string>  $moduleCodes
     * @param  ?list<string>  $resources
     * @return Collection<string, PermissionDecision>
     */
    public function decideAll(User $subject, ?array $moduleCodes = null, ?array $resources = null): Collection
    {
        return $this->catalog()
            ->whereNull('retired_at')
            ->when($moduleCodes !== null, fn (Collection $c) => $c->whereIn('module_code', $moduleCodes))
            ->when($resources !== null, fn (Collection $c) => $c->whereIn('resource', $resources))
            ->keys()
            ->mapWithKeys(fn (string $code) => [$code => $this->decide($subject, $code)]);
    }

    public function can(User $subject, string $code): bool
    {
        return $this->decide($subject, $code)->permitted;
    }

    /**
     * Compatibilidad: lista simple de códigos permitidos (sin ámbitos ni
     * procedencia), consumida por `GET /me` (`UserProfilePresenter`).
     *
     * @return list<string>
     */
    public function effectivePermissionCodes(User $subject): array
    {
        return $this->decideAll($subject)
            ->filter(fn (PermissionDecision $decision) => $decision->permitted)
            ->keys()
            ->values()
            ->all();
    }

    /**
     * `RPERM-013`/`ADR-044 §4.8`: ¿el sujeto posee, de forma efectiva, el
     * ámbito indicado para este código? `todos` absorbe cualquier ámbito.
     * Es la comprobación atómica de «nadie concede lo que no tiene».
     */
    public function ownsScope(User $subject, string $code, Scope $scope): bool
    {
        $decision = $this->decide($subject, $code);

        return $decision->permitted && $decision->hasScope($scope);
    }

    /**
     * @return array{0: bool, 1: ?string}
     */
    private function inertiaReason(?Permission $permission, PermissionRole $grant, ?Scope $scope): array
    {
        if ($permission === null || $permission->retired_at !== null) {
            return [true, 'inerte_permiso_retirado'];
        }

        if (! $this->moduleAvailability->isEnabled($permission->module_code)) {
            return [true, 'inerte_modulo'];
        }

        if ($permission->is_special_category && ! (bool) $grant->role->special_data_access) {
            return [true, 'inerte_datos_especiales'];
        }

        if ($scope !== Scope::Todos && (! $scope instanceof Scope || ! $this->scopeResolvers->has($permission->resource, $scope))) {
            return [true, 'inerte_sin_resolutor'];
        }

        return [false, null];
    }

    /**
     * @return Collection<string, Collection<int, PermissionRole>>
     */
    private function grantsFor(User $subject): Collection
    {
        return $this->grantsByUser[$subject->id] ??= PermissionRole::query()
            ->whereIn('role_id', $subject->roles()->pluck('roles.id'))
            ->with('role')
            ->get()
            ->groupBy('permission_code');
    }

    /**
     * @return Collection<string, Permission>
     */
    private function catalog(): Collection
    {
        return $this->catalog ??= Permission::all()->keyBy('code');
    }
}
