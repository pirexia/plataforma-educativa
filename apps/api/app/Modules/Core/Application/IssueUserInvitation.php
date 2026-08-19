<?php

namespace App\Modules\Core\Application;

use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Core\Domain\Events\InvitationIssued;
use App\Modules\Core\Domain\Events\InvitationRevoked;
use App\Modules\Core\Domain\Models\UserInvitation;
use App\Modules\Core\Domain\TenantSettingsReader;
use App\Modules\Core\Infrastructure\Jobs\SendInvitationEmail;
use App\Support\Api\ApiException;

/**
 * funcional.md §4.3, §4.7. Usada por `POST /users/{id}/invitations` (1.1),
 * el alta de usuario con `send_invitation: true`, la importación masiva y
 * `tenant:provision-defaults` — un único punto que respeta RN-CORE-09
 * (revoca la viva), RN-CORE-12 (solo `pendiente`) y RN-CORE-19 (el token
 * en claro no se persiste).
 */
final class IssueUserInvitation
{
    public function __construct(
        private readonly TenantSettingsReader $settings,
    ) {}

    public function issue(User $user, string $tenantSlug, string $tenantName): UserInvitation
    {
        if ($user->status !== UserStatus::Pendiente) {
            throw ApiException::conflict('core.validation.invitation_requires_pending_user');
        }

        $this->revokeLiveInvitation($user);

        $rawToken = bin2hex(random_bytes(32));
        $ttlDays = config('core.invitation_ttl_days');

        $invitation = UserInvitation::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addDays($ttlDays),
        ]);

        $locale = $user->person?->locale ?? $this->settings->defaultLocale();

        SendInvitationEmail::dispatch(
            rawToken: $rawToken,
            recipientEmail: $user->email,
            recipientGivenName: $user->person?->given_name ?? '',
            recipientLocale: $locale,
            tenantName: $tenantName,
            tenantSlug: $tenantSlug,
            expiresInDays: $ttlDays,
        );

        event(new InvitationIssued($user->tenant_id, $invitation->public_id, $user->public_id));

        return $invitation;
    }

    private function revokeLiveInvitation(User $user): void
    {
        $live = UserInvitation::query()
            ->where('user_id', $user->id)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->first();

        if ($live === null) {
            return;
        }

        $live->update(['revoked_at' => now()]);

        event(new InvitationRevoked($user->tenant_id, $live->public_id, $user->public_id));
    }
}
