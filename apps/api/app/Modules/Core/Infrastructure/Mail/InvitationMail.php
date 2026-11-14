<?php

namespace App\Modules\Core\Infrastructure\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * funcional.md §4.3. `Mail::to($email)->locale($locale)->send(...)` fija
 * el idioma de renderizado (REQ-CORE-006 capa 2, CA-CORE-024). El token en
 * claro llega solo por el constructor, viaja en el payload de la cola y
 * no se persiste en ningún otro sitio (RN-CORE-19).
 */
class InvitationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $tenantName,
        public readonly string $givenName,
        public readonly string $activationUrl,
        public readonly int $expiresInDays,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('core.mail.invitation.subject', ['tenant' => $this->tenantName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'core::mail.invitation',
            with: [
                'tenantName' => $this->tenantName,
                'givenName' => $this->givenName,
                'activationUrl' => $this->activationUrl,
                'expiresInDays' => $this->expiresInDays,
            ],
        );
    }
}
