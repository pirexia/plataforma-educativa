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

        // `tenant_id`/`forceCreate()` a mano (issue #196): `tenant:provision-
        // defaults` (fase 2 del alta de tenant, `1.6b`) puede llamar a este
        // método con `runAsPlatform()` todavía activo en la pila —
        // `BelongsToTenant` no rellena solo en modo plataforma, y
        // `tenant_id` no está en $fillable a propósito. $user->tenant_id ya
        // es correcto (se acaba de crear en este mismo contexto de tenant).
        $invitation = UserInvitation::forceCreate([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addDays($ttlDays),
        ]);

        $locale = $user->person->locale ?? $this->settings->defaultLocale();

        SendInvitationEmail::dispatch(
            rawToken: $rawToken,
            recipientEmail: $user->email,
            recipientGivenName: $user->person->given_name ?? '',
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
        // `where('tenant_id', ...)` a mano (issue #196): sin él, en modo
        // plataforma esta consulta podría coincidir con la invitación de
        // OTRO usuario con el mismo `user_id` autoincremental en otro
        // tenant (`TenantScope` no filtra en modo plataforma).
        $live = UserInvitation::query()
            ->where('tenant_id', $user->tenant_id)
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
