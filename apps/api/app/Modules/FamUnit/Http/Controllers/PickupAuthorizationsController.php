<?php

namespace App\Modules\FamUnit\Http\Controllers;

use App\Models\User;
use App\Modules\Core\Domain\PersonDirectory;
use App\Modules\FamUnit\Application\FamilyRefs;
use App\Modules\FamUnit\Application\PickupAuthorizationService;
use App\Modules\FamUnit\Application\PickupListService;
use App\Modules\FamUnit\Application\PickupViewContext;
use App\Modules\FamUnit\Domain\Models\PickupAuthorization;
use App\Modules\FamUnit\Http\Requests\IndexPickupAuthorizationsRequest;
use App\Modules\FamUnit\Http\Requests\StorePickupAuthorizationRequest;
use App\Modules\FamUnit\Http\Requests\UpdatePickupAuthorizationRequest;
use App\Modules\FamUnit\Http\Resources\PickupAuthorizationResource;
use App\Support\Api\PagePaginatedResponse;
use App\Support\Authorization\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REQ-FAM-UNIT/api.md §8.3 (1.14b-a, `REQ-FAM-UNIT-005`, `ADR-068`): autorizaciones de tercero y puntuales. Permisos del
 * recurso `autorizado_recogida`. Con ámbito `unidad_familiar` solo hay lectura y de los alumnos que el sujeto alcanza
 * (`RN-FAM-UNIT-91`); la escritura por la familia llega con `1.22` (`OPEN-FAM-UNIT-29`).
 */
class PickupAuthorizationsController extends FamUnitController
{
    public function index(IndexPickupAuthorizationsRequest $request, PickupListService $list, PersonDirectory $people, FamilyRefs $refs, PermissionResolver $permissions, PickupViewContext $view): JsonResponse
    {
        $actor = $this->actor($request);
        $filters = ['kind' => $request->list('kind'), 'effective' => $request->flag('effective')];

        // Una persona desconocida o de otro centro no coincide con nada (`api.md §3`).
        foreach (['student_person', 'person'] as $filter) {
            if ($request->list($filter) !== []) {
                $filters[$filter] = array_values(array_filter(array_map(
                    static fn (string $id): ?int => $people->findByPublicId($id)?->id,
                    $request->list($filter),
                ), static fn (?int $id): bool => $id !== null));
            }
        }

        $sort = $request->sortParam('-valid_from');
        $column = ltrim($sort, '-') === 'created_at' ? 'created_at' : 'valid_from';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';

        $page = $list->listing($this->decision($request), $actor, $filters)
            ->orderBy($column, $direction)
            ->orderBy('id', $direction)
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        $this->prepare($page->items(), $actor, $refs, $list, $permissions, $view);

        return PagePaginatedResponse::make($page, PickupAuthorizationResource::class);
    }

    public function store(StorePickupAuthorizationRequest $request, PickupAuthorizationService $service, PickupListService $list, FamilyRefs $refs, PermissionResolver $permissions, PickupViewContext $view): JsonResponse
    {
        $actor = $this->actor($request);
        $authorization = $service->create($request->payload(), $actor, ['ip' => $request->ip(), 'user_agent' => $request->userAgent()]);
        $this->prepare([$authorization], $actor, $refs, $list, $permissions, $view);

        return (new PickupAuthorizationResource($authorization))
            ->response()
            ->setStatusCode(201)
            ->header('Location', url("/api/v1/pickup-authorizations/{$authorization->public_id}"));
    }

    public function show(Request $request, string $publicId, PickupListService $list, FamilyRefs $refs, PermissionResolver $permissions, PickupViewContext $view): PickupAuthorizationResource
    {
        $actor = $this->actor($request);
        $authorization = $list->findScoped($publicId, $this->decision($request), $actor);
        $this->prepare([$authorization], $actor, $refs, $list, $permissions, $view);

        return new PickupAuthorizationResource($authorization);
    }

    public function update(UpdatePickupAuthorizationRequest $request, string $publicId, PickupAuthorizationService $service, PickupListService $list, FamilyRefs $refs, PermissionResolver $permissions, PickupViewContext $view): PickupAuthorizationResource
    {
        $list->find($publicId);
        $authorization = $service->updateValidUntil($publicId, $request->payload()['valid_until']);
        $this->prepare([$authorization], $this->actor($request), $refs, $list, $permissions, $view);

        return new PickupAuthorizationResource($authorization);
    }

    public function withdraw(Request $request, string $publicId, PickupAuthorizationService $service, PickupListService $list, FamilyRefs $refs, PermissionResolver $permissions, PickupViewContext $view): PickupAuthorizationResource
    {
        $actor = $this->actor($request);
        $list->find($publicId);
        $authorization = $service->withdraw($publicId, $actor);
        $this->prepare([$authorization], $actor, $refs, $list, $permissions, $view);

        return new PickupAuthorizationResource($authorization);
    }

    /**
     * Precarga los nombres, las claves públicas y la vigencia calculada que necesitan las representaciones.
     *
     * @param  iterable<PickupAuthorization>  $rows
     */
    private function prepare(iterable $rows, User $actor, FamilyRefs $refs, PickupListService $list, PermissionResolver $permissions, PickupViewContext $view): void
    {
        $rows = is_array($rows) ? $rows : iterator_to_array($rows, false);

        $refs->preloadPersons(array_merge(
            array_map(static fn (PickupAuthorization $a): int => (int) $a->student_person_id, $rows),
            array_map(static fn (PickupAuthorization $a): int => (int) $a->person_id, $rows),
        ));
        $refs->preloadGuardians(array_map(static fn (PickupAuthorization $a): int => (int) $a->authorized_by_guardian_id, $rows));
        $refs->preloadActors(array_map(static fn (PickupAuthorization $a): int => (int) $a->recorded_by, $rows));

        $view->effectiveness = $list->effectivenessOf($rows);
        $view->showReason = $permissions->decide($actor, 'autorizado_recogida.leer')->isUnrestricted();
        $view->showExcluded = $permissions->decide($actor, 'revocacion_tutor.leer')->permitted;
    }
}
