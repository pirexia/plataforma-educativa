<?php

namespace App\Modules\Auth\Infrastructure\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * operacion.md §E.5: sin enlace accionable. `linkMethod` distingue
 * «lo vinculé yo» de «el sistema lo vinculó porque los correos
 * coincidían» — la parte útil del aviso.
 */
class IdentityLinkedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $tenantName,
        public readonly string $givenName,
        public readonly string $linkedEmailMasked,
        public readonly string $linkMethod,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('auth.mail.identity_linked.subject', ['tenant' => $this->tenantName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'auth::mail.identity-linked',
            with: [
                'tenantName' => $this->tenantName,
                'givenName' => $this->givenName,
                'linkedEmailMasked' => $this->linkedEmailMasked,
                'isFusion' => $this->linkMethod === 'fusion_automatica',
            ],
        );
    }
}
