<?php

namespace App\Modules\Auth\Infrastructure\Jobs;

use App\Modules\Auth\Infrastructure\Mail\MfaFactorRemovedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * funcional.md §C.4.13. Aviso al titular: se desactiva un segundo factor
 * (por el propio usuario) o el administrador lo restablece —
 * `$byAdmin` distingue el texto, no el hecho. Sin enlace accionable
 * (`RN-AUTH-50`).
 */
class SendMfaFactorRemovedEmail implements ShouldQueue
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
        public readonly bool $byAdmin,
    ) {
        $this->onQueue('auth-mail');
    }

    public function handle(): void
    {
        Mail::to($this->recipientEmail)->locale($this->recipientLocale)->send(new MfaFactorRemovedMail(
            tenantName: $this->tenantName,
            givenName: $this->recipientGivenName,
            byAdmin: $this->byAdmin,
        ));
    }
}
