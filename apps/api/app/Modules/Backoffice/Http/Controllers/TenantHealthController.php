<?php

namespace App\Modules\Backoffice\Http\Controllers;

use App\Modules\Backoffice\Application\FailedJobRetryService;
use App\Modules\Backoffice\Application\PlatformCursorCodec;
use App\Modules\Backoffice\Application\PlatformReauthenticationCheck;
use App\Modules\Backoffice\Application\TenantHealthService;
use App\Modules\Backoffice\Domain\FailedJobPresentation;
use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Modules\Backoffice\Http\Requests\IndexFailedJobsRequest;
use App\Modules\Backoffice\Http\Requests\StoreFailedJobRetryRequest;
use App\Support\Api\ApiException;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * `REQ-BO-004` reducido, sub-paso `1.6d`. `api.md §2.10.1` a `§2.10.3`.
 * La ficha de salud, el listado de trabajos fallidos del centro y el
 * único reintento — uno a uno, nunca masivo (`RN-BO-88`).
 */
class TenantHealthController extends Controller
{
    public function __construct(
        private readonly TenantHealthService $health,
        private readonly FailedJobRetryService $retryService,
        private readonly PlatformCursorCodec $cursorCodec,
    ) {}

    /**
     * `GET /tenants/{public_id}/health`, capacidad `salud.leer`.
     */
    public function show(string $publicId): JsonResponse
    {
        return response()->json($this->health->forTenant($this->find($publicId)));
    }

    /**
     * `GET /tenants/{public_id}/failed-jobs`, capacidad `salud.leer`.
     * `api.md §2.10.2`: cursor `(failed_at DESC, id DESC)`, filtros
     * `failed_at_from`, `failed_at_to`, `queue`, `job_class` — sin `q`
     * de texto libre a propósito (`RN-BO-84`). **Nunca** `payload` ni
     * traza en la respuesta.
     */
    public function failedJobs(IndexFailedJobsRequest $request, string $publicId): JsonResponse
    {
        $tenant = $this->find($publicId);
        $admin = $this->actor();

        $filters = array_filter([
            'affected_tenant_id' => $tenant->id,
            'failed_at_from' => $request->input('failed_at_from'),
            'failed_at_to' => $request->input('failed_at_to'),
            'queue' => $request->input('queue'),
            'job_class' => $request->input('job_class'),
        ], fn ($v) => $v !== null);

        $query = DB::connection('pgsql_platform')->table('failed_jobs')
            ->whereRaw("payload::jsonb ->> 'tenant_id' = ?", [(string) $tenant->id]);

        if ($request->filled('failed_at_from')) {
            $query->where('failed_at', '>=', $request->input('failed_at_from'));
        }
        if ($request->filled('failed_at_to')) {
            $query->where('failed_at', '<=', $request->input('failed_at_to'));
        }
        if ($request->filled('queue')) {
            $query->where('queue', $request->string('queue')->value());
        }
        if ($request->filled('job_class')) {
            $query->whereRaw("payload::jsonb ->> 'displayName' = ?", [$request->string('job_class')->value()]);
        }

        $fingerprint = $this->cursorCodec->fingerprint($filters);
        // IndexFailedJobsRequest ya garantiza 1-200: un limit=0 o negativo
        // es un 422 de forma, no llega aquí (hallazgo de /codex:review).
        $limit = $request->integer('limit', 50);

        if ($request->filled('cursor')) {
            [$failedAt, $id] = $this->cursorCodec->decode($request->string('cursor')->value(), $fingerprint, $admin->id);

            $query->where(function ($w) use ($failedAt, $id): void {
                $w->where('failed_at', '<', $failedAt)
                    ->orWhere(function ($w2) use ($failedAt, $id): void {
                        $w2->where('failed_at', $failedAt)->where('id', '<', $id);
                    });
            });
        }

        $rows = $query->orderByDesc('failed_at')->orderByDesc('id')->limit($limit + 1)->get();

        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        $nextCursor = null;

        if ($hasMore && $rows->isNotEmpty()) {
            $last = $rows->last();
            $nextCursor = $this->cursorCodec->encode(
                Carbon::parse($last->failed_at)->toJSON(),
                (int) $last->id,
                $fingerprint,
                $admin->id,
            );
        }

        return response()->json([
            // RN-BO-84: uuid, queue, connection, failed_at,
            // payload.displayName (una clase PHP, no un dato) y la clase
            // de la excepción — nunca el payload ni la traza. El mensaje
            // de la excepción sí se devuelve (OPEN-BO-22, resuelta: sí).
            'data' => $rows->map(fn (object $row): array => [
                'uuid' => $row->uuid,
                'job_class' => FailedJobPresentation::jobClassOf($row),
                'queue' => $row->queue,
                'connection' => $row->connection,
                'failed_at' => Carbon::parse($row->failed_at)->toJSON(),
                'exception_class' => FailedJobPresentation::exceptionClassOf($row),
                'exception_message' => FailedJobPresentation::exceptionMessageOf($row),
            ])->all(),
            'meta' => ['next_cursor' => $nextCursor, 'has_more' => $hasMore],
        ]);
    }

    /**
     * `POST /tenants/{public_id}/failed-jobs/{uuid}/retry`, capacidad
     * `job.reintentar`, sensible (`OPEN-BO-21`). `api.md §2.10.3`.
     */
    public function retry(StoreFailedJobRetryRequest $request, string $publicId, string $uuid): JsonResponse
    {
        PlatformReauthenticationCheck::ensureFresh($request);

        $tenant = $this->find($publicId);
        $jobs = $this->retryService->retryForTenant($tenant, $uuid, $request->input('reason'));

        return response()->json(['data' => ['jobs' => $jobs]]);
    }

    private function find(string $publicId): Tenant
    {
        $tenant = Tenant::query()->withTrashed()->where('public_id', $publicId)->first();

        if ($tenant === null) {
            throw ApiException::notFound();
        }

        return $tenant;
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
