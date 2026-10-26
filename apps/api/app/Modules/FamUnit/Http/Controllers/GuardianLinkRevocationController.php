<?php

namespace App\Modules\FamUnit\Http\Controllers;

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
    public function store(RevokeGuardianLinkRequest $request, string $publicId, GuardianLinkService $service): JsonResponse
    {
        $service->find($publicId);
        $result = $service->revoke($publicId, $request->reason(), $this->actor($request));
        $request->attributes->set('fam_unit.with_revocation', true);

        return response()->json(
            (new GuardianStudentLinkResource($result['link']))->resolve($request) + ['principal_cleared' => $result['principal_cleared']],
        );
    }

    public function destroy(Request $request, string $publicId, GuardianLinkService $service): GuardianStudentLinkResource
    {
        $service->find($publicId);
        $link = $service->lift($publicId);
        $request->attributes->set('fam_unit.with_revocation', true);

        return new GuardianStudentLinkResource($link);
    }
}
