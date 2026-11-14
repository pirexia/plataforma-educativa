<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Application\MfaEnrollmentService;
use App\Modules\Auth\Application\MfaFactorRemovalService;
use App\Modules\Auth\Http\Requests\DestroyMfaFactorRequest;
use App\Modules\Auth\Http\Requests\StoreMfaFactorRequest;
use App\Support\Api\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

/**
 * api.md §C.1, `POST /auth/mfa-factors` (confirmación) y
 * `DELETE /auth/mfa-factors/{public_id}` (desactivación). Sin permiso:
 * por identidad.
 */
class MfaFactorsController extends Controller
{
    public function __construct(
        private readonly MfaEnrollmentService $enrollmentService,
        private readonly MfaFactorRemovalService $removalService,
    ) {}

    public function store(StoreMfaFactorRequest $request): JsonResponse
    {
        $user = $this->currentUser();

        $result = $this->enrollmentService->confirm(
            $user,
            $request->string('enrollment')->value(),
            $request->string('code')->value(),
        );

        return response()->json([
            'factor' => [
                'public_id' => $result->factor->public_id,
                'method' => $result->factor->method->value,
                'confirmed_at' => $result->factor->confirmed_at->toISOString(),
            ],
            // §C.4.1 punto 9: solo cuando se han generado (primer factor).
            'recovery_codes' => $result->recoveryCodes,
        ], 201);
    }

    public function destroy(DestroyMfaFactorRequest $request, string $publicId): Response
    {
        $user = $this->currentUser();

        $this->removalService->remove($user, $publicId, $request->string('current_password')->value());

        return response()->noContent();
    }

    private function currentUser(): User
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw ApiException::unauthenticated();
        }

        return $user;
    }
}
