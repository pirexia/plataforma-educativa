<?php

namespace App\Modules\Auth\Infrastructure\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * operacion.md §C.5: sin enlace accionable. `$byAdmin` cambia el cuerpo
 * (§C.4.10) pero no la ausencia de enlace.
 */
class MfaFactorRemovedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $tenantName,
        public readonly string $givenName,
        public readonly bool $byAdmin,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('auth.mail.mfa_factor_removed.subject', ['tenant' => $this->tenantName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'auth::mail.mfa-factor-removed',
            with: [
                'tenantName' => $this->tenantName,
                'givenName' => $this->givenName,
                'byAdmin' => $this->byAdmin,
            ],
        );
    }
}
