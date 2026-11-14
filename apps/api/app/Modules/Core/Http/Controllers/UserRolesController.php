<?php

namespace App\Modules\Core\Http\Controllers;

use App\Models\User;
use App\Modules\Core\Application\ReplaceUserRoles;
use App\Modules\Core\Http\Resources\RoleResource;
use App\Support\Api\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

/**
 * api.md §5 (1.1), REQ-PERM/api.md §8 (1.5). `PUT`, no `POST`/`DELETE` por
 * rol: "este usuario tiene exactamente estos roles" es idempotente y evita
 * estados intermedios sin ningún rol (ADR-038 §9.1). La validación de
 * negocio (RPERM-013 con ámbitos, `asignacion_rol.eliminar` al retirar,
 * auditoría explícita) vive en `ReplaceUserRoles` desde 1.5.
 */
class UserRolesController extends Controller
{
    public function __construct(
        private readonly ReplaceUserRoles $replaceUserRoles,
    ) {}

    public function index(string $publicId): JsonResponse
    {
        $user = User::with('roles')->where('public_id', $publicId)->firstOrFail();

        return response()->json(['data' => RoleResource::collection($user->roles)->resolve()]);
    }

    public function replace(Request $request, string $publicId): JsonResponse
    {
        $request->validate([
            'role_ids' => ['present', 'array'],
            'role_ids.*' => ['string'],
        ]);

        $user = User::with('roles')->where('public_id', $publicId)->firstOrFail();
        $actor = Auth::user();

        if (! $actor instanceof User) {
            throw ApiException::unauthenticated();
        }

        $updated = $this->replaceUserRoles->execute($user, $request->input('role_ids', []), $actor);

        return response()->json(['data' => RoleResource::collection($updated->roles)->resolve()]);
    }
}
