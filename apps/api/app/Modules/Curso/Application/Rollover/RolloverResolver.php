<?php

namespace App\Modules\Curso\Application\Rollover;

use App\Models\User;
use App\Modules\Curso\Application\CursoErrors;
use App\Modules\Curso\Domain\AcademicYearReadAccess;
use App\Modules\Curso\Domain\AcademicYearSharedLock;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Modules\Curso\Domain\AcademicYearSummary;
use App\Modules\Curso\Domain\Models\AcademicYear;
use App\Support\Api\ApiException;
use App\Support\Authorization\PermissionResolver;

/**
 * REQ-CURSO/funcional.md `RN-CURSO-63`, `-64`, `-78`, `-82` (1.12b): valida una petición de
 * simulación o de copia y la resuelve a un `ResolvedRollover`. Orden de las comprobaciones
 * (todas antes de escribir nada): destino (`422` requerido / no existe, `409` si no está en
 * `planificacion`), origen (`422`), selección (`422`) y permisos por elemento (`403`).
 *
 * Con `$lockTarget` el destino se lee con `FOR SHARE` (`AcademicYearSharedLock`), como primer
 * bloqueo de la transacción de la solicitud (`RN-CURSO-68` paso 1); sin él, es una lectura
 * ordinaria (la simulación no toma bloqueos de fila, `RN-CURSO-65`).
 */
final class RolloverResolver
{
    /** Único paquete que existe (`RN-CURSO-82`): `curso` solo conoce su código. */
    public const TEACHING_ASSIGNMENTS_BUNDLE = 'teaching_assignments';

    public function __construct(
        private readonly RolloverCatalog $catalog,
        private readonly AcademicYearSharedLock $sharedLock,
        private readonly AcademicYearReadAccess $readAccess,
        private readonly PermissionResolver $permissions,
    ) {}

    public function resolve(User $actor, RolloverRequest $request, bool $lockTarget = false): ResolvedRollover
    {
        $target = $this->resolveTarget($request, $lockTarget);
        $source = $this->resolveSource($actor, $request, $target);
        $effective = $this->resolveSelection($request);
        $this->assertAllowed($actor, $effective);

        return new ResolvedRollover($target, $source, $effective, $request->copyTeachingAssignments);
    }

    private function resolveTarget(RolloverRequest $request, bool $lockTarget): AcademicYearSummary
    {
        if ($request->target === null || trim($request->target) === '') {
            throw CursoErrors::validation(['academic_year' => [CursoErrors::item('curso.validation.rollover_target_required')]]);
        }

        $id = AcademicYear::query()->where('public_id', $request->target)->value('id');
        $summary = null;

        if ($id !== null) {
            $summary = $lockTarget
                ? $this->sharedLock->lockForShareById((int) $id)
                : AcademicYear::query()->find($id)?->toSummary();
        }

        if ($summary === null) {
            throw CursoErrors::validation(['academic_year' => [CursoErrors::item('curso.validation.rollover_target_not_found')]]);
        }

        if ($summary->status !== AcademicYearStatus::Planificacion) {
            throw CursoErrors::conflict('curso.conflict.rollover_target_not_planning');
        }

        return $summary;
    }

    private function resolveSource(User $actor, RolloverRequest $request, AcademicYearSummary $target): AcademicYearSummary
    {
        if ($request->source === null || trim($request->source) === '') {
            throw CursoErrors::validation(['source' => [CursoErrors::item('curso.validation.rollover_source_required')]]);
        }

        $source = AcademicYear::query()->where('public_id', $request->source)->first()?->toSummary();

        // Inexistente, de otro centro, o cerrado sin `curso_historico.leer`: indistinguibles
        // (RN-CURSO-63, RN-CURSO-25).
        if ($source === null || ! $this->readAccess->canRead($source, $actor)) {
            throw CursoErrors::validation(['source' => [CursoErrors::item('curso.validation.rollover_source_not_found')]]);
        }

        if ($source->status === AcademicYearStatus::Planificacion
            || $source->id === $target->id
            || ! $source->startsOn->lessThan($target->startsOn)) {
            throw CursoErrors::validation(['source' => [CursoErrors::item('curso.validation.rollover_source_invalid')]]);
        }

        return $source;
    }

    /**
     * RN-CURSO-64, RN-CURSO-82: la selección efectiva, en el orden del catálogo.
     *
     * @return list<string>
     */
    private function resolveSelection(RolloverRequest $request): array
    {
        $requested = array_values(array_unique($request->elements));

        if ($requested === []) {
            throw CursoErrors::validation(['elements' => [CursoErrors::item('curso.validation.rollover_elements_required')]]);
        }

        $catalog = $this->catalog->elements();

        foreach ($requested as $code) {
            $element = $catalog[$code] ?? null;

            if ($element === null) {
                throw CursoErrors::validation(['elements' => [CursoErrors::item('curso.validation.rollover_element_unknown', ['element' => $code])]]);
            }

            if ($element->bundle !== null) {
                throw CursoErrors::validation(['elements' => [CursoErrors::item('curso.validation.rollover_element_not_selectable', ['element' => $code])]]);
            }
        }

        $selected = array_fill_keys($requested, true);

        if ($request->copyTeachingAssignments) {
            $bundled = array_filter($catalog, static fn ($element): bool => $element->bundle === self::TEACHING_ASSIGNMENTS_BUNDLE);

            if ($bundled === []) {
                throw CursoErrors::validation(['copy_teaching_assignments' => [CursoErrors::item('curso.validation.rollover_teaching_assignments_unavailable')]]);
            }

            foreach (array_keys($bundled) as $code) {
                $selected[$code] = true;
            }
        }

        $effective = array_values(array_filter(array_keys($catalog), static fn (string $code): bool => isset($selected[$code])));

        foreach ($effective as $code) {
            foreach ($catalog[$code]->requires as $required) {
                if (! isset($selected[$required])) {
                    throw CursoErrors::validation(['elements' => [CursoErrors::item('curso.validation.rollover_element_requires', ['element' => $code, 'requires' => $required])]]);
                }
            }
        }

        return $effective;
    }

    /**
     * RN-CURSO-78: además de `apertura_curso_academico.crear` (middleware), el permiso de alta de cada
     * elemento de la selección efectiva. `403` con una entrada por elemento sin permiso.
     *
     * @param  list<string>  $effective
     */
    private function assertAllowed(User $actor, array $effective): void
    {
        $catalog = $this->catalog->elements();
        $forbidden = [];

        foreach ($effective as $code) {
            if (! $this->permissions->can($actor, $catalog[$code]->createPermission)) {
                $forbidden[] = CursoErrors::item('curso.rollover.element_forbidden', ['element' => $code]);
            }
        }

        if ($forbidden !== []) {
            throw ApiException::forbidden(null, [], ['elements' => $forbidden]);
        }
    }
}
