<?php

namespace App\Modules\FamUnit\Http\Controllers;

use App\Modules\FamUnit\Application\FamilyRefs;
use App\Modules\FamUnit\Application\GuardianLinkService;
use App\Modules\FamUnit\Http\Requests\RevokeGuardianLinkRequest;
use App\Modules\FamUnit\Http\Resources\GuardianStudentLinkResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REQ-FAM-UNIT/api.md §2.5 (revocación por custodia, 1.14-a). Permisos del recurso `revocacion_tutor`: revocar es
 * `crear`, levantar es `eliminar`. La respuesta incluye `revocation` (quien revoca tiene `revocacion_tutor.crear` y
 * necesita ver el resultado). Sin `Idempotency-Key`: repetir una revocación ya hecha responde
 * `409 invalid_transition` (`api.md §2.5`).
 */
class GuardianLinkRevocationController extends FamUnitController
{
    public function store(RevokeGuardianLinkRequest $request, string $publicId, GuardianLinkService $service, FamilyRefs $refs): JsonResponse
    {
        $actor = $this->actor($request);
        $service->find($publicId, $actor);
        $result = $service->revoke($publicId, $request->reason(), $actor);
        $refs->showRevocation(true);

        return response()->json(
            (new GuardianStudentLinkResource($result['link']))->resolve($request) + ['principal_cleared' => $result['principal_cleared']],
        );
    }

    public function destroy(Request $request, string $publicId, GuardianLinkService $service, FamilyRefs $refs): GuardianStudentLinkResource
    {
        // El levantamiento actúa justo sobre un vínculo revocado: no se oculta (#472, autoriza `revocacion_tutor.eliminar`).
        $service->find($publicId, $this->actor($request), forLift: true);
        $link = $service->lift($publicId);
        $refs->showRevocation(true);

        return new GuardianStudentLinkResource($link);
    }
}
