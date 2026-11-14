<?php

namespace App\Modules\Backoffice\Http\Controllers;

use App\Modules\Backoffice\Application\PlatformAdminManagementService;
use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Modules\Backoffice\Domain\PlatformAdminStatus;
use App\Modules\Backoffice\Domain\PlatformRole;
use App\Modules\Backoffice\Http\Requests\DestroyPlatformAdminRequest;
use App\Modules\Backoffice\Http\Requests\ReplacePlatformAdminRolesRequest;
use App\Modules\Backoffice\Http\Requests\StorePlatformAdminRequest;
use App\Modules\Backoffice\Http\Requests\UpdatePlatformAdminRequest;
use App\Modules\Backoffice\Http\Requests\UpdatePlatformAdminStatusRequest;
use App\Modules\Backoffice\Http\Resources\PlatformAdminResource;
use App\Support\Api\ApiException;
use App\Support\Api\PagePaginatedResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

/** REQ-BO-007, api.md §2.2. Sólo `superadministrador` (permisos.md §4.1). */
class PlatformAdminsController extends Controller
{
    public function __construct(
        private readonly PlatformAdminManagementService $management,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = PlatformAdmin::query();

        if ($request->filled('status')) {
            $query->whereIn('status', explode(',', (string) $request->string('status')));
        }

        if ($request->filled('q')) {
            $q = '%'.$request->string('q')->value().'%';
            $query->where(fn ($w) => $w->where('name', 'ilike', $q)->orWhere('email', 'ilike', $q));
        }

        $paginator = $query->orderByDesc('created_at')->paginate($request->integer('per_page', 20));

        return PagePaginatedResponse::make($paginator, PlatformAdminResource::class);
    }

    public function store(StorePlatformAdminRequest $request): JsonResponse
    {
        $admin = $this->management->create(
            $request->string('email')->value(),
            $request->string('name')->value(),
            $request->string('locale')->value(),
            PlatformRole::from($request->string('role')->value()),
        );

        return response()->json(new PlatformAdminResource($admin), 201);
    }

    public function show(string $publicId): JsonResponse
    {
        return response()->json(new PlatformAdminResource($this->find($publicId)));
    }

    public function update(UpdatePlatformAdminRequest $request, string $publicId): JsonResponse
    {
        $admin = $this->management->update(
            $this->find($publicId),
            $request->string('name')->value(),
            $request->string('locale')->value(),
        );

        return response()->json(new PlatformAdminResource($admin));
    }

    public function updateRoles(ReplacePlatformAdminRolesRequest $request, string $publicId): JsonResponse
    {
        $roles = array_map(
            fn (string $role) => PlatformRole::from($role),
            $request->input('roles'),
        );

        $this->management->replaceRoles($this->actor(), $this->find($publicId), $roles, $request->string('reason')->value());

        return response()->json(new PlatformAdminResource($this->find($publicId)));
    }

    public function updateStatus(UpdatePlatformAdminStatusRequest $request, string $publicId): JsonResponse
    {
        $admin = $this->management->setStatus(
            $this->actor(),
            $this->find($publicId),
            PlatformAdminStatus::from($request->string('status')->value()),
            $request->string('reason')->value(),
        );

        return response()->json(new PlatformAdminResource($admin));
    }

    public function destroy(DestroyPlatformAdminRequest $request, string $publicId): Response
    {
        $this->management->delete($this->actor(), $this->find($publicId), $request->string('reason')->value());

        return response()->noContent();
    }

    public function destroyMfa(string $publicId): Response
    {
        $this->management->resetMfa($this->actor(), $this->find($publicId));

        return response()->noContent();
    }

    private function find(string $publicId): PlatformAdmin
    {
        $admin = PlatformAdmin::query()->where('public_id', $publicId)->first();

        if ($admin === null) {
            throw ApiException::notFound();
        }

        return $admin;
    }

    private function actor(): PlatformAdmin
    {
        $admin = Auth::guard('platform')->user();

        if (! $admin instanceof PlatformAdmin) {
            throw ApiException::unauthenticated();
        }

        return $admin;
    }
}
