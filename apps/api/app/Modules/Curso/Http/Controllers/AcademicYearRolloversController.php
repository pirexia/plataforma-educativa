<?php

namespace App\Modules\Curso\Http\Controllers;

use App\Models\User;
use App\Modules\Curso\Application\CursoErrors;
use App\Modules\Curso\Application\Rollover\RolloverCancellation;
use App\Modules\Curso\Application\Rollover\RolloverCatalog;
use App\Modules\Curso\Application\Rollover\RolloverPlanner;
use App\Modules\Curso\Application\Rollover\RolloverResolver;
use App\Modules\Curso\Application\Rollover\RolloverService;
use App\Modules\Curso\Domain\AcademicYearContext;
use App\Modules\Curso\Domain\AcademicYearReadAccess;
use App\Modules\Curso\Domain\Models\AcademicYear;
use App\Modules\Curso\Domain\Models\YearRollover;
use App\Modules\Curso\Http\Requests\IndexRolloversRequest;
use App\Modules\Curso\Http\Requests\PreviewRolloverRequest;
use App\Modules\Curso\Http\Requests\StoreRolloverRequest;
use App\Modules\Curso\Http\Resources\AcademicYearRolloverResource;
use App\Modules\Curso\Http\Resources\RolloverPreviewResource;
use App\Support\Api\ApiException;
use App\Support\Api\PagePaginatedResponse;
use App\Support\Authorization\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * REQ-CURSO/api.md §2.1 (1.12b, `REQ-CURSO-002`): apertura del curso siguiente. Siete *endpoints* bajo
 * `/academic-year-rollovers`. Todos los accesos pasan por `TenantScope` (`INV-001`): un `public_id` de otro
 * centro es un `404`, nunca un `403`. Módulo esencial: sin `module-enabled:`.
 */
class AcademicYearRolloversController extends Controller
{
    /** `GET /academic-year-rollovers/elements` (`RN-CURSO-62`, `-78`): el catálogo de los módulos utilizables. */
    public function elements(Request $request, RolloverCatalog $catalog, PermissionResolver $permissions): JsonResponse
    {
        $user = $this->actor($request);
        $data = [];

        foreach ($catalog->elements() as $element) {
            $data[] = [
                'code' => $element->code,
                'module' => $element->moduleCode(),
                'label' => (string) __($element->labelKey),
                'requires' => $element->requires,
                'allowed' => $permissions->can($user, $element->createPermission),
                'bundle' => $element->bundle,
            ];
        }

        return response()->json(['data' => $data]);
    }

    /** `GET /academic-year-rollovers/preview` (`RN-CURSO-65`): sin efectos, ni escribe ni bloquea filas. */
    public function preview(PreviewRolloverRequest $request, RolloverResolver $resolver, RolloverPlanner $planner): RolloverPreviewResource
    {
        $resolved = $resolver->resolve($this->actor($request), $request->rolloverRequest());

        return new RolloverPreviewResource($planner->preview($resolved, includeExisting: true));
    }

    /** `POST /academic-year-rollovers` (`RN-CURSO-68`): `202`, el trabajo se encola tras confirmar. */
    public function store(StoreRolloverRequest $request, RolloverService $service): JsonResponse
    {
        $rollover = $service->request($this->actor($request), $request->rolloverRequest());

        return (new AcademicYearRolloverResource($rollover))
            ->response()
            ->setStatusCode(202)
            ->header('Location', url("/api/v1/academic-year-rollovers/{$rollover->public_id}"));
    }

    /** `GET /academic-year-rollovers` (`RN-CURSO-80`). */
    public function index(IndexRolloversRequest $request, AcademicYearContext $context, AcademicYearReadAccess $readAccess): JsonResponse
    {
        $user = $this->actor($request);

        $targetPublicId = $request->query('academic_year');

        if (is_string($targetPublicId) && $targetPublicId !== '') {
            $target = AcademicYear::query()->where('public_id', $targetPublicId)->first() ?? throw ApiException::notFound();
        } else {
            $active = $context->active() ?? throw CursoErrors::noActiveYear();
            $target = AcademicYear::query()->findOrFail($active->id);
        }

        // Destino cerrado/archivado sin `curso_historico.leer` ⇒ `404` (RN-CURSO-25, -33, -80).
        $readAccess->assertCanRead($target->toSummary(), $user);

        $query = YearRollover::query()->with(['academicYear', 'sourceAcademicYear'])->where('academic_year_id', $target->id);

        $sourcePublicId = $request->query('source');

        if (is_string($sourcePublicId) && $sourcePublicId !== '') {
            $source = AcademicYear::query()->where('public_id', $sourcePublicId)->first() ?? throw ApiException::notFound();
            $query->where('source_academic_year_id', $source->id);
        }

        if ($request->filled('status')) {
            $query->whereIn('status', explode(',', (string) $request->string('status')));
        }

        $direction = (string) $request->string('sort', '-created_at') === 'created_at' ? 'asc' : 'desc';
        $query->orderBy('created_at', $direction)->orderByDesc('id');

        return PagePaginatedResponse::make($query->paginate($request->integer('per_page', 25))->withQueryString(), AcademicYearRolloverResource::class);
    }

    /** `GET /academic-year-rollovers/{public_id}` (`RN-CURSO-80`). */
    public function show(Request $request, string $publicId, RolloverService $service): AcademicYearRolloverResource
    {
        return new AcademicYearRolloverResource($service->findReadable($publicId, $this->actor($request)));
    }

    /** `POST /academic-year-rollovers/{public_id}/revert` (`RN-CURSO-74`): `202`. */
    public function revert(Request $request, string $publicId, RolloverService $service): JsonResponse
    {
        $rollover = $service->requestRevert($this->actor($request), $publicId);

        return (new AcademicYearRolloverResource($rollover))->response()->setStatusCode(202);
    }

    /** `POST /academic-year-rollovers/{public_id}/cancel` (`RN-CURSO-85`): `200`, no encola nada. */
    public function cancel(Request $request, string $publicId, RolloverCancellation $cancellation): AcademicYearRolloverResource
    {
        return new AcademicYearRolloverResource($cancellation->cancel($publicId, $this->actor($request)));
    }

    private function actor(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw ApiException::unauthenticated();
        }

        return $user;
    }
}
