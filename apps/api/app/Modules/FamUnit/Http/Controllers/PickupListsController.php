<?php

namespace App\Modules\FamUnit\Http\Controllers;

use App\Modules\Core\Domain\PersonDirectory;
use App\Modules\FamUnit\Application\PickupListReader;
use App\Modules\FamUnit\Application\PickupListService;
use App\Modules\FamUnit\Domain\AuthorizedPickupList;
use App\Modules\FamUnit\Http\Resources\PickupListEntryResource;
use App\Support\Api\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REQ-FAM-UNIT/api.md §8.3, `GET /pickup-lists/{student_person}` (1.14b-a, `RN-FAM-UNIT-79` a `-81`, `-90` a `-93`): la
 * lista calculada de un alumno, **auditada** (`read`, finalidad `gestion`) y con límite. Sin paginar (decenas como mucho),
 * tutores primero y después terceros y puntuales, por nombre. `404` si el alumno no existe o el sujeto no lo alcanza (con
 * `unidad_familiar`, un tutor revocado no alcanza al alumno). Sin caché: el efecto de una retirada o de una revocación se
 * ve en la petición siguiente (`RN-FAM-UNIT-81`).
 */
class PickupListsController extends FamUnitController
{
    public function show(Request $request, string $studentPersonPublicId, PersonDirectory $people, PickupListService $list, PickupListReader $reader): JsonResponse
    {
        $student = $people->findByPublicId($studentPersonPublicId) ?? throw ApiException::notFound();
        $decision = $this->decision($request);
        $list->assertStudentReachable($decision, $this->actor($request), $student->id);

        $entries = $reader->read($student->id, AuthorizedPickupList::PURPOSE_MANAGEMENT, ! $decision->isUnrestricted());

        return response()->json([
            'data' => PickupListEntryResource::collection($entries)->resolve($request),
            'meta' => ['as_of' => now()->toIso8601ZuluString()],
        ]);
    }
}
