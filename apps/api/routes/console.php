<?php

use App\Modules\Core\Infrastructure\Jobs\PurgeExpiredIdempotencyKeys;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// REQ-CORE 1.1, operacion.md §4. `core:purge-maintenance` despacha las
// cuatro purgas por tenant a la cola `core-maintenance`; la purga de
// claves de idempotencia no es por tenant (ver la clase) y se programa
// directamente. El *scheduler* corre en su propio contenedor
// (`ADR-037`), nunca en el de la API.
Schedule::command('core:purge-maintenance')->daily();
Schedule::job(new PurgeExpiredIdempotencyKeys)->daily();
