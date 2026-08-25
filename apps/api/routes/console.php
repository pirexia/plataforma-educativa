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

// REQ-AUTH 1.2, operacion.md §4. `auth:close-expired-lockouts` no es
// diaria como el resto: RN-AUTH-38 la quiere frecuente porque un bloqueo
// vencido y sin cerrar ocupa el hueco del índice único parcial de
// RN-AUTH-17 hasta que algo lo cierre (el propio login lo cierra
// perezosamente, esto es el consolidador para los que nadie vuelva a
// tocar).
Schedule::command('auth:purge-maintenance')->daily();
Schedule::command('auth:close-expired-lockouts')->everyFiveMinutes();

// Issue #73: sin esto, un job de correo de REQ-AUTH que agota sus 5
// reintentos (SendPasswordResetEmail/SendAccountLockedEmail, con un token
// de un solo uso en el payload) se queda en failed_jobs sin fecha de
// caducidad. ShouldBeEncrypted ya cifra ese payload; esta purga es la
// segunda capa (nunca guardar más de lo necesario, ni siquiera cifrado).
// failed_jobs es tabla de plataforma (config/tenancy.php), no por tenant.
Schedule::command('queue:prune-failed', ['--hours' => 24])->daily();
