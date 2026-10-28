<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Api\ApiException;
use App\Support\Modules\ModuleAvailability;
use App\Support\Sessions\SessionWall;
use App\Support\Sessions\SessionWallRegistry;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-067 §5.3 (sustituye a `RequireMfaEnrollment`, REQ-AUTH/funcional.md §C.4.9). Recorre en orden los muros de sesión
 * registrados. `INV-002`: lista blanca, no negra — un endpoint nuevo de cualquier módulo queda bloqueado por defecto.
 *
 * Regla entre muros (§5.4): manda el primer muro, por orden, que no exima la ruta y bloquee al usuario. Si un muro exime
 * la ruta, la ruta pasa **sin evaluar ese muro ni los posteriores** (las exenciones se heredan hacia atrás en el orden,
 * nunca hacia delante): así un usuario con dos muros pendientes nunca queda encerrado. Ese atajo, anterior a
 * `blocks()`, es además lo que evita materializar la obligación de MFA en una ruta exenta (`MfaPolicy::resolve()` tiene
 * efectos).
 *
 * Un muro de un módulo no activo no se evalúa (§5.9). Los muros se resuelven del contenedor en cada petición (§5.2).
 * Sin efecto sobre un usuario sin sesión (no hay nada que restringir aquí; cualquier `401` lo decide el endpoint o
 * `permission`). Un `403` del muro no es una escritura y no se audita (§5.12).
 */
class RequireSessionWalls
{
    public function __construct(
        private readonly SessionWallRegistry $registry,
        private readonly Container $container,
        private readonly ModuleAvailability $modules,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();

        foreach ($this->registry->ordered() as $class) {
            /** @var SessionWall $wall */
            $wall = $this->container->make($class);

            if (! $this->modules->isEnabled($wall->moduleCode())) {
                continue;
            }

            if (in_array($routeName, $wall->exemptRouteNames(), true)) {
                return $next($request);
            }

            if ($wall->blocks($user)) {
                throw ApiException::sessionWall($wall->errorType());
            }
        }

        return $next($request);
    }
}
