<?php

namespace App\Modules\Backoffice\Http\Middleware;

use App\Support\Api\ApiException;
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
 */
class RequirePlatformReauthentication
{
    public const SESSION_KEY = 'platform_reauthenticated_at';

    public function handle(Request $request, Closure $next): Response
    {
        $reauthenticatedAt = $request->session()->get(self::SESSION_KEY);
        $windowMinutes = (int) config('backoffice.reauthentication_window_minutes');

        if ($reauthenticatedAt === null || now()->diffInMinutes($reauthenticatedAt, true) > $windowMinutes) {
            throw ApiException::reauthenticationRequired();
        }

        return $next($request);
    }
}
