<?php

namespace App\Modules\Backoffice\Infrastructure\Console;

use App\Modules\Backoffice\Application\AdminActionLogRecorder;
use App\Modules\Backoffice\Domain\AdminActionLogAction;
use App\Modules\Backoffice\Domain\Models\PlatformIpAllowlistEntry;
use Illuminate\Console\Command;

/**
 * operacion.md §5 paso 3, §5.1. Desde el servidor: sin esto no entra
 * nadie, ni con credenciales correctas (RN-BO-07).
 */
class AllowIpCommand extends Command
{
    protected $signature = 'bo:allow-ip {cidr} {--description=}';

    protected $description = 'Añade una entrada a la lista blanca de IP del backoffice (operacion.md §5)';

    public function handle(AdminActionLogRecorder $recorder): int
    {
        $cidr = (string) $this->argument('cidr');
        $description = (string) ($this->option('description') ?: $this->ask('Descripción (obligatoria, p. ej. "Oficina", "VPN")'));

        if (trim($description) === '') {
            $this->error('La descripción es obligatoria: una entrada sin descripción nadie se atreve a retirarla.');

            return self::FAILURE;
        }

        $entry = PlatformIpAllowlistEntry::create([
            'cidr' => $cidr,
            'description' => $description,
            'enabled' => true,
        ]);

        $recorder->record(
            action: AdminActionLogAction::IpPermitidaAnadida,
            subjectPublicId: $entry->public_id,
            context: ['cidr' => $cidr, 'description' => $description],
        );

        $this->info("Entrada añadida: {$cidr} ({$description}).");

        return self::SUCCESS;
    }
}
