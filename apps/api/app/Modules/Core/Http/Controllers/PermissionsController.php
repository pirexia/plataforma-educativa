<?php

namespace App\Modules\Core\Http\Controllers;

use App\Models\Permission;
use App\Support\Api\Rules\QueryBoolean;
use App\Support\Authorization\Scope;
use App\Support\Authorization\ScopeResolverRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * api.md §5 (1.1), REQ-PERM/api.md §2.1 (1.5). Catálogo de plataforma
 * (sin tenant_id, sin paginación por página — tabla de referencia
 * pequeña, la matriz de permisos la necesita entera).
 *
 * `applicable_scopes`/`grantable_scopes`: la diferencia entre los dos es
 * exactamente «este ámbito existe pero su módulo todavía no ha llegado» —
 * permite a 1.5b mostrar un ámbito en gris con explicación en vez de
 * ofrecerlo y devolver 422.
 */
class PermissionsController extends Controller
{
    public function __construct(
        private readonly ScopeResolverRegistry $scopeResolvers,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'module_code' => ['sometimes', 'string'],
            'resource' => ['sometimes', 'string'],
            'include_retired' => ['sometimes', new QueryBoolean],
        ]);

        $query = Permission::query()->orderBy('code');

        if (! $request->boolean('include_retired')) {
            $query->whereNull('retired_at');
        }

        if ($request->filled('module_code')) {
            $query->where('module_code', $request->string('module_code')->value());
        }

        if ($request->filled('resource')) {
            $query->where('resource', $request->string('resource')->value());
        }

        $permissions = $query->get(['code', 'resource', 'action', 'module_code', 'is_special_category', 'applicable_scopes', 'retired_at']);

        return response()->json([
            'data' => $permissions->map(function (Permission $permission): array {
                $applicable = $permission->applicableScopes();
                $grantable = array_values(array_filter(
                    $applicable,
                    fn (Scope $scope) => $this->scopeResolvers->has($permission->resource, $scope),
                ));

                return [
                    'code' => $permission->code,
                    'resource' => $permission->resource,
                    'action' => $permission->action,
                    'module_code' => $permission->module_code,
                    'is_special_category' => $permission->is_special_category,
                    'applicable_scopes' => array_map(fn (Scope $scope) => $scope->value, $applicable),
                    'grantable_scopes' => array_map(fn (Scope $scope) => $scope->value, $grantable),
                    'retired_at' => $permission->retired_at?->toJSON(),
                ];
            })->all(),
        ]);
    }
}
