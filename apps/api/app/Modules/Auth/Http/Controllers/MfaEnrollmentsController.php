<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Application\MfaEnrollmentService;
use App\Modules\Auth\Application\RateLimitGuard;
use App\Modules\Auth\Domain\MfaMethod;
use App\Modules\Auth\Http\Requests\StoreMfaEnrollmentRequest;
use App\Support\Api\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

/**
 * api.md §C.1, `POST /auth/mfa-enrollments`. Sin permiso: por identidad
 * del portador de la cookie, como el resto de autoservicio del módulo.
 */
class MfaEnrollmentsController extends Controller
{
    public function __construct(
        private readonly MfaEnrollmentService $service,
        private readonly RateLimitGuard $rateLimits,
    ) {}

    public function store(StoreMfaEnrollmentRequest $request): JsonResponse
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw ApiException::unauthenticated();
        }

        $this->rateLimits->hit('mfa_enrollment_user', (string) $user->id);

        $method = MfaMethod::from($request->string('method')->value());
        $result = $this->service->start($user, $method);

        // §C.4.1 punto 4: el secreto en claro y la URI otpauth salen del
        // servidor UNA SOLA VEZ, en esta respuesta (RN-AUTH-55).
        return response()->json([
            'public_id' => $result->factor->public_id,
            'method' => $result->factor->method->value,
            'secret' => $result->secretBase32,
            'otpauth_uri' => $result->otpAuthUri,
            'expires_at' => $result->factor->expires_at->toISOString(),
        ], 201);
    }
}
