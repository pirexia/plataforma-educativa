<?php

namespace App\Modules\Backoffice\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RN-BO-48, ADR-046 §4.3. Primer middleware de la pila de plataforma: si
 * el host de la petición no coincide con `BACKOFFICE_HOST`, 404 — antes
 * de sesión y de credenciales. Mismo criterio que `ResolveTenant` con un
 * host desconocido: no se revela que la superficie existe. La
 * comparación es contra `BACKOFFICE_HOST`, nunca contra "lo que no
 * resuelve tenant".
 *
 * No sustituye a la regla `Host()` de Traefik ni al revés (operacion.md
 * §0): las dos capas son obligatorias.
 */
class RequirePlatformHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredHost = strtolower((string) config('backoffice.host'));
        $requestHost = strtolower(explode(':', $request->getHost(), 2)[0]);

        if ($configuredHost === '' || $requestHost !== $configuredHost) {
            abort(404);
        }

        return $next($request);
    }
}
