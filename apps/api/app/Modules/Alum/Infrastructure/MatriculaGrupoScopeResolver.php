<?php

namespace App\Modules\Alum\Infrastructure;

use App\Models\User;
use App\Modules\Alum\Application\EnrollmentService;
use App\Modules\Alum\Domain\Models\Enrollment;
use App\Support\Authorization\Contracts\ScopeResolver;
use App\Support\Authorization\Scope;
use Illuminate\Database\Eloquent\Builder;

/**
 * REQ-ALUM/permisos.md §3.1 (1.15-b T2a, `RN-ALUM-60`, `RN-ALUM-63`, `ADR-044 §3.4`, `ADR-063 §5.2`): `grupo` sobre el
 * recurso `matricula`. Matrículas cuyo `group_id` está en `TeachingMembership::groupIdsFor(u, curso de la matrícula)`.
 *
 * El contrato `constrain(Builder, User)` no lleva el curso consultado, y no hace falta: un grupo pertenece a un solo
 * curso académico, así que «grupo de `groupIdsFor(u, curso de la fila)`» es lo mismo que «grupo de la unión de
 * `groupIdsFor(u, Y)` para cada `Y` con matrículas con grupo» (la calcula `EnrollmentService::groupIdsOf()`). Sin
 * asignación docente ⇒ ninguna fila (deniega). Sin caché entre peticiones. Un modelo inesperado también falla en cerrado.
 *
 * No consulta `Enrollment` (`AR-10`): delega en el servicio sancionado, de forma perezosa (el resolutor se registra en
 * el arranque y no debe construir servicios entonces, `CA-PERM-030`). Lo registra `AlumServiceProvider::boot()`.
 */
final class MatriculaGrupoScopeResolver implements ScopeResolver
{
    public function scope(): Scope
    {
        return Scope::Grupo;
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

        $groupIds = app(EnrollmentService::class)->groupIdsOf($subject);

        return $groupIds === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn($model->qualifyColumn('group_id'), $groupIds);
    }
}
