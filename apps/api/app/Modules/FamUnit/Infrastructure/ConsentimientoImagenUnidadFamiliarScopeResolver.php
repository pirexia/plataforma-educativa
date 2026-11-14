<?php

namespace App\Modules\FamUnit\Infrastructure;

use App\Models\User;
use App\Modules\FamUnit\Application\FamilyScope;
use App\Modules\FamUnit\Domain\Models\FamilyUnitStudent;
use App\Modules\FamUnit\Domain\Models\ImageConsentDecision;
use App\Support\Authorization\Contracts\ScopeResolver;
use App\Support\Authorization\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * REQ-FAM-UNIT/permisos.md §3.1 (1.14-b, `ADR-044 §3.4`): `unidad_familiar` sobre el recurso `consentimiento_imagen`. El
 * sujeto alcanza a los alumnos con **vínculo activo** con su faceta de tutor (`FamilyScope`, `funcional.md §3.6`):
 *
 * - `ImageConsentDecision` (histórico): las decisiones de esos alumnos, **sin** las de tutores cuyo vínculo con ese
 *   alumno está revocado (`RN-FAM-UNIT-71`): cuentan para el estado, pero mostrarlas revelaría la existencia de esa
 *   persona y su relación con el centro (`RN-FAM-UNIT-36`).
 * - `FamilyUnitStudent` (listado de estados): los alumnos que el sujeto alcanza.
 *
 * Sujeto sin faceta de tutor, o sin vínculos activos ⇒ ninguna fila (deniega); un modelo inesperado también falla en
 * cerrado. Sin caché entre peticiones (`RN-FAM-UNIT-34`). Lo registra `FamUnitServiceProvider::boot()`; el núcleo nunca
 * importa esta clase (`INV-007`).
 */
final class ConsentimientoImagenUnidadFamiliarScopeResolver implements ScopeResolver
{
    public function scope(): Scope
    {
        return Scope::UnidadFamiliar;
    }

    public function resource(): string
    {
        return 'consentimiento_imagen';
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function constrain(Builder $query, User $subject): Builder
    {
        // Perezoso (no por constructor): el resolutor se registra en el arranque y no debe construir servicios entonces.
        $reach = app(FamilyScope::class)->reachableStudentIds($subject);

        if ($reach === null) {
            return $query->whereRaw('1 = 0');
        }

        $model = $query->getModel();

        if ($model instanceof FamilyUnitStudent) {
            return $query->whereIn($model->qualifyColumn('student_person_id'), $reach);
        }

        if ($model instanceof ImageConsentDecision) {
            $guardian = $model->qualifyColumn('guardian_id');
            $student = $model->qualifyColumn('student_person_id');

            return $query
                ->whereIn($student, $reach)
                ->whereNotExists(function (QueryBuilder $revoked) use ($model, $guardian, $student): void {
                    $revoked->selectRaw('1')
                        ->from('guardian_student_links as revoked_links')
                        ->whereColumn('revoked_links.tenant_id', $model->qualifyColumn('tenant_id'))
                        ->whereColumn('revoked_links.guardian_id', $guardian)
                        ->whereColumn('revoked_links.student_person_id', $student)
                        ->where('revoked_links.status', 'revocado')
                        ->whereNull('revoked_links.deleted_at');
                });
        }

        return $query->whereRaw('1 = 0');
    }
}
