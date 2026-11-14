<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Application\PasswordResetRequestService;
use App\Modules\Auth\Application\RateLimitGuard;
use App\Modules\Auth\Http\Requests\StorePasswordResetRequestRequest;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

/**
 * api.md §4, `POST /auth/password-reset-requests`. RN-AUTH-10: `202`
 * siempre, exista o no la cuenta.
 */
class PasswordResetRequestsController extends Controller
{
    public function __construct(
        private readonly RateLimitGuard $rateLimits,
        private readonly PasswordResetRequestService $service,
    ) {}

    public function store(StorePasswordResetRequestRequest $request): Response
    {
        $email = Str::lower(trim($request->string('email')->value()));

        $this->rateLimits->hit('password_reset_request_ip', (string) $request->ip());
        $this->rateLimits->hit('password_reset_request_email', $email);

        $this->service->request($email);

        return response()->noContent(202);
    }
}
