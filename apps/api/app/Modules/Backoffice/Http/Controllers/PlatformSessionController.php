<?php

namespace App\Modules\Backoffice\Http\Controllers;

use App\Modules\Backoffice\Application\PlatformAuthenticationService;
use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Modules\Backoffice\Http\Requests\StorePlatformSessionMfaRequest;
use App\Modules\Backoffice\Http\Requests\StorePlatformSessionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

/**
 * REQ-BO-007, api.md §2.1, funcional.md §5.1. `POST /auth/session` es
 * `200` con `mfa_required: true` si falta el segundo paso — nunca emite
 * sesión plena antes del factor (RN-BO-05).
 */
class PlatformSessionController extends Controller
{
    public function __construct(
        private readonly PlatformAuthenticationService $authentication,
    ) {}

    public function csrfCookie(Request $request): Response
    {
        return response()->noContent();
    }

    public function store(StorePlatformSessionRequest $request): JsonResponse
    {
        $admin = $this->authentication->attempt(
            $request->string('email')->value(),
            $request->string('password')->value(),
            (string) $request->ip(),
        );

        if (! $admin->hasMfaEnrolled()) {
            // RN-BO-05: sin factor confirmado, ni siquiera se abre un
            // desafío — el admin entra sin sesión plena y solo alcanza
            // /mfa/*, donde da de alta su factor.
            $request->session()->regenerate();
            Auth::guard('platform')->login($admin);

            return response()->json(['mfa_required' => false, 'mfa_enrollment_required' => true]);
        }

        $this->authentication->issueChallenge($admin, $request->session()->getId());

        return response()->json(['mfa_required' => true]);
    }

    public function storeMfa(StorePlatformSessionMfaRequest $request): JsonResponse
    {
        $admin = $this->authentication->resolveChallenge(
            $request->session()->getId(),
            $request->string('code')->value(),
        );

        $request->session()->regenerate();
        Auth::guard('platform')->login($admin);

        $this->authentication->registerSession(
            $admin,
            $request->session()->getId(),
            $request->ip(),
            $request->userAgent(),
        );

        return response()->json(['mfa_required' => false]);
    }

    public function destroy(Request $request): Response
    {
        $admin = Auth::guard('platform')->user();

        if ($admin instanceof PlatformAdmin) {
            $this->authentication->endSession($admin, $request->session()->getId());
        }

        Auth::guard('platform')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
