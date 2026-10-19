<?php

namespace App\Modules\Acad\Http\Controllers;

use App\Modules\Acad\Application\FacilityBookingService;
use App\Modules\Acad\Application\StaffDirectory;
use App\Modules\Acad\Application\StructureYears;
use App\Modules\Acad\Domain\Models\Facility;
use App\Modules\Acad\Http\Requests\IndexFacilityBookingsRequest;
use App\Modules\Acad\Http\Requests\StoreFacilityBookingRequest;
use App\Modules\Acad\Http\Resources\FacilityBookingResource;
use App\Support\Api\PagePaginatedResponse;
use App\Support\Authorization\PermissionDecision;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * REQ-ACAD/api.md §12 (reservas de espacios, 1.12-b). Permisos del recurso
 * `reservas` (`permisos.md §10.1`). Sin `PATCH`: las reservas no se editan
 * (`RN-ACAD-142`), la ruta no existe (`405`). `DELETE` con ámbito `propios`
 * (`OPEN-ACAD-42`): `RequirePermission` deja la `PermissionDecision` en los
 * atributos de la petición (`permission_decision`) y el servicio la aplica con
 * `ScopedQuery`.
 */
class FacilityBookingsController extends StructureController
{
    public function index(IndexFacilityBookingsRequest $request, StructureYears $years, FacilityBookingService $service, StaffDirectory $staff): JsonResponse
    {
        $param = $request->query('academic_year');
        $year = $years->forListing(is_string($param) ? $param : null, $this->actor($request));

        $facilityIds = null;

        if ($request->list('facility') !== []) {
            // Un `public_id` inexistente o de otro centro no coincide con nada (api.md §12.4).
            $facilityIds = array_map('intval', Facility::query()->whereIn('public_id', $request->list('facility'))->pluck('id')->all());
        }

        $from = $request->query('booked_from');
        $to = $request->query('booked_to');

        $query = $service->listing($year->id, $facilityIds, is_string($from) ? $from : null, is_string($to) ? $to : null);

        $page = $query->paginate($request->integer('per_page', 25))->withQueryString();
        $staff->preload(array_values(array_filter(array_map(static fn ($b): ?int => $b->created_by === null ? null : (int) $b->created_by, $page->items()))));

        return PagePaginatedResponse::make($page, FacilityBookingResource::class);
    }

    public function store(StoreFacilityBookingRequest $request, FacilityBookingService $service): JsonResponse
    {
        $booking = $service->create($request->payload(), $this->actor($request));

        return (new FacilityBookingResource($booking->load('facility')))
            ->response()
            ->setStatusCode(201)
            ->header('Location', url("/api/v1/facility-bookings/{$booking->public_id}"));
    }

    public function show(Request $request, string $publicId, FacilityBookingService $service): FacilityBookingResource
    {
        return new FacilityBookingResource($service->find($publicId, $this->actor($request))->load('facility'));
    }

    public function destroy(Request $request, string $publicId, FacilityBookingService $service): Response
    {
        $decision = $request->attributes->get('permission_decision');

        // Obligatoria: la deja `RequirePermission`; si falta, fallo (nunca se cancela sin acotar por ámbito).
        if (! $decision instanceof PermissionDecision) {
            throw new \LogicException('Falta la decisión de permiso de reservas.eliminar.');
        }

        $service->delete($publicId, $this->actor($request), $decision);

        return response()->noContent();
    }
}
