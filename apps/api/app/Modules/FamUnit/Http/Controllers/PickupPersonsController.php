<?php

namespace App\Modules\FamUnit\Http\Controllers;

use App\Modules\FamUnit\Application\PickupPersonRateLimit;
use App\Modules\FamUnit\Application\PickupPersonService;
use App\Modules\FamUnit\Application\PickupPhotoService;
use App\Modules\FamUnit\Http\Requests\FindPickupPersonRequest;
use App\Modules\FamUnit\Http\Requests\StorePickupPersonRequest;
use App\Modules\FamUnit\Http\Requests\UpdatePickupPersonRequest;
use App\Modules\FamUnit\Http\Requests\UploadPickupPhotoRequest;
use App\Modules\FamUnit\Http\Resources\PickupPersonResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * REQ-FAM-UNIT/api.md §8.2 (1.14b-a, `REQ-FAM-UNIT-005`, `ADR-068 §5.2`/`§5.3`): las personas de recogida (un tutor o un
 * tercero) y su fotografía. Permisos del recurso `autorizado_recogida`. Una persona solo es alcanzable si es tutor del centro
 * o figura en alguna autorización viva; cualquier otra, `404` (no es un directorio de personas del centro). Las lecturas
 * (`GET` de lista, detalle y fotografía) llevan `Cache-Control: no-store` (`RN-FAM-UNIT-81`; teléfono, documento y URL firmadas). Nunca escribe en
 * `people` salvo por `PersonRegistrar` (`INV-007`).
 */
class PickupPersonsController extends FamUnitController
{
    /** `GET /pickup-persons?document_type=&document_number=`: 0 o 1 persona alcanzable con ese documento. */
    public function index(FindPickupPersonRequest $request, PickupPersonService $persons, PickupPersonRateLimit $rateLimit): JsonResponse
    {
        $rateLimit->hit('lookup', (int) $this->actor($request)->id);
        $person = $persons->findByDocument((string) $request->query('document_type'), (string) $request->query('document_number'));

        return response()->json(['data' => $person === null ? [] : [(new PickupPersonResource($persons->auditedView($person)))->resolve($request)]])
            ->header('Cache-Control', 'no-store');
    }

    /** `POST /pickup-persons`: `201` si crea; `200` con `reused = true` si ya existía una persona viva con ese documento. */
    public function store(StorePickupPersonRequest $request, PickupPersonService $persons, PickupPersonRateLimit $rateLimit): JsonResponse
    {
        $rateLimit->hit('register', (int) $this->actor($request)->id);
        $view = $persons->register($request->payload());

        return (new PickupPersonResource($view))->response()->setStatusCode($view->reused === true ? 200 : 201);
    }

    public function show(Request $request, string $personPublicId, PickupPersonService $persons): JsonResponse
    {
        return (new PickupPersonResource($persons->auditedView($persons->find($personPublicId))))->response($request)
            ->header('Cache-Control', 'no-store');
    }

    /** `PATCH`: corrige datos de una persona **sin usuario** (`OPEN-068-07`); con usuario o no alcanzable, `404`. */
    public function update(UpdatePickupPersonRequest $request, string $personPublicId, PickupPersonService $persons): PickupPersonResource
    {
        $persons->find($personPublicId);

        return new PickupPersonResource($persons->auditedView($persons->correct($personPublicId, $request->payload())));
    }

    public function putPhoto(UploadPickupPhotoRequest $request, string $personPublicId, PickupPersonService $persons, PickupPhotoService $photos): PickupPersonResource
    {
        $ref = $persons->find($personPublicId);
        $photos->upload($ref, $request->photo());

        return new PickupPersonResource($persons->auditedView($ref));
    }

    /** `GET …/photo`: URL firmada de caducidad corta, **auditada** (`read`) y con límite. */
    public function showPhoto(Request $request, string $personPublicId, PickupPersonService $persons, PickupPhotoService $photos): JsonResponse
    {
        $ref = $persons->find($personPublicId);

        return response()->json($photos->signedUrl($ref, $this->decision($request), $this->actor($request)))
            ->header('Cache-Control', 'no-store');
    }

    public function destroyPhoto(Request $request, string $personPublicId, PickupPersonService $persons, PickupPhotoService $photos): Response
    {
        $photos->remove($persons->find($personPublicId));

        return response()->noContent();
    }
}
