<?php

namespace App\Modules\Backoffice\Http\Middleware;

use App\Modules\Backoffice\Application\PlatformReauthenticationCheck;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RN-BO-08, funcional.md §5.2, api.md §4. Las operaciones marcadas como
 * sensibles exigen que la sesión haya reautenticado (contraseña y
 * segundo factor) dentro de una ventana corta. Se guarda como marca de
 * tiempo EN LA SESIÓN, no en una tabla: es estado de sesión, muere con
 * ella. `POST /auth/reauthenticate` es quien la fija.
 *
 * `platform_admin_sessions.reauthenticated_at` (datos.md §2.7) es el
 * reflejo consultable de esta marca, no la que gobierna esta
 * comprobación.
 *
 * 1.6b: la comprobación en sí vive en `PlatformReauthenticationCheck`,
 * reutilizada también por `TenantsController::transitions()` para la
 * única operación de este módulo cuya sensibilidad depende del cuerpo de
 * la petición (`to_status`), donde una declaración estática de
 * middleware no basta (api.md §4).
 */
class RequirePlatformReauthentication
{
    public const SESSION_KEY = PlatformReauthenticationCheck::SESSION_KEY;

    public function handle(Request $request, Closure $next): Response
    {
        PlatformReauthenticationCheck::ensureFresh($request);

        return $next($request);
    }
}
