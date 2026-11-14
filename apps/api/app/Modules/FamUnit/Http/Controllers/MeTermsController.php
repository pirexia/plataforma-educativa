<?php

namespace App\Modules\FamUnit\Http\Controllers;

use App\Modules\FamUnit\Application\FamilyRefs;
use App\Modules\FamUnit\Application\TermsAcceptanceService;
use App\Modules\FamUnit\Http\Requests\StoreTermsAcceptanceRequest;
use App\Modules\FamUnit\Http\Resources\TermsAcceptanceResource;
use App\Modules\FamUnit\Http\Resources\TermsVersionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REQ-FAM-UNIT/api.md §2.8 (1.14-c, `ADR-067`). Autoservicio por identidad, sin permiso (precedente `GET /me`; ampliación
 * L1 de `AR-07a`) y exento del muro de condiciones (L3): es justo lo que el usuario necesita para salir de él. Solo
 * actúa sobre el propio usuario autenticado: no recibe ningún identificador de usuario.
 */
class MeTermsController extends FamUnitController
{
    /**
     * `{ "required": bool, "version": TermsVersion|null }`: la versión pendiente del usuario o `null` si no tiene ninguna
     * (sin versión publicada, no obligado o ya aceptada: `RN-FAM-UNIT-62`, `-63`). Sin caché (`ADR-067 §5.8`).
     */
    public function show(Request $request, TermsAcceptanceService $service): JsonResponse
    {
        $pending = $service->isPending($this->actor($request));
        $version = $pending ? $service->published() : null;

        return response()->json([
            'required' => $pending && $version !== null,
            'version' => $version === null ? null : (new TermsVersionResource($version))->resolve($request),
        ]);
    }

    /** `201` con la aceptación nueva; `200` si ya existía (`RN-FAM-UNIT-64`, idempotente). */
    public function store(StoreTermsAcceptanceRequest $request, TermsAcceptanceService $service, FamilyRefs $refs): JsonResponse
    {
        $user = $this->actor($request);
        $result = $service->accept($user, $request->termsVersion(), ['ip' => $request->ip(), 'user_agent' => $request->userAgent()]);

        $refs->preloadActors([(int) $user->id]);

        return (new TermsAcceptanceResource($result['acceptance']))
            ->response()
            ->setStatusCode($result['created'] ? 201 : 200);
    }
}
