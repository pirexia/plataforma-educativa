<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Application\LoginService;
use App\Modules\Auth\Application\RateLimitGuard;
use App\Modules\Auth\Application\SessionRegistrationService;
use App\Modules\Auth\Domain\Models\UserSession;
use App\Modules\Auth\Domain\SessionEndReason;
use App\Modules\Auth\Http\Requests\StoreSessionRequest;
use App\Support\Api\UserProfilePresenter;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;

/**
 * api.md §2. `POST /auth/session` es el único camino del sistema que crea
 * una sesión (`RN-AUTH-21`).
 */
class SessionController extends Controller
{
    /** funcional.md §B.6.2, RN-AUTH-45. */
    private const DEVICE_COOKIE_NAME = 'pge_device';

    public function __construct(
        private readonly RateLimitGuard $rateLimits,
        private readonly LoginService $loginService,
        private readonly UserProfilePresenter $presenter,
        private readonly TenantContext $tenantContext,
        private readonly AuditRecorder $auditRecorder,
        private readonly SessionRegistrationService $sessionRegistration,
    ) {}

    /**
     * §4.7: equivalente propio de `/sanctum/csrf-cookie`. La cookie
     * `XSRF-TOKEN` la deja el middleware `csrf` del grupo, no este método.
     * Issue #74: es el único de los 6 endpoints anónimos que abre sesión
     * (inserta en `sessions`) sin límite de tasa — el *bucket*
     * `csrf_cookie_ip` ya existía en `auth-local.php` pero nunca se
     * invocaba.
     */
    public function csrfCookie(Request $request): Response
    {
        $this->rateLimits->hit('csrf_cookie_ip', (string) $request->ip());

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    public function store(StoreSessionRequest $request): array
    {
        $email = $this->loginService->normalize($request->string('email')->value());

        $this->rateLimits->hit('session_ip', (string) $request->ip());
        $this->rateLimits->hit('session_email', $email);

        $user = $this->loginService->attempt($email, $request->string('password')->value());

        // RN-AUTH-32: regenerate() rota el identificador de sesión Y el
        // token CSRF (Illuminate\Session\Store::regenerate() llama a
        // regenerateToken() internamente).
        $request->session()->regenerate();

        Auth::guard('web')->login($user);

        // ADR-039 §4.5/§4.6: se registra DESPUÉS de Auth::login() para que
        // AuditActor::resolveType() resuelva 'user' (actor ya autenticado),
        // no 'anonymous'. Issue de regresión: LoginService lo hacía antes
        // de esta línea y escribía la fila con el actor equivocado.
        $this->auditRecorder->record($user, 'login');

        $request->session()->put('pge_tenant_id', $this->tenantContext->tenantId());
        $request->session()->put('pge_last_activity_at', now()->timestamp);

        // funcional.md §B.4.1: DESPUÉS de regenerate()/login()/auditoría —
        // el identificador de sesión que hay que guardar es el nuevo
        // (RN-AUTH-32), y el orden del registro de auditoría lo fija
        // ADR-039 §4.5 (issue de regresión #63).
        $registration = $this->sessionRegistration->register(
            $user,
            $request->session()->getId(),
            $request->ip(),
            $request->userAgent(),
            $request->cookie(self::DEVICE_COOKIE_NAME),
        );

        if ($registration->newDeviceCookieValue !== null) {
            $this->queueDeviceCookie($registration->newDeviceCookieValue);
        }

        return $this->presenter->present($user);
    }

    /**
     * §4.3: idempotente a propósito. Cerrar una sesión que ya no existe
     * no es un error (`CA-AUTH-017`).
     */
    public function destroy(Request $request): Response
    {
        $user = Auth::user();

        if ($user instanceof User) {
            $this->auditRecorder->record($user, 'logout');

            // funcional.md §B.4.6: se cierra ANTES de invalidate() — el
            // mismo orden con el que ADR-039 §4.5 justifica el registro de
            // 'logout' antes de destruir la sesión.
            UserSession::query()
                ->where('session_id', $request->session()->getId())
                ->whereNull('ended_at')
                ->first()
                ?->close(SessionEndReason::Logout);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    /**
     * api.md §B.6, RN-AUTH-45. Emitida solo en la respuesta 200 del login,
     * solo cuando el dispositivo no se reconoce. `Secure` sigue la misma
     * excepción de desarrollo local que la cookie de sesión
     * (`config('session.secure')`); nunca lleva `Domain` (host-only).
     */
    private function queueDeviceCookie(string $rawValue): void
    {
        $ttlMinutes = (int) config('auth-local.device_cookie_ttl_days') * 24 * 60;

        Cookie::queue(Cookie::make(
            name: self::DEVICE_COOKIE_NAME,
            value: $rawValue,
            minutes: $ttlMinutes,
            path: null,
            domain: null,
            secure: (bool) config('session.secure'),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        ));
    }
}
