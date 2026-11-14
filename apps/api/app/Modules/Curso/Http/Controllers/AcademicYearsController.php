<?php

namespace App\Modules\Curso\Http\Controllers;

use App\Models\User;
use App\Modules\Curso\Application\AcademicYearAdministration;
use App\Modules\Curso\Application\AcademicYearTransitions;
use App\Modules\Curso\Application\CursoErrors;
use App\Modules\Curso\Domain\AcademicYearContext;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Modules\Curso\Domain\Models\AcademicYear;
use App\Modules\Curso\Http\Requests\IndexAcademicYearsRequest;
use App\Modules\Curso\Http\Requests\ReopenAcademicYearRequest;
use App\Modules\Curso\Http\Requests\StoreAcademicYearRequest;
use App\Modules\Curso\Http\Requests\TransitionAcademicYearRequest;
use App\Modules\Curso\Http\Requests\UpdateAcademicYearRequest;
use App\Modules\Curso\Http\Resources\AcademicYearResource;
use App\Modules\Curso\Infrastructure\PermissionAcademicYearReadAccess;
use App\Support\Api\ApiException;
use App\Support\Api\PagePaginatedResponse;
use App\Support\Authorization\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * REQ-CURSO/api.md §2 (paso 1.10, `REQ-CURSO-001`). Siete endpoints, con el de
 * reapertura de 1.11c (`ADR-059`); sin `DELETE` ni transición a `archivado`
 * (api.md §2, tabla «no existen»). Todos los accesos pasan por `TenantScope` (`INV-001`): un
 * `public_id` de otro centro es un `404`, nunca un `403`.
 */
class AcademicYearsController extends Controller
{
    public function index(IndexAcademicYearsRequest $request): JsonResponse
    {
        $sort = (string) $request->string('sort', '-starts_on');
        $column = ltrim($sort, '-');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';

        $query = AcademicYear::query()
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', explode(',', (string) $request->string('status'))))
            ->orderBy($column, $direction)
            // Desempate determinista (ADR-038 §5.3).
            ->orderByDesc('id');

        return PagePaginatedResponse::make($query->paginate($request->integer('per_page', 25))->withQueryString(), AcademicYearResource::class);
    }

    /**
     * `OPEN-CURSO-15`: autoservicio sin permiso (como `GET /me`). El código y
     * las fechas del curso activo no son datos personales. `404
     * curso.no_active_year` cuando no hay curso activo (estado legítimo
     * entre un cierre y la siguiente activación, `funcional.md §4.4`).
     */
    public function current(Request $request, AcademicYearContext $context): AcademicYearResource
    {
        // Sin `permission:` la ruta no autentica sola: «autoservicio» exige
        // sesión (`401` sin ella), no es una ruta pública.
        if (! $request->user() instanceof User) {
            throw ApiException::unauthenticated();
        }

        $active = $context->active();

        if ($active === null) {
            throw CursoErrors::noActiveYear();
        }

        return new AcademicYearResource(AcademicYear::query()->findOrFail($active->id));
    }

    /**
     * `OPEN-ACAD-11`, issue #409, `CA-ACAD-043`: las opciones del selector de
     * curso académico, sin exigir `curso_academico.leer` (que casi ningún rol
     * tiene). Siempre `activo` y `planificacion`; con `curso_historico.leer`
     * (el mismo criterio de `AcademicYearReadAccess`, `RN-CURSO-25`) también
     * `cerrado` y `archivado`. Sin paginar (decenas de filas por centro),
     * orden `-starts_on`. El filtrado de centro lo hace `TenantScope`.
     */
    public function selectable(Request $request, PermissionResolver $permissions): JsonResponse
    {
        $user = $request->user();

        // Autoservicio sin `permission:`: exige sesión (`401`), no es pública.
        if (! $user instanceof User) {
            throw ApiException::unauthenticated();
        }

        $statuses = [AcademicYearStatus::Activo, AcademicYearStatus::Planificacion];

        if ($permissions->can($user, PermissionAcademicYearReadAccess::HISTORY_PERMISSION)) {
            $statuses = AcademicYearStatus::cases();
        }

        $years = AcademicYear::query()
            ->whereIn('status', $statuses)
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => AcademicYearResource::collection($years)->resolve()]);
    }

    public function show(string $publicId): AcademicYearResource
    {
        return new AcademicYearResource($this->findOrFail($publicId));
    }

    public function store(StoreAcademicYearRequest $request, AcademicYearAdministration $administration): JsonResponse
    {
        $year = $administration->create($request->academicYearInput());

        return (new AcademicYearResource($year))
            ->response()
            ->setStatusCode(201)
            ->header('Location', url("/api/v1/academic-years/{$year->public_id}"));
    }

    public function update(UpdateAcademicYearRequest $request, string $publicId, AcademicYearAdministration $administration): AcademicYearResource
    {
        $year = $this->findOrFail($publicId);

        /** @var array{code?: mixed, starts_on?: string, ends_on?: string} $input */
        $input = $request->validated();

        return new AcademicYearResource($administration->update($year, $input));
    }

    public function updateStatus(TransitionAcademicYearRequest $request, string $publicId, AcademicYearTransitions $transitions): AcademicYearResource
    {
        // 404 antes que cualquier regla de transición (`INV-001`).
        $this->findOrFail($publicId);

        $target = AcademicYearStatus::from($request->string('status')->value());

        return new AcademicYearResource($transitions->transition($publicId, $target));
    }

    /**
     * `ADR-059`, `RN-CURSO-40` a `-48`: reapertura de un curso `cerrado`.
     */
    public function reopen(ReopenAcademicYearRequest $request, string $publicId, AcademicYearTransitions $transitions): AcademicYearResource
    {
        // 404 antes que cualquier regla de transición (`INV-001`). La validación
        // de `reason` (422) precede al 404 por el FormRequest; sin fuga: no
        // depende de si el curso existe ni de su tenant.
        $this->findOrFail($publicId);

        return new AcademicYearResource($transitions->reopen($publicId, $request->reason()));
    }

    private function findOrFail(string $publicId): AcademicYear
    {
        return AcademicYear::query()->where('public_id', $publicId)->firstOrFail();
    }
}
