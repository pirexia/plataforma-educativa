<?php

namespace App\Modules\Alum\Http\Controllers;

use App\Modules\Alum\Application\GroupChangeService;
use App\Modules\Alum\Http\Requests\DecideGroupChangeRequest;
use App\Modules\Alum\Http\Requests\IndexGroupChangeRequestsRequest;
use App\Modules\Alum\Http\Requests\StoreGroupChangeRequest;
use App\Modules\Alum\Http\Resources\GroupChangeRequestResource;
use App\Support\Api\PagePaginatedResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REQ-ALUM/api.md §3 (solicitudes de cambio de grupo, 1.15-c). Permisos del recurso `matricula` (`permisos.md §2`): leer
 * (listado y detalle, acotados por ámbito con `ScopedQuery`: lo no alcanzado es `404`, `RN-ALUM-46`), actualizar (solicitar y
 * cancelar) y aprobar (aprobar y rechazar), estos tres con ámbito `todos`. Sin `Idempotency-Key`: el único de solicitud
 * pendiente y las transiciones de estado protegen el reintento.
 */
class GroupChangeRequestsController extends AlumController
{
    public function index(IndexGroupChangeRequestsRequest $request, GroupChangeService $service): JsonResponse
    {
        $page = $service->page(
            $this->decision($request),
            $this->actor($request),
            $request->filters(),
            max(1, $request->integer('page', 1)),
            $request->integer('per_page', 25),
        );
        $service->attachViews($page->items());

        return PagePaginatedResponse::make($page, GroupChangeRequestResource::class);
    }

    public function store(StoreGroupChangeRequest $request, GroupChangeService $service): JsonResponse
    {
        $created = $service->create($this->actor($request), $request->payload());
        $service->attachViews([$created]);

        return (new GroupChangeRequestResource($created))
            ->response()
            ->setStatusCode(201)
            ->header('Location', url("/api/v1/group-change-requests/{$created->public_id}"));
    }

    public function show(Request $request, string $publicId, GroupChangeService $service): GroupChangeRequestResource
    {
        $found = $service->findScoped($publicId, $this->decision($request), $this->actor($request));
        $service->attachViews([$found]);

        return new GroupChangeRequestResource($found);
    }

    public function approve(DecideGroupChangeRequest $request, string $publicId, GroupChangeService $service): GroupChangeRequestResource
    {
        $decided = $service->approve($this->actor($request), $publicId, $request->payload());
        $service->attachViews([$decided]);

        return new GroupChangeRequestResource($decided);
    }

    public function reject(DecideGroupChangeRequest $request, string $publicId, GroupChangeService $service): GroupChangeRequestResource
    {
        $decided = $service->reject($this->actor($request), $publicId, $request->payload());
        $service->attachViews([$decided]);

        return new GroupChangeRequestResource($decided);
    }

    public function cancel(DecideGroupChangeRequest $request, string $publicId, GroupChangeService $service): GroupChangeRequestResource
    {
        $decided = $service->cancel($this->actor($request), $publicId, $request->payload());
        $service->attachViews([$decided]);

        return new GroupChangeRequestResource($decided);
    }
}
