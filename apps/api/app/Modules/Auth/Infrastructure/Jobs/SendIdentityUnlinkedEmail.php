<?php

namespace App\Modules\Auth\Infrastructure\Jobs;

use App\Modules\Auth\Infrastructure\Mail\IdentityUnlinkedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * funcional.md §E.4.5 punto 5, §E.4.7. Sin enlace accionable (`RN-AUTH-97`).
 */
class SendIdentityUnlinkedEmail implements ShouldQueue
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
        public readonly string $unlinkedEmailMasked,
    ) {
        $this->onQueue('auth-mail');
    }

    public function handle(): void
    {
        Mail::to($this->recipientEmail)->locale($this->recipientLocale)->send(new IdentityUnlinkedMail(
            tenantName: $this->tenantName,
            givenName: $this->recipientGivenName,
            unlinkedEmailMasked: $this->unlinkedEmailMasked,
        ));
    }
}
