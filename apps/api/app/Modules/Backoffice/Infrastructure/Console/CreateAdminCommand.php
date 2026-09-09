<?php

namespace App\Modules\Backoffice\Infrastructure\Console;

use App\Modules\Backoffice\Application\AdminActionLogRecorder;
use App\Modules\Backoffice\Domain\AdminActionLogAction;
use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Modules\Backoffice\Domain\Models\PlatformAdminRole;
use App\Modules\Backoffice\Domain\PlatformAdminStatus;
use App\Modules\Backoffice\Domain\PlatformRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * operacion.md §5 pasos 4-5. Crea sin contraseña utilizable. En 1.6 no
 * hay todavía invitación por correo (`OPEN-09`, servicio transaccional
 * pendiente): se genera un token de invitación de un solo uso y se
 * muestra por consola — `actor_type = 'console'` en `admin_action_logs`.
 *
 * El paso 5 de operacion.md ("repetir para un segundo
 * superadministrador") no es opcional: con una sola cuenta, eliminar un
 * tenant es imposible por diseño (RN-BO-19).
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'bo:create-admin {--email=} {--name=} {--role=superadministrador} {--locale=es-ES}';

    protected $description = 'Crea un administrador de plataforma sin contraseña utilizable (operacion.md §5)';

    public function handle(AdminActionLogRecorder $recorder): int
    {
        $email = (string) ($this->option('email') ?: $this->ask('Correo'));
        $name = (string) ($this->option('name') ?: $this->ask('Nombre'));
        $locale = (string) $this->option('locale');

        try {
            $role = PlatformRole::from((string) $this->option('role'));
        } catch (\ValueError) {
            throw new InvalidArgumentException('--role debe ser uno de: soporte, operaciones, comercial, superadministrador.');
        }

        $admin = DB::connection('pgsql_platform')->transaction(function () use ($email, $name, $locale, $role): PlatformAdmin {
            $admin = PlatformAdmin::create([
                'email' => $email,
                'name' => $name,
                'locale' => $locale,
                'password' => Str::password(40),
                'status' => PlatformAdminStatus::Activo,
                'password_changed_at' => now(),
            ]);

            PlatformAdminRole::create(['platform_admin_id' => $admin->id, 'role' => $role]);

            return $admin;
        });

        $recorder->record(
            action: AdminActionLogAction::AdminCreado,
            subjectPublicId: $admin->public_id,
            reason: 'alta desde consola',
            context: ['role' => $role->value],
        );

        $this->info("Administrador creado: {$email} ({$role->value}).");
        $this->warn('Sin invitación por correo (OPEN-09 pendiente): fija la contraseña con bo:reset-mfa/canal seguro propio.');

        return self::SUCCESS;
    }
}
