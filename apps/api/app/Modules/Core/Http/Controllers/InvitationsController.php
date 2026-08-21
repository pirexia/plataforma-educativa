<?php

namespace App\Modules\Core\Http\Controllers;

use App\Models\User;
use App\Modules\Core\Application\IssueUserInvitation;
use App\Modules\Core\Domain\Events\InvitationRevoked;
use App\Modules\Core\Domain\Models\UserInvitation;
use App\Modules\Core\Http\Resources\InvitationResource;
use App\Support\Api\ApiException;
use App\Support\Api\PagePaginatedResponse;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\RateLimiter;

/**
 * api.md §4 (`REQ-CORE-003`). El canje queda fuera de 1.1 (1.2); aquí
 * solo se emite, se lista y se revoca.
 */
class InvitationsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['sometimes', 'in:vigente,caducada,revocada,aceptada']]);

        $query = UserInvitation::query()->with('user')->latest('id');

        if ($request->filled('status')) {
            match ($request->string('status')->value()) {
                'aceptada' => $query->whereNotNull('accepted_at'),
                'revocada' => $query->whereNull('accepted_at')->whereNotNull('revoked_at'),
                'caducada' => $query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '<', now()),
                'vigente' => $query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>=', now()),
                default => null,
            };
        }

        $paginator = $query->paginate($request->integer('per_page', 25))->withQueryString();

        return PagePaginatedResponse::make($paginator, InvitationResource::class);
    }

    /**
     * §4.3, RN-CORE-09/12. Emite o reemite: revoca la viva anterior.
     */
    public function store(string $userPublicId, IssueUserInvitation $issuer): JsonResponse
    {
        $user = User::where('public_id', $userPublicId)->firstOrFail();

        $rateLimitKey = app(TenantContext::class)->rateLimitKey("invitations:{$user->id}");

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            throw ApiException::tooManyRequests(RateLimiter::availableIn($rateLimitKey));
        }

        RateLimiter::hit($rateLimitKey, 3600);

        $tenant = Tenant::query()->find(app(TenantContext::class)->tenantId());

        $invitation = $issuer->issue($user, $tenant->slug ?? '', $tenant->name ?? '');

        return response()->json((new InvitationResource($invitation))->resolve(), 201);
    }

    public function destroy(string $publicId): JsonResponse
    {
        $invitation = UserInvitation::where('public_id', $publicId)->firstOrFail();

        if ($invitation->accepted_at !== null) {
            throw ApiException::conflict('core.validation.invitation_already_accepted');
        }

        $invitation->update(['revoked_at' => now()]);

        event(new InvitationRevoked($invitation->tenant_id, $invitation->public_id, $invitation->user->public_id));

        return response()->json(null, 204);
    }
}
