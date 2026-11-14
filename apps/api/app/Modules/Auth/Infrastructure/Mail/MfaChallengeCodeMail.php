<?php

namespace App\Modules\Auth\Infrastructure\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * funcional.md §D.4.2, operacion.md §D.5. Código de segundo factor en el
 * login. Sin enlace accionable (`RN-AUTH-50`); el código nunca va en el
 * asunto; avisa de cambiar la contraseña si el destinatario no ha
 * intentado entrar.
 */
class MfaChallengeCodeMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $tenantName,
        public readonly string $givenName,
        public readonly string $code,
        public readonly int $ttlMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('auth.mail.mfa_challenge_code.subject', ['tenant' => $this->tenantName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'auth::mail.mfa-challenge-code',
            with: [
                'tenantName' => $this->tenantName,
                'givenName' => $this->givenName,
                'code' => $this->code,
                'ttlMinutes' => $this->ttlMinutes,
            ],
        );
    }
}
