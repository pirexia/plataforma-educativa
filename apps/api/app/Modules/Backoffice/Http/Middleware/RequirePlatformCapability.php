<?php

namespace App\Modules\Backoffice\Http\Middleware;

use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Modules\Backoffice\Domain\PlatformCapability;
use App\Modules\Backoffice\Domain\PlatformCapabilityMap;
use App\Support\Api\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * RN-BO-03, RN-BO-04, INV-002. Denegación por defecto: toda ruta de
 * plataforma exige capacidad explícita. Presente en TODAS las rutas
 * (puesto 9 de `api.md §1.1`, CA-BO-013), incluidas las de
 * pre-autenticación: se declara `capability:identity` para las que se
 * autorizan "por identidad del portador" (`GET /csrf-cookie`, `POST
 * /auth/session`, `POST /auth/session/mfa`, alta de MFA, `GET /me`) —
 * `api.md §1.1.1`, OPEN-BO-13 lectura (a). `identity` no es un código de
 * capacidad real (no aparece en `PlatformCapability`): es el valor
 * especial que este middleware conoce para "autenticado basta, sin
 * comprobar capacidad" — sin sesión, sigue exigiendo un guard válido
 * salvo en las rutas que ni eso necesitan (declaradas también como
 * `identity`, porque no hay sujeto todavía).
 *
 * Comprueba **capacidad**, nunca código de rol (RN-BO-04): resuelve los
 * roles vivos del administrador y consulta `PlatformCapabilityMap`, sin
 * ningún `if ($admin->hasRole('superadministrador'))`.
 */
class RequirePlatformCapability
{
    private const IDENTITY_ONLY = 'identity';

    public function handle(Request $request, Closure $next, string $capability): Response
    {
        if ($capability === self::IDENTITY_ONLY) {
            return $next($request);
        }

        $admin = Auth::guard('platform')->user();

        if (! $admin instanceof PlatformAdmin) {
            throw ApiException::unauthenticated();
        }

        $required = PlatformCapability::from($capability);

        if (! PlatformCapabilityMap::grants($admin->roles(), $required)) {
            throw ApiException::forbidden();
        }

        return $next($request);
    }
}
