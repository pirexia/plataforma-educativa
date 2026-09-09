<?php

namespace App\Modules\Backoffice\Infrastructure\Console;

use App\Modules\Backoffice\Application\PlatformAdminManagementService;
use App\Modules\Backoffice\Domain\PlatformRole;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * operacion.md §5 pasos 4-5. Crea sin contraseña utilizable y emite una
 * invitación real (issue #173): usa el mismo `PlatformAdminManagement
 * Service::create()` que `POST /admins`, para que el alta desde consola y
 * el alta desde la API compartan un único punto que crea e invita
 * (`api.md §2.2`) — antes de la corrección, este comando duplicaba la
 * transacción a mano y nunca emitía la invitación (issue #173).
 *
 * `AdminActionLogRecorder::resolveActorType()` ya distingue `console` de
 * `platform_admin` mirando `Auth::guard('platform')->user()` y
 * `app()->runningInConsole()`: no hace falta que este comando pase nada
 * especial para que `actor_type = 'console'` en `admin_action_logs`.
 *
 * El paso 5 de operacion.md ("repetir para un segundo
 * superadministrador") no es opcional: con una sola cuenta, eliminar un
 * tenant es imposible por diseño (RN-BO-19).
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'bo:create-admin {--email=} {--name=} {--role=superadministrador} {--locale=es-ES}';

    protected $description = 'Crea un administrador de plataforma sin contraseña utilizable y le envía una invitación (operacion.md §5)';

    public function handle(PlatformAdminManagementService $management): int
    {
        $email = (string) ($this->option('email') ?: $this->ask('Correo'));
        $name = (string) ($this->option('name') ?: $this->ask('Nombre'));
        $locale = (string) $this->option('locale');

        try {
            $role = PlatformRole::from((string) $this->option('role'));
        } catch (\ValueError) {
            throw new InvalidArgumentException('--role debe ser uno de: soporte, operaciones, comercial, superadministrador.');
        }

        $management->create($email, $name, $locale, $role);

        $this->info("Administrador creado: {$email} ({$role->value}).");
        $this->info('Invitación emitida y encolada para su envío (operacion.md §0, fila "Correo").');

        return self::SUCCESS;
    }
}
