<?php

namespace App\Modules\Auth\Infrastructure\Jobs;

use App\Modules\Auth\Infrastructure\Mail\MfaEnrollmentCodeMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * funcional.md §D.4.1, operacion.md §D.4.1. Alta de un factor de entrega
 * (`email`): entrega el código de confirmación. `ShouldBeEncrypted`
 * (issue #73): el código en claro viaja en el *payload*, también el que
 * queda en `failed_jobs` si se agotan los reintentos.
 *
 * Retroceso corto (`operacion.md §D.4.1`): el código vive
 * `AUTH_MFA_CODE_TTL_MINUTES` (10 min) y el alta `AUTH_MFA_ENROLLMENT_TTL_MINUTES`
 * (10 min) — un reintento con retroceso exponencial normal entregaría el
 * código cuando ya no vale. Tres intentos en minuto y medio, o nada.
 */
class SendMfaEnrollmentCodeEmail implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $recipientEmail,
        public readonly string $recipientGivenName,
        public readonly string $recipientLocale,
        public readonly string $tenantName,
        public readonly string $code,
        public readonly int $ttlMinutes,
    ) {
        $this->onQueue('auth-mail');
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(): void
    {
        Mail::to($this->recipientEmail)->locale($this->recipientLocale)->send(new MfaEnrollmentCodeMail(
            tenantName: $this->tenantName,
            givenName: $this->recipientGivenName,
            code: $this->code,
            ttlMinutes: $this->ttlMinutes,
        ));
    }
}
