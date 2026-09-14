<?php

namespace App\Modules\Backoffice\Http\Controllers;

use App\Modules\Backoffice\Application\DualAuthorizationService;
use App\Modules\Backoffice\Domain\Models\DualAuthorization;
use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Modules\Backoffice\Http\Requests\StorePlatformDualAuthorizationApprovalRequest;
use App\Modules\Backoffice\Http\Requests\StorePlatformDualAuthorizationRejectionRequest;
use App\Modules\Backoffice\Http\Resources\DualAuthorizationResource;
use App\Support\Api\ApiException;
use App\Support\Api\PagePaginatedResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

/**
 * REQ-BO-007, api.md §2.8. `GET` es de lectura general
 * (`autorizacion.leer`); la aprobación y el rechazo se rigen por la
 * capacidad de la **acción autorizada**, no por una propia
 * (permisos.md §5.2) — `DualAuthorizationService` la comprueba, así que
 * el middleware de ruta solo exige `autorizacion.leer` como línea base.
 */
class DualAuthorizationsController extends Controller
{
    public function __construct(
        private readonly DualAuthorizationService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = DualAuthorization::query();

        if ($request->filled('status')) {
            $query->whereIn('status', explode(',', (string) $request->string('status')));
        }

        if ($request->filled('action')) {
            $query->whereIn('action', explode(',', (string) $request->string('action')));
        }

        if ($request->filled('requested_by')) {
            $requester = PlatformAdmin::query()->where('public_id', $request->string('requested_by')->value())->first();
            $query->where('requested_by', $requester === null ? 0 : $requester->id);
        }

        $paginator = $query->orderByDesc('requested_at')->paginate($request->integer('per_page', 20));

        return PagePaginatedResponse::make($paginator, DualAuthorizationResource::class);
    }

    public function show(string $publicId): JsonResponse
    {
        return response()->json(new DualAuthorizationResource($this->find($publicId)));
    }

    public function approve(StorePlatformDualAuthorizationApprovalRequest $request, string $publicId): JsonResponse
    {
        $authorization = $this->service->approve(
            $this->find($publicId),
            $this->actor(),
            $request->input('resolution_reason'),
        );

        return response()->json(new DualAuthorizationResource($authorization));
    }

    public function reject(StorePlatformDualAuthorizationRejectionRequest $request, string $publicId): JsonResponse
    {
        $authorization = $this->service->reject(
            $this->find($publicId),
            $this->actor(),
            $request->string('resolution_reason')->value(),
        );

        return response()->json(new DualAuthorizationResource($authorization));
    }

    private function find(string $publicId): DualAuthorization
    {
        $authorization = DualAuthorization::query()->where('public_id', $publicId)->first();

        if ($authorization === null) {
            throw ApiException::notFound();
        }

        return $authorization;
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
