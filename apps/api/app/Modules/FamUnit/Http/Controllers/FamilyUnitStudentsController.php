<?php

namespace App\Modules\FamUnit\Http\Controllers;

use App\Modules\Core\Domain\PersonDirectory;
use App\Modules\FamUnit\Application\FamilyRefs;
use App\Modules\FamUnit\Application\FamilyUnitService;
use App\Modules\FamUnit\Application\FamilyUnitStudentService;
use App\Modules\FamUnit\Application\FamUnitErrors;
use App\Modules\FamUnit\Domain\Models\FamilyUnitStudent;
use App\Modules\FamUnit\Http\Requests\AddUnitStudentRequest;
use App\Modules\FamUnit\Http\Resources\FamilyUnitStudentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REQ-FAM-UNIT/api.md §2.3 (alumnado de la unidad, 1.14-a). Añadir o quitar alumnos es **configurar** la unidad:
 * exige `familia.actualizar`, no `crear`/`eliminar`. El listado no se pagina (decenas como mucho por unidad) y va
 * ordenado por nombre.
 */
class FamilyUnitStudentsController extends FamUnitController
{
    public function index(Request $request, string $publicId, FamilyUnitStudentService $service, FamilyRefs $refs): JsonResponse
    {
        $memberships = $service->listForUnit($publicId, $this->decision($request), $this->actor($request));
        $refs->preloadPersons(array_map(static fn (FamilyUnitStudent $m): int => (int) $m->student_person_id, $memberships));

        foreach ($memberships as $membership) {
            $membership->load('familyUnit');
        }

        usort($memberships, static fn (FamilyUnitStudent $a, FamilyUnitStudent $b): int => [$refs->person((int) $a->student_person_id)?->displayName, $a->id] <=> [$refs->person((int) $b->student_person_id)?->displayName, $b->id]);

        return response()->json(['data' => FamilyUnitStudentResource::collection($memberships)->resolve()]);
    }

    public function store(AddUnitStudentRequest $request, string $publicId, FamilyUnitService $units, FamilyUnitStudentService $service, PersonDirectory $people): JsonResponse
    {
        $units->find($publicId);

        $person = $people->findByPublicId($request->studentPerson())
            ?? throw FamUnitErrors::invalid('student_person', 'fam_unit.validation.student_not_found');

        $membershipId = $service->add($publicId, $person->id);

        return (new FamilyUnitStudentResource($service->findInUnit($publicId, $membershipId)->load('familyUnit')))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(string $publicId, string $membershipPublicId, FamilyUnitStudentService $service): JsonResponse
    {
        $found = $service->findInUnit($publicId, $membershipPublicId);

        return response()->json(['remaining_links' => $service->remove((string) $found->public_id)]);
    }
}
