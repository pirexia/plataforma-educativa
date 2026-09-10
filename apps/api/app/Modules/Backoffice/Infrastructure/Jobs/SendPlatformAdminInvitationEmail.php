<?php

namespace App\Modules\Backoffice\Infrastructure\Jobs;

use App\Modules\Backoffice\Infrastructure\Mail\PlatformAdminInvitationMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Issue #173. Precedente de forma: `App\Modules\Core\Infrastructure\Jobs\
 * SendInvitationEmail`. Cola propia (`bo-mail`, distinta de `core-mail`:
 * es correo de plataforma, no de tenant). `INV-012`: nunca se envía en la
 * petición HTTP. El token en claro sólo existe en este payload y en el
 * correo generado — nunca se escribe en base de datos.
 * `ShouldBeEncrypted` cifra el payload completo (con `APP_KEY`), también
 * el que queda en `failed_jobs` si se agotan los reintentos — mismo
 * criterio que el precedente de `Core` (issue #75).
 *
 * Se despacha SIEMPRE fuera de contexto de tenant (el backoffice no
 * tiene): `TenancyServiceProvider::registerTenantAwareQueues()` estampa
 * `tenant_id: null` en el payload y el *worker* no entra en ningún
 * tenant para procesarlo — comportamiento correcto, no una omisión.
 */
class SendPlatformAdminInvitationEmail implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly string $rawToken,
        public readonly string $recipientEmail,
        public readonly string $recipientName,
        public readonly string $recipientLocale,
        public readonly int $expiresInDays,
    ) {
        $this->onQueue('bo-mail');
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 600, 1200, 1800];
    }

    public function handle(): void
    {
        $host = (string) config('backoffice.host');
        $url = "https://{$host}/activar/{$this->rawToken}";

        Mail::to($this->recipientEmail)->locale($this->recipientLocale)->send(new PlatformAdminInvitationMail(
            recipientName: $this->recipientName,
            activationUrl: $url,
            expiresInDays: $this->expiresInDays,
        ));
    }
}
