<?php

namespace App\Modules\Backoffice\Http\Controllers;

use App\Modules\Backoffice\Application\PlatformMfaEnrollmentService;
use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Modules\Backoffice\Http\Requests\ConfirmPlatformMfaFactorRequest;
use App\Support\Api\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

/** REQ-BO-007, api.md §2.1. Únicos endpoints alcanzables sin factor confirmado. */
class PlatformMfaFactorsController extends Controller
{
    public function __construct(
        private readonly PlatformMfaEnrollmentService $enrollment,
    ) {}

    public function store(): JsonResponse
    {
        $result = $this->enrollment->beginEnrollment($this->admin());

        return response()->json([
            'public_id' => $result['factor']->public_id,
            'otpauth_uri' => $result['otpauth_uri'],
        ], 201);
    }

    public function confirm(ConfirmPlatformMfaFactorRequest $request, string $publicId): JsonResponse
    {
        $codes = $this->enrollment->confirm($this->admin(), $publicId, $request->string('code')->value());

        return response()->json(['recovery_codes' => $codes]);
    }

    private function admin(): PlatformAdmin
    {
        $admin = Auth::guard('platform')->user();

        if (! $admin instanceof PlatformAdmin) {
            throw ApiException::unauthenticated();
        }

        return $admin;
    }
}
