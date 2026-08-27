<?php

namespace App\Modules\Auth\Infrastructure\Jobs;

use App\Modules\Auth\Infrastructure\Mail\MfaFactorActivatedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * funcional.md §C.4.13. Aviso al titular: se ha activado un segundo
 * factor. Sin enlace accionable (`RN-AUTH-50`): si no fue el propio
 * titular, es la señal más urgente del módulo.
 */
class SendMfaFactorActivatedEmail implements ShouldQueue
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
    ) {
        $this->onQueue('auth-mail');
    }

    public function handle(): void
    {
        Mail::to($this->recipientEmail)->locale($this->recipientLocale)->send(new MfaFactorActivatedMail(
            tenantName: $this->tenantName,
            givenName: $this->recipientGivenName,
        ));
    }
}
