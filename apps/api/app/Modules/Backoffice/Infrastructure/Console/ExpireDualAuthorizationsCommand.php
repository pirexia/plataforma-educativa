<?php

namespace App\Modules\Backoffice\Infrastructure\Console;

use App\Modules\Backoffice\Infrastructure\Jobs\ExpireDualAuthorizations;
use Illuminate\Console\Command;

/** operacion.md §6.2. Cada 15 minutos. */
class ExpireDualAuthorizationsCommand extends Command
{
    protected $signature = 'bo:expire-dual-authorizations';

    protected $description = 'Pasa a caducada las solicitudes de doble autorización vencidas (operacion.md §6.2)';

    public function handle(): int
    {
        ExpireDualAuthorizations::dispatch();

        return self::SUCCESS;
    }
}
