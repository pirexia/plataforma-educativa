<?php

namespace App\Modules\Backoffice\Infrastructure\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Issue #173. Precedente de forma: `App\Modules\Core\Infrastructure\Mail\
 * InvitationMail`. `Mail::to($email)->locale($locale)->send(...)` fija el
 * idioma de renderizado (`INV-009`, capa 2 de la *skill*
 * `i18n-cuatro-idiomas`). El token en claro llega sólo por el
 * constructor, viaja en el payload de la cola y no se persiste en ningún
 * otro sitio.
 */
class PlatformAdminInvitationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $activationUrl,
        public readonly int $expiresInDays,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('bo.mail.invitation.subject'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'backoffice::mail.invitation',
            with: [
                'recipientName' => $this->recipientName,
                'activationUrl' => $this->activationUrl,
                'expiresInDays' => $this->expiresInDays,
            ],
        );
    }
}
