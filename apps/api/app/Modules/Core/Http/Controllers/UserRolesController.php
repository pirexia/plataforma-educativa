<?php

namespace App\Modules\Core\Http\Controllers;

use App\Models\PermissionRole;
use App\Models\Role;
use App\Models\User;
use App\Modules\Core\Application\SchoolAdministratorGuard;
use App\Modules\Core\Domain\Events\UserRolesChanged;
use App\Modules\Core\Http\Resources\RoleResource;
use App\Support\Api\ApiException;
use App\Support\Api\ValidationErrorBag;
use App\Support\Authorization\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * api.md §5. `PUT`, no `POST`/`DELETE` por rol: "este usuario tiene
 * exactamente estos roles" es idempotente y evita estados intermedios
 * sin ningún rol (ADR-038 §9.1).
 */
class UserRolesController extends Controller
{
    public function __construct(
        private readonly SchoolAdministratorGuard $adminGuard,
        private readonly PermissionResolver $permissions,
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

        if ($actor instanceof User && $actor->is($user)) {
            throw ApiException::conflict('core.validation.cannot_modify_self');
        }

        $requestedIds = $request->input('role_ids', []);
        $roles = Role::query()->whereIn('public_id', $requestedIds)->get();

        if ($roles->count() !== count(array_unique($requestedIds))) {
            (new ValidationErrorBag)
                ->add('role_ids', 'core.validation.role_not_found', 'core.validation.role_not_found')
                ->throwIfAny();
        }

        $currentRoleIds = $user->roles->pluck('id');
        $newRoleIds = $roles->pluck('id');
        $addedRoles = $roles->whereNotIn('id', $currentRoleIds->all());

        if ($addedRoles->isNotEmpty() && $actor instanceof User) {
            $this->assertActorCanGrant($actor, $addedRoles);
        }

        $losesAdminCentro = $user->roles->contains('code', 'administrador_centro')
            && ! $roles->contains('code', 'administrador_centro');

        if ($losesAdminCentro && $this->adminGuard->wouldLeaveNoLivingAdministrator($user)) {
            throw ApiException::conflict('core.validation.last_school_administrator');
        }

        DB::transaction(function () use ($user, $newRoleIds): void {
            $user->roles()->sync($newRoleIds);
        });

        event(new UserRolesChanged($user->tenant_id, $user->public_id));

        return response()->json(['data' => RoleResource::collection($user->roles()->get())->resolve()]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Role>  $roles
     */
    private function assertActorCanGrant(User $actor, \Illuminate\Support\Collection $roles): void
    {
        $actorPermissions = $this->permissions->effectivePermissionCodes($actor);

        $grantedCodes = PermissionRole::query()
            ->whereIn('role_id', $roles->pluck('id'))
            ->where('effect', 'allow')
            ->pluck('permission_code')
            ->unique();

        if ($grantedCodes->diff($actorPermissions)->isNotEmpty()) {
            throw ApiException::forbidden();
        }
    }
}
