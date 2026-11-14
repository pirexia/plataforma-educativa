<?php

namespace App\Modules\Auth\Infrastructure\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * funcional.md §D.4.1, operacion.md §D.5. Código de alta de un factor de
 * entrega (`email`). Sin enlace accionable (`RN-AUTH-50`); el código nunca
 * va en el asunto.
 */
class MfaEnrollmentCodeMail extends Mailable
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
            subject: __('auth.mail.mfa_enrollment_code.subject', ['tenant' => $this->tenantName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'auth::mail.mfa-enrollment-code',
            with: [
                'tenantName' => $this->tenantName,
                'givenName' => $this->givenName,
                'code' => $this->code,
                'ttlMinutes' => $this->ttlMinutes,
            ],
        );
    }
}
