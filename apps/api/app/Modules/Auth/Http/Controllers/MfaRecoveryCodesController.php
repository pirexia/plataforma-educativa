<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Application\MfaRecoveryCodeService;
use App\Modules\Auth\Application\RateLimitGuard;
use App\Modules\Auth\Http\Requests\StoreMfaRecoveryCodesRequest;
use App\Support\Api\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

/**
 * api.md §C.1, `POST /auth/mfa-recovery-codes`, `§C.4.3` punto 4.
 */
class MfaRecoveryCodesController extends Controller
{
    public function __construct(
        private readonly MfaRecoveryCodeService $service,
        private readonly RateLimitGuard $rateLimits,
    ) {}

    public function store(StoreMfaRecoveryCodesRequest $request): JsonResponse
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw ApiException::unauthenticated();
        }

        $this->rateLimits->hit('mfa_recovery_codes_user', (string) $user->id);

        $codes = $this->service->regenerate($user, $request->string('current_password')->value());

        // §C.4.3 punto 5: en claro, una sola vez.
        return response()->json(['recovery_codes' => $codes], 201);
    }
}
