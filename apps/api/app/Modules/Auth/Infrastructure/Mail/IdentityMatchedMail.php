<?php

namespace App\Modules\Auth\Infrastructure\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * `operacion.md §F.5`. Sin enlace accionable. `providerDisplayName` es
 * texto del centro sin traducir (`funcional.md §F.9`), igual que
 * `tenantName`.
 */
class IdentityMatchedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $tenantName,
        public readonly string $givenName,
        public readonly string $providerDisplayName,
        public readonly string $matchedEmailMasked,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('auth.mail.identity_matched.subject', ['tenant' => $this->tenantName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'auth::mail.identity-matched',
            with: [
                'tenantName' => $this->tenantName,
                'givenName' => $this->givenName,
                'providerDisplayName' => $this->providerDisplayName,
                'matchedEmailMasked' => $this->matchedEmailMasked,
            ],
        );
    }
}
