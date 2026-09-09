<?php

namespace App\Modules\Backoffice\Http\Controllers;

use App\Modules\Backoffice\Application\PlatformAdminInvitationRedemptionService;
use App\Modules\Backoffice\Http\Requests\StorePlatformAdminInvitationRedemptionRequest;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Issue #173, `api.md §2.1`, `operacion.md §5` paso 6. Anónimo: autorizado
 * por posesión del token, mismo criterio que `POST /api/v1/auth/
 * invitation-redemptions` de `REQ-AUTH`. El sujeto todavía no tiene
 * sesión con la que tener capacidades — `require-platform-capability:
 * identity` en `routes.php`.
 */
class PlatformAdminInvitationRedemptionsController extends Controller
{
    public function __construct(
        private readonly PlatformAdminInvitationRedemptionService $redemptions,
    ) {}

    public function store(StorePlatformAdminInvitationRedemptionRequest $request): Response
    {
        $this->redemptions->redeem(
            $request->string('token')->value(),
            $request->string('password')->value(),
        );

        return response()->noContent();
    }
}
