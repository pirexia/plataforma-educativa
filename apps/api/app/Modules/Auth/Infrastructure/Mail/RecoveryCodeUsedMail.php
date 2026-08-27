<?php

namespace App\Modules\Auth\Infrastructure\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * operacion.md §C.5: sin enlace accionable.
 */
class RecoveryCodeUsedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $tenantName,
        public readonly string $givenName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('auth.mail.recovery_code_used.subject', ['tenant' => $this->tenantName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'auth::mail.recovery-code-used',
            with: [
                'tenantName' => $this->tenantName,
                'givenName' => $this->givenName,
            ],
        );
    }
}
