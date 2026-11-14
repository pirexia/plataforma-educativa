<?php

namespace App\Modules\Auth\Infrastructure\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class AccountLockedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $tenantName,
        public readonly string $givenName,
        public readonly string $unlockUrl,
        public readonly int $failedCount,
        public readonly Carbon $autoUnlocksAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('auth.mail.account_locked.subject', ['tenant' => $this->tenantName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'auth::mail.account-locked',
            with: [
                'tenantName' => $this->tenantName,
                'givenName' => $this->givenName,
                'unlockUrl' => $this->unlockUrl,
                'failedCount' => $this->failedCount,
                'autoUnlocksAt' => $this->autoUnlocksAt,
            ],
        );
    }
}
