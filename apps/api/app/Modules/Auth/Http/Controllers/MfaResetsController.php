<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Application\MfaResetService;
use App\Modules\Auth\Application\RateLimitGuard;
use App\Modules\Auth\Http\Requests\StoreMfaResetRequest;
use App\Support\Api\ApiException;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

/**
 * api.md §C.5, `POST /mfa-resets`, `§C.4.10`. Permiso `mfa.eliminar`.
 */
class MfaResetsController extends Controller
{
    public function __construct(
        private readonly MfaResetService $service,
        private readonly RateLimitGuard $rateLimits,
    ) {}

    public function store(StoreMfaResetRequest $request): Response
    {
        $actor = Auth::user();

        if (! $actor instanceof User) {
            throw ApiException::unauthenticated();
        }

        $this->rateLimits->hit('mfa_resets_admin', (string) $actor->id);

        $target = User::query()->where('public_id', $request->string('user')->value())->first();

        if ($target === null) {
            throw ApiException::notFound();
        }

        $this->service->reset($actor, $target, $request->string('reason')->value());

        return response()->noContent();
    }
}
