<?php

namespace App\Modules\FamUnit\Infrastructure;

use App\Models\User;
use App\Modules\FamUnit\Application\FamilyScope;
use App\Modules\FamUnit\Application\FamilyUnitStudentService;
use App\Modules\FamUnit\Application\GuardianLinkService;
use App\Modules\FamUnit\Domain\Models\FamilyUnit;
use App\Modules\FamUnit\Domain\Models\FamilyUnitStudent;
use App\Modules\FamUnit\Domain\Models\Guardian;
use App\Support\Authorization\Contracts\ScopeResolver;
use App\Support\Authorization\Scope;
use Illuminate\Database\Eloquent\Builder;

/**
 * REQ-FAM-UNIT/permisos.md §3.1 (1.14-a, `ADR-044 §3.4`, `ADR-063 §5.2`): `unidad_familiar` sobre el recurso `familia`.
 * Un solo resolutor para los tres modelos del recurso; lo que alcanza el sujeto sale de sus vínculos **activos**
 * (`FamilyScope`, `funcional.md §3.6`):
 *
 * - `FamilyUnit`: unidades con al menos un alumno que el sujeto alcanza.
 * - `Guardian`: tutores con vínculo activo con alguno de esos alumnos.
 * - `FamilyUnitStudent`: las pertenencias de esos alumnos.
 *
 * Sujeto sin faceta de tutor, o sin vínculos activos ⇒ ninguna fila (deniega). Un modelo inesperado también falla en
 * cerrado. Sin caché entre peticiones (`RN-FAM-UNIT-34`). Lo registra `FamUnitServiceProvider::boot()`; el núcleo
 * nunca importa esta clase (`INV-007`). Entra en el mapa cerrado de `AR-10` con la aprobación de la especificación.
 */
final class FamiliaUnidadFamiliarScopeResolver implements ScopeResolver
{
    public function scope(): Scope
    {
        return Scope::UnidadFamiliar;
    }

    public function resource(): string
    {
        return 'familia';
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function constrain(Builder $query, User $subject): Builder
    {
        // Perezoso (no por constructor): el resolutor se registra en el arranque y no debe construir servicios entonces
        // (lección de 1.12-b, `CA-PERM-030`).
        $reach = app(FamilyScope::class)->reachableStudentIds($subject);

        if ($reach === null) {
            return $query->whereRaw('1 = 0');
        }

        $model = $query->getModel();

        return match (true) {
            $model instanceof FamilyUnit => $query->whereIn($model->getQualifiedKeyName(), app(FamilyUnitStudentService::class)->unitIdsOfStudents($reach)),
            $model instanceof Guardian => $query->whereIn($model->getQualifiedKeyName(), app(GuardianLinkService::class)->guardianIdsOfStudents($reach)),
            $model instanceof FamilyUnitStudent => $query->whereIn($model->qualifyColumn('student_person_id'), $reach),
            default => $query->whereRaw('1 = 0'),
        };
    }
}
