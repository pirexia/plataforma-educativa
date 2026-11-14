<?php

namespace App\Modules\Auth\Infrastructure\Jobs;

use App\Modules\Auth\Infrastructure\Mail\IdentityMatchedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * `funcional.md §F.4.6`, `operacion.md §F.5`. El equivalente
 * institucional del aviso de fusión (`SendIdentityLinkedEmail`), con
 * contenido distinto: nombra el proveedor del centro y deja claro que
 * fue el sistema quien vinculó, no el titular. Sin enlace accionable
 * (`RN-AUTH-97`) y sin `ShouldBeEncrypted` (mismo criterio que el resto
 * del módulo: sin material de credencial en el *payload*).
 */
class SendIdentityMatchedEmail implements ShouldQueue
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
        public readonly string $providerDisplayName,
        public readonly string $matchedEmailMasked,
    ) {
        $this->onQueue('auth-mail');
    }

    public function handle(): void
    {
        Mail::to($this->recipientEmail)->locale($this->recipientLocale)->send(new IdentityMatchedMail(
            tenantName: $this->tenantName,
            givenName: $this->recipientGivenName,
            providerDisplayName: $this->providerDisplayName,
            matchedEmailMasked: $this->matchedEmailMasked,
        ));
    }
}
