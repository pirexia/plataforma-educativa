<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Application\PasswordResetService;
use App\Modules\Auth\Application\RateLimitGuard;
use App\Modules\Auth\Http\Requests\StorePasswordResetRequest;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * api.md §4, `POST /auth/password-resets`.
 */
class PasswordResetsController extends Controller
{
    public function __construct(
        private readonly RateLimitGuard $rateLimits,
        private readonly PasswordResetService $service,
    ) {}

    public function store(StorePasswordResetRequest $request): Response
    {
        $this->rateLimits->hit('token_endpoints_ip', (string) $request->ip());

        $this->service->reset(
            $request->string('token')->value(),
            $request->string('password')->value(),
        );

        return response()->noContent();
    }
}
