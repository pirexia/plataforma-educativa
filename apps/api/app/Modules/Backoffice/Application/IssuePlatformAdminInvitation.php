<?php

namespace App\Modules\Backoffice\Application;

use App\Modules\Backoffice\Domain\AdminActionLogAction;
use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Modules\Backoffice\Domain\Models\PlatformAdminInvitation;
use App\Modules\Backoffice\Infrastructure\Jobs\SendPlatformAdminInvitationEmail;

/**
 * Issue #173. Precedente de forma: `App\Modules\Core\Application\
 * IssueUserInvitation` — un único punto que emite la invitación, revoca
 * la viva si la había, y despacha el correo en cola (`INV-012`).
 *
 * A diferencia del precedente de `Core`, no comprueba `status ===
 * pendiente`: `platform_admins.status` sólo admite `activo`/`suspendido`
 * (datos.md §2.1) y un administrador de plataforma nace `activo` sin
 * contraseña utilizable — es la propia invitación la que decide si
 * puede entrar, no el estado del administrador.
 */
final class IssuePlatformAdminInvitation
{
    public function __construct(
        private readonly AdminActionLogRecorder $recorder,
    ) {}

    public function issue(PlatformAdmin $admin): PlatformAdminInvitation
    {
        $this->revokeLiveInvitation($admin);

        $rawToken = bin2hex(random_bytes(32));
        $ttlDays = (int) config('backoffice.invitation_ttl_days');

        $invitation = PlatformAdminInvitation::create([
            'platform_admin_id' => $admin->id,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addDays($ttlDays),
        ]);

        SendPlatformAdminInvitationEmail::dispatch(
            rawToken: $rawToken,
            recipientEmail: $admin->email,
            recipientName: $admin->name,
            recipientLocale: $admin->locale,
            expiresInDays: $ttlDays,
        );

        $this->recorder->record(action: AdminActionLogAction::AdminInvitado, subjectPublicId: $admin->public_id);

        return $invitation;
    }

    private function revokeLiveInvitation(PlatformAdmin $admin): void
    {
        $live = PlatformAdminInvitation::query()
            ->where('platform_admin_id', $admin->id)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->first();

        if ($live === null) {
            return;
        }

        $live->update(['revoked_at' => now()]);
    }
}
