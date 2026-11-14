<?php

namespace App\Modules\Backoffice\Http\Middleware;

use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Modules\Backoffice\Domain\Models\PlatformAdminSession;
use App\Modules\Backoffice\Domain\PlatformAdminSessionEndReason;
use App\Support\Api\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * RN-BO-09. Vida de la sesión de plataforma, más corta que la del
 * tenant y configurable (`BO_SESSION_LIFETIME`) — mismo mecanismo que
 * `EnforceSessionIdleTimeout`, adaptado al guard `platform`: la marca de
 * actividad vive en el *payload* de la sesión, nunca se lee de
 * `platform_sessions.last_activity` (el propio manejador la refresca
 * antes de que corra ningún middleware).
 */
class RequirePlatformSessionIdleTimeout
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('platform')->user();

        if (! $admin instanceof PlatformAdmin) {
            return $next($request);
        }

        $lastActivityAt = $request->session()->get('bo_last_activity_at');
        $timeoutSeconds = (int) config('backoffice.session_lifetime') * 60;

        if (is_int($lastActivityAt) && (now()->timestamp - $lastActivityAt) > $timeoutSeconds) {
            PlatformAdminSession::query()
                ->where('session_id', $request->session()->getId())
                ->whereNull('ended_at')
                ->first()
                ?->close(PlatformAdminSessionEndReason::Caducidad);

            Auth::guard('platform')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ApiException::unauthenticated();
        }

        $request->session()->put('bo_last_activity_at', now()->timestamp);

        return $next($request);
    }
}
