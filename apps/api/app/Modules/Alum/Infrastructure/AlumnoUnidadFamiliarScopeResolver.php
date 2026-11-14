<?php

namespace App\Modules\Alum\Infrastructure;

use App\Models\User;
use App\Modules\Alum\Domain\Models\Student;
use App\Modules\Core\Domain\PersonDirectory;
use App\Modules\FamUnit\Domain\FamilyMembership;
use App\Support\Authorization\Contracts\ScopeResolver;
use App\Support\Authorization\Scope;
use Illuminate\Database\Eloquent\Builder;

/**
 * REQ-ALUM/permisos.md §3.1 (1.15-a, `RN-ALUM-62`, `RN-ALUM-63`, `ADR-044 §3.4`, `ADR-063 §5.2`): `unidad_familiar`
 * sobre el recurso `alumno`. Alumnos cuya persona alcanza el sujeto por un vínculo **activo** (contrato público
 * `FamilyMembership::studentPersonIdsReachedBy()`, sin categoría). Sujeto sin persona, sin faceta de tutor o sin
 * vínculos activos ⇒ ninguna fila (deniega). Sin caché entre peticiones: cada llamada lee el estado del momento
 * (`RN-ALUM-63`). Un modelo inesperado también falla en cerrado.
 *
 * Lo registra `AlumServiceProvider::boot()`; el núcleo nunca importa esta clase (`INV-007`). Entra en el mapa cerrado
 * de `AR-10` con la aprobación de la especificación.
 */
final class AlumnoUnidadFamiliarScopeResolver implements ScopeResolver
{
    public function scope(): Scope
    {
        return Scope::UnidadFamiliar;
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

        // Perezoso (no por constructor): el resolutor se registra en el arranque y no debe construir servicios entonces
        // (lección de 1.12-b, `CA-PERM-030`).
        $personId = app(PersonDirectory::class)->personIdForUser((int) $subject->id);

        if ($personId === null) {
            return $query->whereRaw('1 = 0');
        }

        $reached = app(FamilyMembership::class)->studentPersonIdsReachedBy($personId);

        return $reached === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn($model->qualifyColumn('person_id'), $reached);
    }
}
