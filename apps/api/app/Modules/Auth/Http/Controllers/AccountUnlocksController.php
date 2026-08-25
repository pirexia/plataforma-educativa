<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Application\AccountUnlockService;
use App\Modules\Auth\Application\RateLimitGuard;
use App\Modules\Auth\Http\Requests\StoreAccountUnlockRequest;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * api.md §5, `POST /auth/account-unlocks`.
 */
class AccountUnlocksController extends Controller
{
    public function __construct(
        private readonly RateLimitGuard $rateLimits,
        private readonly AccountUnlockService $service,
    ) {}

    public function store(StoreAccountUnlockRequest $request): Response
    {
        $this->rateLimits->hit('token_endpoints_ip', (string) $request->ip());

        $this->service->unlock($request->string('token')->value());

        return response()->noContent();
    }
}
