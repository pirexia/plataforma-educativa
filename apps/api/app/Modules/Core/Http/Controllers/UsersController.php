<?php

namespace App\Modules\Core\Http\Controllers;

use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Core\Application\CreateUser;
use App\Modules\Core\Application\SchoolAdministratorGuard;
use App\Modules\Core\Application\UpdateUser;
use App\Modules\Core\Domain\Events\UserDeactivated;
use App\Modules\Core\Domain\Events\UserRestored;
use App\Modules\Core\Http\Requests\IndexUsersRequest;
use App\Modules\Core\Http\Requests\StoreUserRequest;
use App\Modules\Core\Http\Requests\UpdateUserRequest;
use App\Modules\Core\Http\Resources\UserResource;
use App\Support\Api\ApiException;
use App\Support\Api\PagePaginatedResponse;
use App\Support\Authorization\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

/**
 * api.md §3 (`REQ-CORE-003`). `RN-CORE-06`: nadie se da de baja ni se
 * cambia el estado a sí mismo por esta API. `RN-CORE-07`: siempre al
 * menos un `administrador_centro` vivo/activo.
 */
class UsersController extends Controller
{
    public function __construct(
        private readonly SchoolAdministratorGuard $adminGuard,
        private readonly PermissionResolver $permissions,
    ) {}

    public function index(IndexUsersRequest $request): JsonResponse
    {
        $includeDeleted = $request->boolean('include_deleted');
        $actor = Auth::user();

        // permisos.md §2: include_deleted=true exige además usuario.eliminar,
        // no solo usuario.leer (ya exigido por la ruta).
        if ($includeDeleted && (! $actor instanceof User || ! $this->permissions->can($actor, 'usuario.eliminar'))) {
            throw ApiException::forbidden();
        }

        $query = User::query()->with(['person', 'roles']);

        if ($includeDeleted) {
            $query->withTrashed();
        }

        if ($request->filled('q')) {
            $needle = '%'.$request->string('q')->value().'%';
            $query->where(function ($q) use ($needle): void {
                $q->where('email', 'ilike', $needle)
                    ->orWhereHas('person', fn ($p) => $p->where('given_name', 'ilike', $needle)
                        ->orWhere('family_name_1', 'ilike', $needle)
                        ->orWhere('family_name_2', 'ilike', $needle));
            });
        }

        if ($request->filled('status')) {
            $query->whereIn('status', explode(',', (string) $request->string('status')));
        }

        if ($request->filled('role')) {
            $query->whereHas('roles', fn ($q) => $q->whereIn('public_id', explode(',', (string) $request->string('role'))));
        }

        if ($request->filled('locale')) {
            $query->whereHas('person', fn ($p) => $p->where('locale', $request->string('locale')->value()));
        }

        $sort = (string) $request->string('sort', 'family_name_1');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        if ($column === 'email') {
            $query->orderBy('email', $direction);
        } elseif ($column === 'created_at') {
            $query->orderBy('created_at', $direction);
        } else {
            $query->join('people', 'people.id', '=', 'users.person_id')
                ->orderBy('people.family_name_1', $direction)
                ->select('users.*');
        }

        $paginator = $query->paginate($request->integer('per_page', 25))->withQueryString();

        return PagePaginatedResponse::make($paginator, UserResource::class);
    }

    public function store(StoreUserRequest $request, CreateUser $createUser): JsonResponse
    {
        $result = $createUser->create($request->validated(), $request->user());

        $body = (new UserResource($result['user']))->resolve();

        if ($result['invitation'] !== null) {
            $body['invitation'] = [
                'public_id' => $result['invitation']->public_id,
                'expires_at' => $result['invitation']->expires_at,
            ];
        }

        return response()->json($body, 201);
    }

    public function show(string $publicId): UserResource
    {
        return new UserResource($this->findOrFail($publicId));
    }

    public function update(UpdateUserRequest $request, string $publicId, UpdateUser $updateUser): UserResource
    {
        $user = $this->findOrFail($publicId);

        return new UserResource($updateUser->update($user, $request->validated()));
    }

    public function destroy(string $publicId): JsonResponse
    {
        $user = $this->findOrFail($publicId);
        $actor = Auth::user();

        if ($actor instanceof User && $actor->is($user)) {
            throw ApiException::conflict('core.validation.cannot_modify_self');
        }

        if ($this->adminGuard->wouldLeaveNoLivingAdministrator($user) && $user->roles->contains('code', 'administrador_centro')) {
            throw ApiException::conflict('core.validation.last_school_administrator');
        }

        $user->status = UserStatus::Inactivo;
        $user->save();
        $user->delete();

        event(new UserDeactivated($user->tenant_id, $user->public_id));

        return response()->json(null, 204);
    }

    public function restore(string $publicId): UserResource
    {
        $user = User::withTrashed()->where('public_id', $publicId)->firstOrFail();

        if ($user->deleted_at === null) {
            throw ApiException::notFound();
        }

        if (User::query()->where('email', $user->email)->exists()) {
            throw ApiException::conflict('core.validation.email_duplicate');
        }

        $user->restore();
        $user->status = UserStatus::Inactivo;
        $user->save();

        event(new UserRestored($user->tenant_id, $user->public_id));

        return new UserResource($user->fresh(['person', 'roles']));
    }

    public function updateStatus(Request $request, string $publicId): UserResource
    {
        $request->validate(['status' => ['required', 'in:activo,inactivo']]);

        $user = $this->findOrFail($publicId);
        $actor = Auth::user();

        if ($actor instanceof User && $actor->is($user)) {
            throw ApiException::conflict('core.validation.cannot_modify_self');
        }

        $newStatus = UserStatus::from($request->string('status')->value());

        if ($user->status === UserStatus::Pendiente) {
            throw ApiException::conflict('core.validation.invitation_requires_pending_user');
        }

        if ($newStatus === UserStatus::Inactivo
            && $this->adminGuard->wouldLeaveNoActiveAdministrator($user)
            && $user->roles->contains('code', 'administrador_centro')) {
            throw ApiException::conflict('core.validation.last_school_administrator');
        }

        $user->status = $newStatus;
        $user->save();

        return new UserResource($user->fresh(['person', 'roles']));
    }

    private function findOrFail(string $publicId): User
    {
        return User::with(['person', 'roles'])->where('public_id', $publicId)->firstOrFail();
    }
}
