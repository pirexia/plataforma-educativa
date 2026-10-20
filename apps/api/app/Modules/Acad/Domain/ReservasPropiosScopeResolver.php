<?php

namespace App\Modules\Acad\Domain;

use App\Models\User;
use App\Support\Authorization\Contracts\ScopeResolver;
use App\Support\Authorization\Scope;
use Illuminate\Database\Eloquent\Builder;

/**
 * REQ-ACAD/permisos.md §10.2 (1.12-b, `OPEN-ACAD-42`, `ADR-063 §5.2` punto 3):
 * `propios` sobre `reservas`. Un rol con `reservas.eliminar` de ámbito `propios`
 * solo cancela las reservas que creó el propio sujeto (`created_by`, el
 * reservante, `RN-ACAD-140`); la ajena responde `404` (`RN-ACAD-142`).
 *
 * Es un recurso propio de `acad`, así que lo registra `acad` en
 * `ScopeResolverRegistry` desde `AcadServiceProvider::boot()` — el núcleo
 * de autorización nunca importa esta clase, solo el contrato que implementa
 * (`INV-007`). Amplía el mapa cerrado de `AR-10` con aprobación expresa
 * (`OPEN-ACAD-42`).
 */
final class ReservasPropiosScopeResolver implements ScopeResolver
{
    public function scope(): Scope
    {
        return Scope::Propios;
    }

    public function resource(): string
    {
        return 'reservas';
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function constrain(Builder $query, User $subject): Builder
    {
        return $query->where('created_by', $subject->id);
    }
}
