<?php

namespace App\Modules\Prl\Http\Controllers;

use App\Modules\Prl\Application\DoorService;
use App\Modules\Prl\Http\Requests\SearchDoorStudentsRequest;
use App\Modules\Prl\Http\Resources\DoorPickupEntryResource;
use App\Modules\Prl\Http\Resources\DoorStudentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REQ-PRL/api.md §8.4 (1.14b-b, `REQ-PRL-004`): la consulta rápida de la puerta, permiso `entrega_menor.leer`. Buscar al alumno
 * (`GET /door/students`, máximo 20, sin paginar) y abrir su lista (`GET /door/students/{student_person}/pickup-list`), que es la
 * lectura auditada y limitada de `fam_unit` con finalidad `puerta` (`CA-PRL-010`). Sin caché: la lista se calcula en cada
 * petición (`RN-FAM-UNIT-81`), y la respuesta lo dice con `Cache-Control: no-store` (URLs firmadas de fotografía).
 */
class DoorController extends PrlController
{
    public function students(SearchDoorStudentsRequest $request, DoorService $door): JsonResponse
    {
        return response()->json([
            'data' => DoorStudentResource::collection($door->students($request->term()))->resolve($request),
        ]);
    }

    public function pickupList(Request $request, string $studentPersonPublicId, DoorService $door): JsonResponse
    {
        $entries = $door->pickupList($studentPersonPublicId);

        return response()->json([
            'data' => DoorPickupEntryResource::collection($entries)->resolve($request),
            // El protocolo mínimo del producto siempre existe (`OPEN-FAM-UNIT-40`): la alerta lo muestra al abrirse.
            'meta' => ['as_of' => now()->toIso8601ZuluString(), 'protocol_available' => true],
        ])->header('Cache-Control', 'no-store');
    }
}
