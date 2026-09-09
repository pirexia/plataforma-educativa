<?php

namespace App\Modules\Backoffice\Http\Controllers;

use App\Modules\Backoffice\Application\PlatformCursorCodec;
use App\Modules\Backoffice\Domain\Models\AdminActionLog;
use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Modules\Backoffice\Http\Resources\AdminActionLogResource;
use App\Support\Api\ApiException;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

/**
 * REQ-BO-007, api.md §2.9, §3.2. `plataforma_platform` (BYPASSRLS): ve la
 * tabla entera. Orden `(occurred_at DESC, id DESC)`, cursor cifrado con
 * el `platform_admin_id` del emisor en vez de un `tenant_id`
 * (`PlatformCursorCodec`).
 */
class AdminActionLogsController extends Controller
{
    public function __construct(
        private readonly PlatformCursorCodec $cursorCodec,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = array_filter([
            'action' => $request->input('action'),
            'actor_platform_admin_id' => $request->input('actor_platform_admin_id'),
            'affected_tenant_id' => $request->input('affected_tenant_id'),
            'occurred_at_from' => $request->input('occurred_at_from'),
            'occurred_at_to' => $request->input('occurred_at_to'),
        ], fn ($v) => $v !== null);

        return $this->paginate(AdminActionLog::query(), $filters, $request);
    }

    public function forTenant(Request $request, string $tenantPublicId): JsonResponse
    {
        $tenant = Tenant::query()->where('public_id', $tenantPublicId)->first();

        if ($tenant === null) {
            throw ApiException::notFound();
        }

        return $this->paginate(
            AdminActionLog::query()->where('affected_tenant_id', $tenant->id),
            ['affected_tenant_id' => $tenant->id],
            $request,
        );
    }

    /**
     * @param  Builder<AdminActionLog>  $query
     * @param  array<string, mixed>  $filters
     */
    private function paginate(Builder $query, array $filters, Request $request): JsonResponse
    {
        $admin = Auth::guard('platform')->user();

        if (! $admin instanceof PlatformAdmin) {
            throw ApiException::unauthenticated();
        }

        if (isset($filters['action'])) {
            $query->whereIn('action', explode(',', (string) $filters['action']));
        }
        if (isset($filters['actor_platform_admin_id'])) {
            $query->where('actor_platform_admin_id', $filters['actor_platform_admin_id']);
        }
        if (isset($filters['affected_tenant_id'])) {
            $query->where('affected_tenant_id', $filters['affected_tenant_id']);
        }
        if (isset($filters['occurred_at_from'])) {
            $query->where('occurred_at', '>=', $filters['occurred_at_from']);
        }
        if (isset($filters['occurred_at_to'])) {
            $query->where('occurred_at', '<=', $filters['occurred_at_to']);
        }

        $fingerprint = $this->cursorCodec->fingerprint($filters);
        $limit = min($request->integer('limit', 50), 200);

        if ($request->filled('cursor')) {
            [$occurredAt, $id] = $this->cursorCodec->decode($request->string('cursor')->value(), $fingerprint, $admin->id);

            $query->where(function (Builder $w) use ($occurredAt, $id): void {
                $w->where('occurred_at', '<', $occurredAt)
                    ->orWhere(function (Builder $w2) use ($occurredAt, $id): void {
                        $w2->where('occurred_at', $occurredAt)->where('id', '<', $id);
                    });
            });
        }

        $logs = $query->orderByDesc('occurred_at')->orderByDesc('id')->limit($limit + 1)->get();

        $hasMore = $logs->count() > $limit;
        $logs = $logs->take($limit);

        $nextCursor = null;

        if ($hasMore && $logs->isNotEmpty()) {
            $last = $logs->last();
            $nextCursor = $this->cursorCodec->encode($last->occurred_at->toJSON(), $last->id, $fingerprint, $admin->id);
        }

        return response()->json([
            'data' => AdminActionLogResource::collection($logs)->resolve(),
            'meta' => ['next_cursor' => $nextCursor, 'has_more' => $hasMore],
        ]);
    }
}
