<?php

namespace App\Modules\Auth\Infrastructure\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * operacion.md §E.5: sin enlace accionable.
 */
class IdentityUnlinkedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $tenantName,
        public readonly string $givenName,
        public readonly string $unlinkedEmailMasked,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('auth.mail.identity_unlinked.subject', ['tenant' => $this->tenantName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'auth::mail.identity-unlinked',
            with: [
                'tenantName' => $this->tenantName,
                'givenName' => $this->givenName,
                'unlinkedEmailMasked' => $this->unlinkedEmailMasked,
            ],
        );
    }
}
