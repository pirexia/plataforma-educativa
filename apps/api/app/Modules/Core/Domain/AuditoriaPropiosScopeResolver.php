<?php

namespace App\Modules\Core\Domain;

use App\Models\User;
use App\Support\Authorization\Contracts\ScopeResolver;
use App\Support\Authorization\Scope;
use Illuminate\Database\Eloquent\Builder;

/**
 * REQ-PERM/funcional.md §6, ADR-044 §8: el único resolutor real de 1.5,
 * probado de punta a punta — sin él, el contrato de `ScopeResolver` no
 * está verificado (`INV-015`).
 *
 * `propios` sobre `auditoria`: un rol con `auditoria.leer`/`.exportar` de
 * ámbito `propios` ve únicamente las entradas en las que el sujeto es el
 * actor. `audit_logs.actor_user_id` es exactamente la columna que define
 * «lo mío» sin necesidad de ninguna entidad académica.
 *
 * Registrado desde `CoreServiceProvider::boot()` — el núcleo de
 * autorización nunca importa esta clase, solo el contrato que implementa
 * (`INV-007`).
 */
final class AuditoriaPropiosScopeResolver implements ScopeResolver
{
    public function scope(): Scope
    {
        return Scope::Propios;
    }

    public function resource(): string
    {
        return 'auditoria';
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function constrain(Builder $query, User $subject): Builder
    {
        return $query->where('actor_user_id', $subject->id);
    }
}
