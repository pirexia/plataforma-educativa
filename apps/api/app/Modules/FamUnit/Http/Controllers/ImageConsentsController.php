<?php

namespace App\Modules\FamUnit\Http\Controllers;

use App\Modules\Core\Domain\ExportRequestService;
use App\Modules\Core\Domain\PersonDirectory;
use App\Modules\FamUnit\Application\FamilyRefs;
use App\Modules\FamUnit\Application\ImageConsentFilters;
use App\Modules\FamUnit\Application\ImageConsentService;
use App\Modules\FamUnit\Http\Requests\ExportImageConsentsRequest;
use App\Modules\FamUnit\Http\Requests\IndexImageConsentsRequest;
use App\Modules\FamUnit\Http\Resources\ImageConsentResource;
use App\Modules\FamUnit\Infrastructure\Jobs\GenerateImageConsentsExport;
use App\Support\Api\PagePaginatedResponse;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * REQ-FAM-UNIT/api.md §2.7, §4 (1.14-b, `ADR-066 §5.2`). Estado vigente **calculado** de los alumnos de las unidades
 * (`consentimiento_imagen.leer`, ámbito `todos` o `unidad_familiar`) y su informe en cola (`consentimiento_imagen.exportar`,
 * solo `todos`). El listado parte de los alumnos vivos, así que `state=pendiente` incluye a quien nunca ha decidido.
 */
class ImageConsentsController extends FamUnitController
{
    public function index(IndexImageConsentsRequest $request, ImageConsentService $service, PersonDirectory $people, FamilyRefs $refs): JsonResponse
    {
        $filters = ImageConsentFilters::resolve($request->filters(), $people);

        $page = $service->page(
            $this->decision($request),
            $this->actor($request),
            $filters,
            $request->sortParam(ImageConsentService::DEFAULT_SORT),
            $request->integer('page', 1),
            $request->integer('per_page', 25),
        );

        $rows = $page->items();
        $refs->preloadPersons(array_map(static fn (array $row): int => $row['student_person_id'], $rows));
        $units = $service->unitRefs(array_values(array_unique(array_filter(array_map(static fn (array $row): ?int => $row['family_unit_id'], $rows)))));

        $enriched = new LengthAwarePaginator(
            array_map(static fn (array $row): array => $row + ['family_unit' => $row['family_unit_id'] === null ? null : ($units[$row['family_unit_id']]['public_id'] ?? null)], $rows),
            $page->total(),
            $page->perPage(),
            $page->currentPage(),
        );

        return PagePaginatedResponse::make($enriched, ImageConsentResource::class);
    }

    /**
     * `POST /image-consents/exports`: en cola (`INV-012`) sobre la primitiva `data_exports` de `Core`, con `kind =
     * image_consents`, enlace caducable y la solicitud auditada como `exported`. `202` con el recurso de exportación
     * (`GET /data-exports/{public_id}`).
     */
    public function storeExport(ExportImageConsentsRequest $request, ExportRequestService $exports, TenantContext $tenantContext): JsonResponse
    {
        $export = $exports->request('image_consents', 'csv', $request->payload(), $this->actor($request));

        $tenant = Tenant::query()->find($tenantContext->tenantId());
        GenerateImageConsentsExport::dispatch($export->id, $tenant->public_id ?? '');

        return response()->json(['public_id' => $export->public_id, 'status' => $export->status], 202);
    }
}
