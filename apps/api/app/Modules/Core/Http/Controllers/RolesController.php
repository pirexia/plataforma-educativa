<?php

namespace App\Modules\Core\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Modules\Core\Application\CreateRole;
use App\Modules\Core\Application\DeleteRole;
use App\Modules\Core\Application\PatchRole;
use App\Modules\Core\Application\ReplaceRolePermissions;
use App\Modules\Core\Http\Requests\PatchRoleRequest;
use App\Modules\Core\Http\Requests\ReplaceRolePermissionsRequest;
use App\Modules\Core\Http\Requests\StoreRoleRequest;
use App\Modules\Core\Http\Resources\RoleResource;
use App\Support\Api\ApiException;
use App\Support\Api\PagePaginatedResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

/**
 * api.md §3-§6. Alta y clonación (`RPERM-005`/`006`), editor completo
 * (`PATCH`, sobre la misma ruta y el mismo permiso desde 1.3), concesión y
 * revocación de permisos, y baja de rol — los cuatro nuevos/ampliados de
 * `REQ-PERM` (1.5) sobre `App\Modules\Core`, donde ya vivían `index()` y
 * `show()` desde 1.1.
 */
class RolesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $paginator = Role::query()->withCount('users')->orderBy('code')
            ->paginate($request->integer('per_page', 25))->withQueryString();

        return PagePaginatedResponse::make($paginator, RoleResource::class);
    }

    public function show(string $publicId): RoleResource
    {
        $role = Role::with('permissionGrants.permission')
            ->where('public_id', $publicId)
            ->first();

        if ($role === null) {
            throw ApiException::notFound();
        }

        return new RoleResource($role);
    }

    /**
     * `RPERM-005`/`RPERM-006`, api.md §3. `clone_from` decide si es alta o
     * clonación; ambas comparten permiso (`rol.crear`) y validación
     * (`CreateRole`).
     */
    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = app(CreateRole::class)->create($request->validated(), $this->actor());
        $role->load('permissionGrants.permission');

        return (new RoleResource($role))->response()
            ->setStatusCode(201)
            ->header('Location', route('core.roles.show', $role->public_id));
    }

    /**
     * REQ-AUTH/funcional.md §C.2.2, §C.16, `RN-AUTH-70`, `CA-AUTH-135`
     * (1.3): acotado a `mfa_required`. REQ-PERM/api.md §4 (1.5): mismo
     * método, mismo permiso base, editor completo delegado en `PatchRole`.
     */
    public function update(PatchRoleRequest $request, string $publicId): RoleResource
    {
        $role = Role::query()->where('public_id', $publicId)->first();

        if ($role === null) {
            throw ApiException::notFound();
        }

        $role = app(PatchRole::class)->apply($role, $request->all(), $this->actor());

        return new RoleResource($role);
    }

    /**
     * api.md §6, `funcional.md §7.9` (`RN-PERM-16`, `RN-PERM-17`).
     */
    public function destroy(string $publicId): Response
    {
        $role = Role::query()->where('public_id', $publicId)->first();

        if ($role === null) {
            throw ApiException::notFound();
        }

        app(DeleteRole::class)->delete($role);

        return response()->noContent();
    }

    /**
     * api.md §5, `PUT /roles/{public_id}/permissions`. Reemplazo completo
     * del conjunto de concesiones (`ADR-038 §9.1`). El orden de validación
     * exacto — forma antes que autorización, existencia del rol la última
     * — vive en `ReplaceRolePermissions` (api.md §5.1).
     */
    public function replacePermissions(ReplaceRolePermissionsRequest $request, string $publicId): RoleResource
    {
        $role = Role::query()->where('public_id', $publicId)->first();

        $updated = app(ReplaceRolePermissions::class)->execute(
            $role,
            $request->validated('permissions'),
            $this->actor(),
        );

        return new RoleResource($updated);
    }

    private function actor(): User
    {
        $actor = Auth::user();

        if (! $actor instanceof User) {
            throw ApiException::unauthenticated();
        }

        return $actor;
    }
}
