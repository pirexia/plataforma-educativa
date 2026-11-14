<?php

namespace App\Http\Middleware;

use App\Support\Api\ApiException;
use App\Support\Modules\ModuleAvailability;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RMOD-009 (funcional.md §8, operacion.md §1). No aplica a REQ-CORE, que
 * es siempre-activo por diseño: la usan los DEMÁS módulos, declarada en
 * su ruta como `module-enabled:<code>`.
 *
 * REQ-PERM/operacion.md §6.2 (1.5): la lectura del booleano se extrajo a
 * `ModuleAvailability` (implementada por `EloquentModuleAvailability`),
 * consumida también por `PermissionResolver` (filtro de inercia
 * `inerte_modulo`) — una sola definición de «utilizable», no dos.
 */
class EnsureModuleEnabled
{
    public function __construct(
        private readonly ModuleAvailability $availability,
    ) {}

    public function handle(Request $request, Closure $next, string $moduleCode): Response
    {
        if (! $this->availability->isEnabled($moduleCode)) {
            throw ApiException::moduleDisabled();
        }

        return $next($request);
    }
}
