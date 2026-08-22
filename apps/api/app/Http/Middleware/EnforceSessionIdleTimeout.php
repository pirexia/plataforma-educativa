<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Modules\Core\Domain\TenantSettingsReader;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditRecorder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * REQ-AUTH-005 punto 1, RN-AUTH-30, funcional.md §4.6. La marca de última
 * actividad se guarda en el *payload* de la sesión (`pge_last_activity_at`),
 * **nunca** se lee de `sessions.last_activity`: el propio controlador de
 * sesión de Laravel refresca esa columna antes de que corra ningún
 * *middleware*, así que leerla siempre daría "actividad ahora mismo" y el
 * timeout nunca dispararía (funcional.md §4.6 punto 3, operacion.md §9).
 *
 * Posición 7 de la cadena (api.md §8), después de `VerifySessionTenant`:
 * no tiene sentido refrescar la actividad de una sesión que se va a
 * invalidar por tenant equivocado.
 */
class EnforceSessionIdleTimeout
{
    public function __construct(
        private readonly TenantSettingsReader $settings,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $lastActivityAt = $request->session()->get('pge_last_activity_at');
        $timeoutSeconds = $this->settings->sessionTimeoutMinutes() * 60;

        if (is_int($lastActivityAt) && (now()->timestamp - $lastActivityAt) > $timeoutSeconds) {
            $this->auditRecorder->record($user, 'logout');

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ApiException::unauthenticated();
        }

        $request->session()->put('pge_last_activity_at', now()->timestamp);

        return $next($request);
    }
}
