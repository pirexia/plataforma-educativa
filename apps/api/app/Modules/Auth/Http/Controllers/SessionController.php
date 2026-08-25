<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Application\LoginService;
use App\Modules\Auth\Application\RateLimitGuard;
use App\Modules\Auth\Http\Requests\StoreSessionRequest;
use App\Support\Api\UserProfilePresenter;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

/**
 * api.md §2. `POST /auth/session` es el único camino del sistema que crea
 * una sesión (`RN-AUTH-21`).
 */
class SessionController extends Controller
{
    public function __construct(
        private readonly RateLimitGuard $rateLimits,
        private readonly LoginService $loginService,
        private readonly UserProfilePresenter $presenter,
        private readonly TenantContext $tenantContext,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    /**
     * §4.7: equivalente propio de `/sanctum/csrf-cookie`. La cookie
     * `XSRF-TOKEN` la deja el middleware `csrf` del grupo, no este método.
     */
    public function csrfCookie(): Response
    {
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

        $request->session()->put('pge_tenant_id', $this->tenantContext->tenantId());
        $request->session()->put('pge_last_activity_at', now()->timestamp);

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
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
