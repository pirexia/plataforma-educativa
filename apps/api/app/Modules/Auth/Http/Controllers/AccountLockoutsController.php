<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Domain\AccountLockService;
use App\Modules\Auth\Domain\Models\AccountLockout;
use App\Modules\Auth\Domain\UnlockReason;
use App\Modules\Auth\Http\Requests\IndexAccountLockoutsRequest;
use App\Modules\Auth\Http\Resources\AccountLockoutResource;
use App\Support\Api\ApiException;
use App\Support\Api\PagePaginatedResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

/**
 * api.md §5. El único listado del módulo, y el único par de endpoints con
 * permiso propio (`permisos.md §3`).
 */
class AccountLockoutsController extends Controller
{
    public function __construct(
        private readonly AccountLockService $lockService,
    ) {}

    public function index(IndexAccountLockoutsRequest $request): JsonResponse
    {
        $query = AccountLockout::query()->with(['user', 'unlockedByUser']);

        $statuses = $request->filled('status')
            ? explode(',', (string) $request->string('status'))
            : ['vigente'];

        $query->where(function ($q) use ($statuses): void {
            if (in_array('vigente', $statuses, true)) {
                $q->orWhereNull('unlocked_at');
            }

            if (in_array('levantado', $statuses, true)) {
                $q->orWhereNotNull('unlocked_at');
            }
        });

        if ($request->filled('q')) {
            $query->where('email', 'ilike', '%'.$request->string('q')->value().'%');
        }

        $sort = (string) $request->string('sort', '-locked_at');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $query->orderBy('locked_at', $direction);

        $paginator = $query->paginate($request->integer('per_page', 25))->withQueryString();

        return PagePaginatedResponse::make($paginator, AccountLockoutResource::class);
    }

    public function destroy(string $publicId): Response
    {
        $lockout = AccountLockout::query()->where('public_id', $publicId)->first();

        if ($lockout === null) {
            throw ApiException::notFound();
        }

        if (! $lockout->isLive()) {
            throw ApiException::conflict('auth.validation.lockout_already_unlocked');
        }

        $actor = Auth::user();

        if (! $actor instanceof User) {
            throw ApiException::unauthenticated();
        }

        $this->lockService->lift($lockout, UnlockReason::Administrador, $actor);

        return response()->noContent();
    }
}
