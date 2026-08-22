<?php

namespace App\Modules\Auth\Infrastructure\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * operacion.md §5: sin enlace accionable, a propósito.
 */
class PasswordChangedMail extends Mailable
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
            subject: __('auth.mail.password_changed.subject', ['tenant' => $this->tenantName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'auth::mail.password-changed',
            with: [
                'tenantName' => $this->tenantName,
                'givenName' => $this->givenName,
            ],
        );
    }
}
