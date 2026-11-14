<?php

namespace App\Support\Authorization;

/**
 * ADR-044 §3.5, funcional.md §3.5: objeto de valor inmutable, resultado de
 * resolver un código de permiso para un sujeto. Única fuente para las dos
 * salidas de la autorización (funcional.md §3.3): la puerta (`permitted`)
 * y la acotación (`scopes`, vía `App\Support\Authorization\ScopedQuery`).
 */
final class PermissionDecision
{
    /**
     * @param  list<Scope>  $scopes  conjunto unión de ámbitos concedidos, tras aplicar las cuatro inercias (RN-PERM-07) — vacío si denegado
     * @param  list<PermissionSource>  $sources  procedencia de cada concesión y cada denegación (RPERM-009)
     */
    public function __construct(
        public readonly string $code,
        public readonly bool $permitted,
        public readonly array $scopes,
        public readonly array $sources,
    ) {}

    /**
     * RN-PERM-09: `todos` en el conjunto absorbe — sin restricción de fila.
     */
    public function isUnrestricted(): bool
    {
        return in_array(Scope::Todos, $this->scopes, true);
    }

    public function hasScope(Scope $scope): bool
    {
        return $this->isUnrestricted() || in_array($scope, $this->scopes, true);
    }
}
