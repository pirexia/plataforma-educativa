<?php

namespace App\Support\Authorization;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * ADR-044 §4.2/§3.5, funcional.md §3.5: la **única** API sancionada para
 * acotar una consulta por ámbito. Cualquier otra forma de listar un
 * recurso permisionado con ámbito restringido es una desviación, no un
 * estilo alternativo (deuda estructural reconocida en `ADR-044 §8`: esto
 * no lo garantiza el framework, se compensa con esta API única más los
 * criterios de aceptación por recurso).
 *
 * Dos operaciones y solo dos, ambas sobre la misma restricción para que
 * listado y detalle no puedan divergir (ADR-044 §3.4, decisión 2):
 * `constrain()` para listar, `satisfies()` para comprobar una fila.
 */
final class ScopedQuery
{
    public function __construct(
        private readonly ScopeResolverRegistry $registry,
    ) {}

    /**
     * Aplica la unión (OR) de las restricciones de los ámbitos del
     * conjunto. Si el conjunto contiene `todos`, no aplica ninguna
     * restricción (RN-PERM-09).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function constrain(Builder $query, string $resource, PermissionDecision $decision, User $subject): Builder
    {
        if ($decision->isUnrestricted()) {
            return $query;
        }

        $resolvers = array_values(array_filter(array_map(
            fn (Scope $scope) => $this->registry->get($resource, $scope),
            $decision->scopes,
        )));

        if ($resolvers === []) {
            // RN-PERM-05: ningún ámbito del conjunto tiene resolutor
            // registrado (no debería ocurrir: el resolutor de permisos ya
            // trata esas concesiones como inertes antes de llegar aquí).
            // Fallo en cerrado: no devolver ninguna fila.
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $outer) use ($resolvers, $subject): void {
            foreach ($resolvers as $resolver) {
                $outer->orWhere(fn (Builder $inner) => $resolver->constrain($inner, $subject));
            }
        });
    }

    /**
     * `funcional.md §3.4`, decisión 2: el detalle se comprueba con la
     * MISMA restricción que el listado, aplicada a una consulta acotada a
     * esa fila. RN-PERM-14: quien llama decide qué hacer con un resultado
     * negativo — siempre `404`, nunca `403` (`api.md §9.3`).
     */
    public function satisfies(Model $row, string $resource, PermissionDecision $decision, User $subject): bool
    {
        if ($decision->isUnrestricted()) {
            return true;
        }

        $query = $row->newModelQuery()->whereKey($row->getKey());

        return $this->constrain($query, $resource, $decision, $subject)->exists();
    }
}
