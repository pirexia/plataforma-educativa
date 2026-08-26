<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Application\AuthenticatedSessionEstablisher;
use App\Modules\Auth\Application\LoginService;
use App\Modules\Auth\Application\MfaChallengeService;
use App\Modules\Auth\Application\RateLimitGuard;
use App\Modules\Auth\Domain\MfaPolicy;
use App\Modules\Auth\Domain\Models\UserSession;
use App\Modules\Auth\Domain\SessionEndReason;
use App\Modules\Auth\Http\Requests\StoreSessionRequest;
use App\Support\Audit\AuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;

/**
 * api.md §2. `POST /auth/session` es el único camino del sistema que crea
 * una sesión (`RN-AUTH-21`). Desde 1.3 (`funcional.md §C.4.4`) puede en
 * cambio abrir un desafío de segundo factor y no crear ninguna: la
 * credencial era correcta, pero eso ya no basta por sí solo.
 */
class SessionController extends Controller
{
    /** funcional.md §B.6.2, RN-AUTH-45. */
    private const DEVICE_COOKIE_NAME = 'pge_device';

    public function __construct(
        private readonly RateLimitGuard $rateLimits,
        private readonly LoginService $loginService,
        private readonly AuditRecorder $auditRecorder,
        private readonly MfaPolicy $mfaPolicy,
        private readonly MfaChallengeService $mfaChallenges,
        private readonly AuthenticatedSessionEstablisher $establisher,
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
     * funcional.md §C.4.4. Contraseña correcta ⇒ o bien hay un factor de
     * MFA que superar (`202`, ningún dato de sesión escrito, `§C.6`) o
     * bien se establece la sesión exactamente como en 1.2 — obligado sin
     * factor, en gracia o no, siempre `200`: la restricción del muro la
     * aplica `RequireMfaEnrollment` en la petición siguiente, no aquí
     * (`§C.4.9`).
     */
    public function store(StoreSessionRequest $request): JsonResponse
    {
        $email = $this->loginService->normalize($request->string('email')->value());

        $this->rateLimits->hit('session_ip', (string) $request->ip());
        $this->rateLimits->hit('session_email', $email);

        $user = $this->loginService->attempt($email, $request->string('password')->value());

        if ($this->mfaPolicy->hasUsableFactor($user)) {
            return response()->json($this->mfaChallenges->open($request, $user), 202);
        }

        $result = $this->establisher->establish($request, $user, $email, $request->cookie(self::DEVICE_COOKIE_NAME));

        if ($result->newDeviceCookieValue !== null) {
            $this->queueDeviceCookie($result->newDeviceCookieValue);
        }

        return response()->json($result->profile);
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
