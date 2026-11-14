<?php

namespace App\Modules\Auth\Infrastructure\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * operacion.md §B.4, funcional.md §B.4.5 punto 3. RN-AUTH-50: sin ningún
 * enlace accionable sin sesión — el único enlace es `/cuenta/sesiones` de
 * la SPA, que exige entrar. `locationLabel` siempre `null` en 1.2b
 * (RN-AUTH-47); el hueco está preparado en la plantilla para no rehacer
 * las cuatro traducciones al resolver `OPEN-AUTH-13`.
 */
class NewDeviceLoginMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $tenantName,
        public readonly string $givenName,
        public readonly Carbon $occurredAt,
        public readonly ?string $ipAddress,
        public readonly string $clientLabel,
        public readonly ?string $locationLabel,
        public readonly string $sessionsUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('auth.mail.new_device_login.subject', ['tenant' => $this->tenantName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'auth::mail.new-device-login',
            with: [
                'tenantName' => $this->tenantName,
                'givenName' => $this->givenName,
                'occurredAt' => $this->occurredAt,
                'ipAddress' => $this->ipAddress,
                'clientLabel' => $this->clientLabel,
                'locationLabel' => $this->locationLabel,
                'sessionsUrl' => $this->sessionsUrl,
            ],
        );
    }
}
