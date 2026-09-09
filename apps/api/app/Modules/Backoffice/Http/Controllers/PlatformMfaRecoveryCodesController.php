<?php

namespace App\Modules\Backoffice\Http\Controllers;

use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Modules\Backoffice\Domain\Models\PlatformAdminMfaRecoveryCode;
use App\Support\Api\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

/**
 * api.md §2.1, `GET /mfa/recovery-codes`. Los códigos en claro solo se
 * muestran una vez, al confirmar el factor (`PlatformMfaFactorsController
 * ::confirm()`) — aquí solo el recuento de los que quedan sin usar, nunca
 * el valor.
 */
class PlatformMfaRecoveryCodesController extends Controller
{
    public function index(): JsonResponse
    {
        $admin = Auth::guard('platform')->user();

        if (! $admin instanceof PlatformAdmin) {
            throw ApiException::unauthenticated();
        }

        $remaining = PlatformAdminMfaRecoveryCode::query()
            ->where('platform_admin_id', $admin->id)
            ->whereNull('used_at')
            ->count();

        return response()->json(['remaining' => $remaining]);
    }
}
