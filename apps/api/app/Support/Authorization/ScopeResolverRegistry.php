<?php

namespace App\Support\Authorization;

use App\Support\Authorization\Contracts\ScopeResolver;

/**
 * ADR-044 §3.4: el registro que expone el núcleo para que cada módulo
 * propietario de una entidad de ámbito registre su resolutor, desde el
 * `boot()` de su propio ServiceProvider — nunca al revés. Singleton
 * (`AuthorizationServiceProvider`): un único registro para todo el
 * proceso.
 *
 * RN-PERM-04/05: un par (recurso, ámbito) sin resolutor registrado no
 * puede concederse (422) y, si una fila así existiera, `has()` devuelve
 * `false` y el resolutor de permisos la trata como inerte. No hay tercera
 * vía.
 */
final class ScopeResolverRegistry
{
    /** @var array<string, ScopeResolver> */
    private array $resolvers = [];

    public function register(ScopeResolver $resolver): void
    {
        $this->resolvers[$this->key($resolver->resource(), $resolver->scope())] = $resolver;
    }

    public function has(string $resource, Scope $scope): bool
    {
        return $scope === Scope::Todos || isset($this->resolvers[$this->key($resource, $scope)]);
    }

    public function get(string $resource, Scope $scope): ?ScopeResolver
    {
        return $this->resolvers[$this->key($resource, $scope)] ?? null;
    }

    private function key(string $resource, Scope $scope): string
    {
        return "{$resource}:{$scope->value}";
    }
}
