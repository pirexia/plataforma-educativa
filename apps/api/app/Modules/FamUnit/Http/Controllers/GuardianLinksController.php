<?php

namespace App\Modules\FamUnit\Http\Controllers;

use App\Modules\Core\Domain\PersonDirectory;
use App\Modules\FamUnit\Application\FamilyRefs;
use App\Modules\FamUnit\Application\FamUnitErrors;
use App\Modules\FamUnit\Application\GuardianLinkService;
use App\Modules\FamUnit\Domain\Models\GuardianStudentLink;
use App\Modules\FamUnit\Http\Requests\IndexGuardianLinksRequest;
use App\Modules\FamUnit\Http\Requests\StoreGuardianLinkRequest;
use App\Modules\FamUnit\Http\Requests\UpdateGuardianLinkRequest;
use App\Modules\FamUnit\Http\Resources\GuardianStudentLinkResource;
use App\Support\Api\PagePaginatedResponse;
use App\Support\Authorization\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * REQ-FAM-UNIT/api.md §2.4 (vínculos tutor ↔ alumno, 1.14-a). Permisos del recurso `vinculo_tutor`. El campo
 * `revocation` solo lo ve quien tiene `revocacion_tutor.leer` (`api.md §1.4`, `RN-FAM-UNIT-36`).
 */
class GuardianLinksController extends FamUnitController
{
    public function index(IndexGuardianLinksRequest $request, GuardianLinkService $service, PersonDirectory $people, FamilyRefs $refs, PermissionResolver $permissions): JsonResponse
    {
        $actor = $this->actor($request);
        $canReadRevocation = $permissions->decide($actor, 'revocacion_tutor.leer')->permitted;
        $status = $request->list('status');

        // api.md §3: el filtro `status=revocado` exige `revocacion_tutor.leer`.
        if (in_array('revocado', $status, true) && ! $canReadRevocation) {
            throw FamUnitErrors::filterForbidden();
        }

        $filters = [
            'family_unit' => $request->list('family_unit'),
            'guardian' => $request->list('guardian'),
            'status' => $status,
            'is_emergency_contact' => $request->flag('is_emergency_contact'),
            'is_legal_guardian' => $request->flag('is_legal_guardian'),
        ];

        if ($request->list('student_person') !== []) {
            // Una persona desconocida o de otro centro no coincide con nada (api.md §3).
            $filters['student_person'] = array_values(array_filter(array_map(
                static fn (string $id): ?int => $people->findByPublicId($id)?->id,
                $request->list('student_person'),
            ), static fn (?int $id): bool => $id !== null));
        }

        $direction = str_starts_with($request->sortParam('created_at'), '-') ? 'desc' : 'asc';
        $query = $service->listing($this->decision($request), $actor, $filters)->orderBy('created_at', $direction)->orderBy('id');

        $page = $query->paginate($request->integer('per_page', 25))->withQueryString();
        $this->preload($refs, $page->items());
        $request->attributes->set('fam_unit.with_revocation', $canReadRevocation);

        return PagePaginatedResponse::make($page, GuardianStudentLinkResource::class);
    }

    public function store(StoreGuardianLinkRequest $request, GuardianLinkService $service, FamilyRefs $refs, PermissionResolver $permissions): JsonResponse
    {
        $actor = $this->actor($request);
        $link = $service->create($request->payload(), $actor);
        $request->attributes->set('fam_unit.with_revocation', $permissions->decide($actor, 'revocacion_tutor.leer')->permitted);

        return (new GuardianStudentLinkResource($link))
            ->response()
            ->setStatusCode(201)
            ->header('Location', url("/api/v1/guardian-links/{$link->public_id}"));
    }

    public function show(Request $request, string $publicId, GuardianLinkService $service, FamilyRefs $refs, PermissionResolver $permissions): GuardianStudentLinkResource
    {
        $actor = $this->actor($request);
        $link = $service->findScoped($publicId, $this->decision($request), $actor);
        $this->preload($refs, [$link]);
        $request->attributes->set('fam_unit.with_revocation', $permissions->decide($actor, 'revocacion_tutor.leer')->permitted);

        return new GuardianStudentLinkResource($link);
    }

    public function update(UpdateGuardianLinkRequest $request, string $publicId, GuardianLinkService $service, FamilyRefs $refs, PermissionResolver $permissions): GuardianStudentLinkResource
    {
        $actor = $this->actor($request);
        $service->find($publicId);
        $link = $service->update($publicId, $request->payload(), $actor);
        $request->attributes->set('fam_unit.with_revocation', $permissions->decide($actor, 'revocacion_tutor.leer')->permitted);

        return new GuardianStudentLinkResource($link);
    }

    public function destroy(string $publicId, GuardianLinkService $service): Response
    {
        $service->find($publicId);
        $service->delete($publicId);

        return response()->noContent();
    }

    /**
     * @param  array<int, GuardianStudentLink>  $links
     */
    private function preload(FamilyRefs $refs, array $links): void
    {
        $refs->preloadPersons(array_map(static fn (GuardianStudentLink $l): int => (int) $l->student_person_id, $links));
        $refs->preloadActors(array_values(array_filter(array_map(static fn (GuardianStudentLink $l): ?int => $l->revoked_by === null ? null : (int) $l->revoked_by, $links))));
    }
}
