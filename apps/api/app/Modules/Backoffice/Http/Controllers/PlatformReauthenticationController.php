<?php

namespace App\Modules\Backoffice\Http\Controllers;

use App\Modules\Backoffice\Application\PlatformAuthenticationService;
use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Modules\Backoffice\Http\Requests\StorePlatformReauthenticationRequest;
use App\Support\Api\ApiException;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

/** REQ-BO-007, api.md §2.1, funcional.md §5.2. */
class PlatformReauthenticationController extends Controller
{
    public function __construct(
        private readonly PlatformAuthenticationService $authentication,
    ) {}

    public function store(StorePlatformReauthenticationRequest $request): Response
    {
        $admin = Auth::guard('platform')->user();

        if (! $admin instanceof PlatformAdmin) {
            throw ApiException::unauthenticated();
        }

        $this->authentication->reauthenticate(
            $admin,
            $request->string('password')->value(),
            $request->string('code')->value(),
        );

        return response()->noContent();
    }
}
