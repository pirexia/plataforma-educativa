<?php

namespace App\Modules\Alum\Infrastructure;

use App\Models\User;
use App\Modules\Alum\Application\StudentService;
use App\Modules\Alum\Domain\Models\Enrollment;
use App\Modules\Core\Domain\PersonDirectory;
use App\Modules\FamUnit\Domain\FamilyMembership;
use App\Support\Authorization\Contracts\ScopeResolver;
use App\Support\Authorization\Scope;
use Illuminate\Database\Eloquent\Builder;

/**
 * REQ-ALUM/permisos.md §3.1 (1.15-b T2a, `RN-ALUM-62`, `RN-ALUM-63`): `unidad_familiar` sobre el recurso `matricula`.
 * Matrículas de los alumnos que la persona del sujeto alcanza por un vínculo **activo** (contrato público
 * `FamilyMembership::studentPersonIdsReachedBy()`, sin categoría), con la misma definición que
 * `AlumnoUnidadFamiliarScopeResolver`. Sujeto sin persona, sin faceta de tutor o sin vínculos activos ⇒ ninguna fila.
 * Sin caché entre peticiones. Un modelo inesperado también falla en cerrado.
 *
 * Sobre `GroupChangeRequest` (1.15-c) este ámbito resolverá a conjunto vacío (`RN-ALUM-46`): hoy cualquier modelo que
 * no sea `Enrollment` ya da vacío.
 *
 * No consulta `Student` (`AR-10`): pide la subconsulta de alumnos al servicio sancionado, de forma perezosa.
 */
final class MatriculaUnidadFamiliarScopeResolver implements ScopeResolver
{
    public function scope(): Scope
    {
        return Scope::UnidadFamiliar;
    }

    public function resource(): string
    {
        return 'matricula';
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

        if (! $model instanceof Enrollment) {
            return $query->whereRaw('1 = 0');
        }

        $personId = app(PersonDirectory::class)->personIdForUser((int) $subject->id);

        if ($personId === null) {
            return $query->whereRaw('1 = 0');
        }

        $reached = app(FamilyMembership::class)->studentPersonIdsReachedBy($personId);

        return $reached === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn($model->qualifyColumn('student_id'), app(StudentService::class)->idsQueryOfPersons($reached));
    }
}
