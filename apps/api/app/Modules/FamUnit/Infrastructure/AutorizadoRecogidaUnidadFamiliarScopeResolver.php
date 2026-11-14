<?php

namespace App\Modules\FamUnit\Infrastructure;

use App\Models\User;
use App\Modules\FamUnit\Application\FamilyScope;
use App\Modules\FamUnit\Domain\Models\PickupAuthorization;
use App\Support\Authorization\Contracts\ScopeResolver;
use App\Support\Authorization\Scope;
use Illuminate\Database\Eloquent\Builder;

/**
 * REQ-FAM-UNIT/permisos.md §8.3 (1.14b-a, `ADR-044 §3.4`, `ADR-068 §6` `L1`): `unidad_familiar` sobre el recurso
 * `autorizado_recogida`. El sujeto alcanza a los alumnos con **vínculo activo** con su faceta de tutor (`FamilyScope`,
 * `funcional.md §3.6`):
 *
 * - `PickupAuthorization`: las autorizaciones de esos alumnos (`RN-FAM-UNIT-91`). La familia no ve el documento de los
 *   terceros ni el motivo (lo decide la representación); un tutor revocado no alcanza al alumno, así que es `404`
 *   (`RN-FAM-UNIT-36`).
 * - `PickupPhoto`: **ninguna** (`OPEN-FAM-UNIT-43`: que la familia vea la fotografía de un tercero sigue abierta, fallo en
 *   cerrado).
 *
 * Sujeto sin faceta de tutor o sin vínculos activos ⇒ ninguna fila (deniega); un modelo inesperado también falla en
 * cerrado. Sin caché entre peticiones (`RN-FAM-UNIT-34`). Lo registra `FamUnitServiceProvider::boot()`; el núcleo nunca
 * importa esta clase (`INV-007`).
 */
final class AutorizadoRecogidaUnidadFamiliarScopeResolver implements ScopeResolver
{
    public function scope(): Scope
    {
        return Scope::UnidadFamiliar;
    }

    public function resource(): string
    {
        return 'autorizado_recogida';
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

        if (! $model instanceof PickupAuthorization) {
            return $query->whereRaw('1 = 0');
        }

        // Perezoso (no por constructor): el resolutor se registra en el arranque y no debe construir servicios entonces.
        $reach = app(FamilyScope::class)->reachableStudentIds($subject);

        return $reach === null ? $query->whereRaw('1 = 0') : $query->whereIn($model->qualifyColumn('student_person_id'), $reach);
    }
}
