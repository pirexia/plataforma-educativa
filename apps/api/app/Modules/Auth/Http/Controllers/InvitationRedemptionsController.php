<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Application\InvitationRedemptionService;
use App\Modules\Auth\Application\RateLimitGuard;
use App\Modules\Auth\Http\Requests\StoreInvitationRedemptionRequest;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * api.md §3, `POST /auth/invitation-redemptions`. Contrato del token
 * fijado por `REQ-CORE/funcional.md §4.3`.
 */
class InvitationRedemptionsController extends Controller
{
    public function __construct(
        private readonly RateLimitGuard $rateLimits,
        private readonly InvitationRedemptionService $service,
    ) {}

    public function store(StoreInvitationRedemptionRequest $request): Response
    {
        $this->rateLimits->hit('token_endpoints_ip', (string) $request->ip());

        $this->service->redeem(
            $request->string('token')->value(),
            $request->string('password')->value(),
        );

        return response()->noContent();
    }
}
