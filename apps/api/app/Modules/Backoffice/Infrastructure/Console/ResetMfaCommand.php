<?php

namespace App\Modules\Backoffice\Infrastructure\Console;

use App\Modules\Backoffice\Application\AdminActionLogRecorder;
use App\Modules\Backoffice\Domain\AdminActionLogAction;
use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Modules\Backoffice\Domain\Models\PlatformAdminSession;
use App\Modules\Backoffice\Domain\PlatformAdminSessionEndReason;
use Illuminate\Console\Command;

/**
 * operacion.md §5.1. Salida de un bloqueo total: el único administrador
 * pierde su segundo factor. Desde el servidor, auditado como `console` —
 * no hay vía por correo ni por la API (CA-BO-012).
 */
class ResetMfaCommand extends Command
{
    protected $signature = 'bo:reset-mfa {email}';

    protected $description = 'Restablece el segundo factor de un administrador de plataforma desde el servidor (operacion.md §5.1)';

    public function handle(AdminActionLogRecorder $recorder): int
    {
        $email = (string) $this->argument('email');

        $admin = PlatformAdmin::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->first();

        if ($admin === null) {
            $this->error("No existe ningún administrador con el correo {$email}.");

            return self::FAILURE;
        }

        $admin->mfaFactors()->delete();
        $admin->forceFill(['mfa_enrolled_at' => null])->save();

        PlatformAdminSession::query()
            ->where('platform_admin_id', $admin->id)
            ->whereNull('ended_at')
            ->get()
            ->each(fn (PlatformAdminSession $session) => $session->close(PlatformAdminSessionEndReason::MfaRestablecido));

        $recorder->record(action: AdminActionLogAction::AdminMfaRestablecido, subjectPublicId: $admin->public_id, reason: 'restablecido desde consola');

        $this->info("Segundo factor restablecido para {$email}. Debe darlo de alta de nuevo al entrar.");

        return self::SUCCESS;
    }
}
