<?php

namespace App\Modules\Alum\Http\Controllers;

use App\Modules\Alum\Application\EnrollmentService;
use App\Modules\Alum\Application\ExportRateLimit;
use App\Modules\Alum\Http\Requests\ExportEnrollmentsRequest;
use App\Modules\Alum\Http\Requests\IndexEnrollmentsRequest;
use App\Modules\Alum\Http\Requests\StoreEnrollmentRequest;
use App\Modules\Alum\Http\Requests\UpdateEnrollmentRequest;
use App\Modules\Alum\Http\Requests\WithdrawEnrollmentRequest;
use App\Modules\Alum\Http\Resources\EnrollmentResource;
use App\Modules\Alum\Infrastructure\Jobs\GenerateEnrollmentsExport;
use App\Modules\Core\Domain\ExportRequestService;
use App\Support\Api\PagePaginatedResponse;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * REQ-ALUM/api.md §2 (matrícula, 1.15-b). Permisos del recurso `matricula` (`permisos.md §2`). Listado y detalle acotados
 * por ámbito con `ScopedQuery` y por curso legible: lo no alcanzado es `404` (`RN-ALUM-23`, `-64`). Las escrituras exigen
 * ámbito `todos`. Sin `Idempotency-Key` (`RN-ALUM-27`): el índice único y la transición de estado protegen el reintento.
 */
class EnrollmentsController extends AlumController
{
    public function index(IndexEnrollmentsRequest $request, EnrollmentService $service): JsonResponse
    {
        $page = $service->page(
            $this->decision($request),
            $this->actor($request),
            $request->filters(),
            $request->sortParam(),
            max(1, $request->integer('page', 1)),
            $request->integer('per_page', 25),
        );
        $service->attachViews($page->items());

        return PagePaginatedResponse::make($page, EnrollmentResource::class);
    }

    public function store(StoreEnrollmentRequest $request, EnrollmentService $service): JsonResponse
    {
        $enrollment = $service->create($this->actor($request), $request->payload());
        $service->attachViews([$enrollment]);

        return (new EnrollmentResource($enrollment))
            ->response()
            ->setStatusCode(201)
            ->header('Location', url("/api/v1/enrollments/{$enrollment->public_id}"));
    }

    public function show(Request $request, string $publicId, EnrollmentService $service): EnrollmentResource
    {
        $enrollment = $service->findScoped($publicId, $this->decision($request), $this->actor($request));
        $service->attachViews([$enrollment]);

        return new EnrollmentResource($enrollment);
    }

    public function update(UpdateEnrollmentRequest $request, string $publicId, EnrollmentService $service): EnrollmentResource
    {
        $enrollment = $service->update($this->actor($request), $publicId, $request->payload());
        $service->attachViews([$enrollment]);

        return new EnrollmentResource($enrollment);
    }

    public function withdraw(WithdrawEnrollmentRequest $request, string $publicId, EnrollmentService $service): EnrollmentResource
    {
        $enrollment = $service->withdraw($this->actor($request), $publicId, $request->payload());
        $service->attachViews([$enrollment]);

        return new EnrollmentResource($enrollment);
    }

    public function destroy(Request $request, string $publicId, EnrollmentService $service): Response
    {
        $service->annul($this->actor($request), $publicId);

        return response()->noContent();
    }

    /**
     * `POST /enrollments/exports` (`api.md §6`): en cola (`INV-012`) sobre la primitiva `data_exports` de `Core`, con
     * `kind = alum_enrollments`, enlace caducable y la solicitud auditada como `exported` con los filtros. `202` con el
     * recurso de exportación (`GET /data-exports/{public_id}`). El límite de tasa cuenta todos los intentos.
     */
    public function storeExport(ExportEnrollmentsRequest $request, EnrollmentService $service, ExportRequestService $exports, TenantContext $tenantContext, ExportRateLimit $rateLimit): JsonResponse
    {
        $actor = $this->actor($request);
        $rateLimit->hit($actor);

        $filters = $request->payload();
        $service->assertExportable($this->decision($request), $actor, $filters);

        // Falla en cerrado (revisión de seguridad B3): sin el `public_id` del centro no hay ruta de objeto ni contexto del job.
        $tenant = Tenant::query()->find($tenantContext->tenantId());

        if ($tenant === null || $tenant->public_id === '') {
            throw new \LogicException('No se pudo resolver el centro de la exportación.');
        }

        $export = $exports->request('alum_enrollments', 'csv', $filters, $actor);

        GenerateEnrollmentsExport::dispatch($export->id, $tenant->public_id);

        return response()->json(['public_id' => $export->public_id, 'status' => $export->status], 202);
    }
}
