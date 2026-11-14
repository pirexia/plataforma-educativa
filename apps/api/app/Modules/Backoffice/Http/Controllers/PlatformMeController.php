<?php

namespace App\Modules\Backoffice\Http\Controllers;

use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Modules\Backoffice\Http\Resources\PlatformAdminResource;
use App\Support\Api\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

/** api.md §2.1, `GET /me`. Sin permiso, por identidad del portador. */
class PlatformMeController extends Controller
{
    public function show(): JsonResponse
    {
        $admin = Auth::guard('platform')->user();

        if (! $admin instanceof PlatformAdmin) {
            throw ApiException::unauthenticated();
        }

        return response()->json(new PlatformAdminResource($admin));
    }
}
