<?php

namespace App\Modules\Core\Http\Controllers;

use App\Models\Role;
use App\Modules\Core\Domain\Events\RoleMfaRequirementChanged;
use App\Modules\Core\Http\Requests\PatchRoleRequest;
use App\Modules\Core\Http\Resources\RoleResource;
use App\Support\Api\ApiException;
use App\Support\Api\PagePaginatedResponse;
use App\Support\Api\ValidationErrorBag;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * api.md §5. Solo lectura en 1.1. REQ-AUTH/funcional.md §C.2, §C.16 (1.3)
 * añade `update()`, acotado a `mfa_required` (`RN-AUTH-70`) — el resto de
 * la escritura de roles y concesiones sigue siendo 1.5.
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
     * REQ-AUTH/funcional.md §C.2.2, §C.16, `RN-AUTH-70`, `CA-AUTH-135`.
     * En 1.3 el cuerpo admite **exactamente** `mfa_required`: cualquier
     * otra clave responde `422` sin cambiar nada (`ADR-038 §8`, semántica
     * de `PATCH`). En 1.5 este mismo método admite el resto de atributos
     * del editor de roles, sin cambiar de ruta ni de permiso (`§C.2.2`).
     */
    public function update(PatchRoleRequest $request, string $publicId): RoleResource
    {
        $role = Role::query()->where('public_id', $publicId)->first();

        if ($role === null) {
            throw ApiException::notFound();
        }

        $extraKeys = array_diff(array_keys($request->all()), ['mfa_required']);

        if ($extraKeys !== []) {
            $errors = new ValidationErrorBag;
            $errors->add('body', 'core.validation.role_patch_field_not_allowed', 'core.validation.role_patch_field_not_allowed');
            $errors->throwIfAny();
        }

        $newValue = $request->boolean('mfa_required');
        $wasRequired = (bool) $role->mfa_required;

        $role->mfa_required = $newValue;
        $role->save();

        // funcional.md §C.4.8: solo cuando la obligación EMPIEZA (false→true)
        // hace falta materializar de inmediato — apagarla no crea nada que
        // resolver aquí (MfaPolicy ya deja de exigirlo en la próxima
        // evaluación de cada usuario).
        if ($newValue && ! $wasRequired) {
            event(new RoleMfaRequirementChanged(app(TenantContext::class)->tenantId(), $role->public_id));
        }

        return new RoleResource($role->refresh());
    }
}
