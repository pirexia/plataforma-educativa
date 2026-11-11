<?php

namespace App\Modules\Alum\Http\Controllers;

use App\Modules\Alum\Application\AlumErrors;
use App\Modules\Alum\Application\StudentPhotoService;
use App\Modules\Alum\Application\StudentService;
use App\Modules\Alum\Domain\Models\Student;
use App\Modules\Alum\Http\Requests\IndexStudentsRequest;
use App\Modules\Alum\Http\Requests\StoreStudentRequest;
use App\Modules\Alum\Http\Requests\UpdateStudentRequest;
use App\Modules\Alum\Http\Resources\StudentResource;
use App\Modules\Alum\Http\Resources\StudentSummaryResource;
use App\Support\Api\ApiException;
use App\Support\Api\PagePaginatedResponse;
use App\Support\Authorization\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * REQ-ALUM/api.md §1 (alumnado, 1.15-a). Permisos del recurso `alumno` (`permisos.md §2`). Listado y detalle acotados
 * por ámbito con `ScopedQuery`: lo no alcanzado es `404` (`RN-ALUM-64`, `ADR-044 §4.2`). Las escrituras exigen ámbito
 * `todos`.
 */
class StudentsController extends AlumController
{
    public function index(IndexStudentsRequest $request, StudentService $service, PermissionResolver $permissions): JsonResponse
    {
        $actor = $this->actor($request);
        $includeDeleted = $request->includeDeleted();

        // api.md §1: `include_deleted` solo con `alumno.eliminar`.
        if ($includeDeleted && ! $permissions->decide($actor, 'alumno.eliminar')->permitted) {
            throw AlumErrors::filterForbidden();
        }

        $page = $service->page(
            $this->decision($request),
            $actor,
            $request->search(),
            $includeDeleted,
            $request->sortParam(),
            max(1, $request->integer('page', 1)),
            $request->integer('per_page', 25),
            $request->enrollmentFilters(),
        );
        $service->attachPeople($page->items(), false);
        $service->attachCurrentEnrollments($page->items(), $actor, $request->enrollmentFilters());

        return PagePaginatedResponse::make($page, StudentSummaryResource::class);
    }

    public function store(StoreStudentRequest $request, StudentService $service, PermissionResolver $permissions): JsonResponse
    {
        $payload = $request->payload();
        $familyUnit = $payload['family_unit'];
        unset($payload['family_unit']);

        // `OPEN-ALUM-31` = A, `RN-ALUM-04`: con unidad familiar, además de `alumno.crear` hace falta `familia.actualizar`
        // (el permiso de `POST /family-units/{id}/students`; solo admite `todos`). Sin él, `403` y no se escribe nada.
        if ($familyUnit !== null && ! $permissions->decide($this->actor($request), 'familia.actualizar')->permitted) {
            throw ApiException::forbidden();
        }

        $student = $service->create($payload, $familyUnit);
        $service->attachPeople([$student], true);
        $service->attachEnrollments($student, $this->actor($request));

        return (new StudentResource($student))
            ->response()
            ->setStatusCode(201)
            ->header('Location', url("/api/v1/students/{$student->public_id}"));
    }

    public function show(Request $request, string $publicId, StudentService $service, StudentPhotoService $photos): StudentResource
    {
        $student = $service->findScoped($publicId, $this->decision($request), $this->actor($request));
        $service->attachPeople([$student], true);
        $service->attachEnrollments($student, $this->actor($request));
        $this->attachPhoto($student, $photos);

        return new StudentResource($student);
    }

    public function update(UpdateStudentRequest $request, string $publicId, StudentService $service, StudentPhotoService $photos): StudentResource
    {
        $service->find($publicId);
        $student = $service->update($publicId, $request->payload());
        $service->attachPeople([$student], true);
        $service->attachEnrollments($student, $this->actor($request));
        $this->attachPhoto($student, $photos);

        return new StudentResource($student);
    }

    public function destroy(string $publicId, StudentService $service): Response
    {
        $service->delete($publicId);

        return response()->noContent();
    }

    public function restore(Request $request, string $publicId, StudentService $service, StudentPhotoService $photos): StudentResource
    {
        $student = $service->restore($publicId);
        $service->attachPeople([$student], true);
        $service->attachEnrollments($student, $this->actor($request));
        $this->attachPhoto($student, $photos);

        return new StudentResource($student);
    }

    /** `has_photo` de la ficha (solo el booleano, nunca la URL: `api.md §1`). */
    private function attachPhoto(Student $student, StudentPhotoService $photos): void
    {
        $student->setAttribute('has_photo_view', isset($photos->liveStudentIds([(int) $student->id])[(int) $student->id]));
    }
}
