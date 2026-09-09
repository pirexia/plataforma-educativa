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
 * CA-BO-013): la excepción no es una lista externa, es este propio
 * middleware, que conoce sus rutas de pre-autenticación (`api.md
 * §1.1.1`, OPEN-BO-13 lectura (a) — mismo patrón que
 * `RequireMfaEnrollment::ALLOWED_ROUTE_NAMES` del tenant).
 *
 * Sin efecto sobre una petición sin sesión de plataforma (nada que
 * restringir aquí: cualquier 401 lo decide `RequirePlatformCapability` o
 * el propio endpoint).
 */
class RequirePlatformMfa
{
    /**
     * Únicas rutas alcanzables sin segundo factor confirmado: las que
     * crean/resuelven la sesión y las de alta del propio factor
     * (`api.md §1.1.1`, §2.1).
     *
     * @var list<string>
     */
    private const MFA_EXEMPT_ROUTE_NAMES = [
        'platform.csrf-cookie',
        'platform.auth.session.store',
        'platform.auth.session.mfa.store',
        'platform.auth.session.destroy',
        'platform.mfa.factors.store',
        'platform.mfa.factors.confirm',
        'platform.mfa.recovery-codes.index',
        'platform.me.show',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('platform')->user();

        if (! $admin instanceof PlatformAdmin) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();

        if (in_array($routeName, self::MFA_EXEMPT_ROUTE_NAMES, true)) {
            return $next($request);
        }

        if ($admin->hasMfaEnrolled()) {
            return $next($request);
        }

        throw ApiException::forbidden();
    }
}
