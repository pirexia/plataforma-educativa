<?php

namespace App\Modules\FamUnit\Infrastructure;

use App\Models\User;
use App\Modules\FamUnit\Application\FamilyScope;
use App\Modules\FamUnit\Domain\Models\GuardianStudentLink;
use App\Support\Authorization\Contracts\ScopeResolver;
use App\Support\Authorization\Scope;
use Illuminate\Database\Eloquent\Builder;

/**
 * REQ-FAM-UNIT/permisos.md §3.1 (1.14-a, `ADR-044 §3.4`): `unidad_familiar` sobre el recurso `vinculo_tutor`. El sujeto
 * alcanza los vínculos **activos** cuyo alumno alcanza él mismo (`FamilyScope`): ve a los demás tutores activos de
 * sus hijos, **nunca** un vínculo revocado, ni el suyo (`RN-FAM-UNIT-36`). Sujeto sin faceta de tutor, o sin vínculos
 * activos ⇒ ninguna fila. Sin caché entre peticiones (`RN-FAM-UNIT-34`).
 */
final class VinculoTutorUnidadFamiliarScopeResolver implements ScopeResolver
{
    public function scope(): Scope
    {
        return Scope::UnidadFamiliar;
    }

    public function resource(): string
    {
        return 'vinculo_tutor';
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function constrain(Builder $query, User $subject): Builder
    {
        $reach = app(FamilyScope::class)->reachableStudentIds($subject);

        if ($reach === null || ! $query->getModel() instanceof GuardianStudentLink) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where($query->getModel()->qualifyColumn('status'), 'activo')
            ->whereIn($query->getModel()->qualifyColumn('student_person_id'), $reach);
    }
}
