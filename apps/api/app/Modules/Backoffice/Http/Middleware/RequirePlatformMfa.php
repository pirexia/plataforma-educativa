<?php

namespace App\Modules\Backoffice\Http\Middleware;

use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Support\Api\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * RN-BO-05, RN-BO-03. MFA obligatorio sin excepción, sin período de
 * gracia y sin mecanismo de exención — CA-BO-005, CA-BO-006. Presente en
 * TODAS las rutas de `/api/platform/*` (puesto 8 de `api.md §1.1`,
 * CA-BO-013).
 *
 * Issue #174: la excepción **no** es una lista de nombres de ruta en esta
 * clase (`OPEN-BO-13` la descarta explícitamente — «existe una lista que
 * alguien puede ampliar»). Sigue el mismo patrón que
 * `RequirePlatformCapability` (puesto 9): la excepción se declara **en el
 * sitio de la ruta**, como parámetro del propio middleware
 * (`require-platform-mfa:exento`), no en una constante de la clase. Las
 * únicas rutas exentas son las que crean/resuelven la sesión y las de
 * alta del propio factor (`api.md §1.1.1`, §2.1) — cada una lo declara
 * explícitamente en `routes.php`.
 *
 * Sin efecto sobre una petición sin sesión de plataforma (nada que
 * restringir aquí: cualquier 401 lo decide `RequirePlatformCapability` o
 * el propio endpoint).
 */
class RequirePlatformMfa
{
    private const EXENTO = 'exento';

    public function handle(Request $request, Closure $next, ?string $exception = null): Response
    {
        $admin = Auth::guard('platform')->user();

        if (! $admin instanceof PlatformAdmin) {
            return $next($request);
        }

        if ($exception === self::EXENTO) {
            return $next($request);
        }

        if ($admin->hasMfaEnrolled()) {
            return $next($request);
        }

        throw ApiException::forbidden();
    }
}
