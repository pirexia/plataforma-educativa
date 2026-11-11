<?php

namespace App\Modules\Alum\Infrastructure;

use App\Models\User;
use App\Modules\Acad\Domain\TeachingMembership;
use App\Modules\Alum\Application\EnrollmentService;
use App\Modules\Alum\Domain\Models\Student;
use App\Modules\Curso\Domain\AcademicYearContext;
use App\Support\Authorization\Contracts\ScopeResolver;
use App\Support\Authorization\Scope;
use Illuminate\Database\Eloquent\Builder;

/**
 * REQ-ALUM/permisos.md §3.1 (1.15-b T2a, `RN-ALUM-61`, `OPEN-ALUM-17`, `RN-ALUM-63`): `grupo` sobre el recurso
 * `alumno`. Alumnos con matrícula `activa` viva en un grupo de `groupIdsFor(u, Y)`, con `Y` el curso activo de
 * `AcademicYearContext`. Sin curso activo, sin asignación docente o sin matrículas en esos grupos ⇒ ninguna fila
 * (deniega). Sin caché entre peticiones. Un modelo inesperado también falla en cerrado.
 *
 * No consulta `Student` ni `Enrollment` (`AR-10`): pide la subconsulta de matrículas al servicio sancionado, de forma
 * perezosa (`CA-PERM-030`). Lo registra `AlumServiceProvider::boot()`.
 */
final class AlumnoGrupoScopeResolver implements ScopeResolver
{
    public function scope(): Scope
    {
        return Scope::Grupo;
    }

    public function resource(): string
    {
        return 'alumno';
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function constrain(Builder $query, User $subject): Builder
    {
        $model = $query->getModel();

        if (! $model instanceof Student) {
            return $query->whereRaw('1 = 0');
        }

        $year = app(AcademicYearContext::class)->active();

        if ($year === null) {
            return $query->whereRaw('1 = 0');
        }

        $groupIds = app(TeachingMembership::class)->groupIdsFor((int) $subject->id, $year->id);

        return $groupIds === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn($model->qualifyColumn('id'), app(EnrollmentService::class)->activeStudentIdsInGroups($groupIds, $year->id));
    }
}
