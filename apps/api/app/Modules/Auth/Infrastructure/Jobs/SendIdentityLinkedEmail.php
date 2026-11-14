<?php

namespace App\Modules\Auth\Infrastructure\Jobs;

use App\Modules\Auth\Infrastructure\Mail\IdentityLinkedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * funcional.md §E.4.7. Fusión automática o vinculación desde el perfil —
 * `linkMethod` distingue el texto (`§E.4.7`: "la parte útil del aviso").
 * Sin enlace accionable (`RN-AUTH-97`). `operacion.md §E.4`: sin
 * `ShouldBeEncrypted` — el *payload* no lleva material de credencial,
 * solo el correo del destinatario, como todos los del módulo.
 */
class SendIdentityLinkedEmail implements ShouldQueue
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
        public readonly string $linkedEmailMasked,
        public readonly string $linkMethod,
    ) {
        $this->onQueue('auth-mail');
    }

    public function handle(): void
    {
        Mail::to($this->recipientEmail)->locale($this->recipientLocale)->send(new IdentityLinkedMail(
            tenantName: $this->tenantName,
            givenName: $this->recipientGivenName,
            linkedEmailMasked: $this->linkedEmailMasked,
            linkMethod: $this->linkMethod,
        ));
    }
}
