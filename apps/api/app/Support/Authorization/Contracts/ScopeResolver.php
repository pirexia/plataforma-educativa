<?php

namespace App\Support\Authorization\Contracts;

use App\Models\User;
use App\Support\Authorization\Scope;
use Illuminate\Database\Eloquent\Builder;

/**
 * ADR-044 §3.4/§4.1/§4.2: contrato que implementa el módulo **propietario
 * de la entidad** sobre la que se resuelve un ámbito. El núcleo nunca sabe
 * qué es un grupo — solo define esta interfaz y el registro contra el que
 * se apunta (`ScopeResolverRegistry`).
 *
 * Tres decisiones de forma, todas de ADR-044 §3.4:
 *
 * 1. Un resolutor resuelve exactamente **un** par (ámbito, recurso). El
 *    registro es por ese par, no por código de permiso: la restricción
 *    depende de qué filas se consultan (propiedad del recurso), no de la
 *    acción.
 * 2. `constrain()` es el **único** método. No hay un `permits()` aparte
 *    para el detalle: la comprobación de una fila se hace aplicando esta
 *    misma restricción a una consulta acotada a esa fila
 *    (`App\Support\Authorization\ScopedQuery::satisfies()`). Un contrato
 *    con dos métodos podría divergir entre listado y detalle — exactamente
 *    el IDOR clásico que la *skill* `permisos-y-roles` describe.
 * 3. Es un predicado sobre filas, nada más: recibe el constructor de
 *    consulta y el sujeto, devuelve el constructor con la restricción
 *    aplicada.
 */
interface ScopeResolver
{
    /**
     * El ámbito que este resolutor resuelve. Uno de los seis de {@see Scope}.
     */
    public function scope(): Scope;

    /**
     * El recurso (`RPERM-002`) sobre el que se resuelve, p. ej. `auditoria`.
     */
    public function resource(): string;

    /**
     * Aplica la restricción de este ámbito sobre la consulta dada.
     *
     * Método genérico (no la interfaz completa): cada implementación
     * declara el mismo `@template` sin acotar a un modelo concreto, para
     * que PHPStan no vea una interfaz `Builder<Model>` estrecha frente a
     * una implementación `Builder<AuditLog>` — mismo `TModel` a los dos
     * lados, sin problema de varianza.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function constrain(Builder $query, User $subject): Builder;
}
