<?php

namespace App\Modules\Core\Http\Controllers;

use App\Models\Role;
use App\Modules\Core\Http\Resources\RoleResource;
use App\Support\Api\ApiException;
use App\Support\Api\PagePaginatedResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * api.md §5. Solo lectura en 1.1 — la escritura de roles y concesiones
 * es 1.5 (funcional.md §1.3).
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
}
