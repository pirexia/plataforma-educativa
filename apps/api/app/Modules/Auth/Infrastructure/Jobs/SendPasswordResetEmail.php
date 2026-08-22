<?php

namespace App\Modules\Auth\Infrastructure\Jobs;

use App\Modules\Auth\Infrastructure\Mail\PasswordResetMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * operacion.md §4: cola `auth-mail`, 5 reintentos, retroceso exponencial
 * (1 min a 30 min). INV-012: nunca se envía en la petición HTTP. El token
 * en claro solo existe en este *payload* y en el correo generado
 * (RN-AUTH-09) — nunca se escribe en base de datos.
 */
class SendPasswordResetEmail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly string $rawToken,
        public readonly string $recipientEmail,
        public readonly string $recipientGivenName,
        public readonly string $recipientLocale,
        public readonly string $tenantName,
        public readonly string $tenantSlug,
        public readonly int $expiresInMinutes,
    ) {
        $this->onQueue('auth-mail');
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 600, 1200, 1800];
    }

    public function handle(): void
    {
        $baseDomain = (string) config('tenancy.base_domain');
        $url = "https://{$this->tenantSlug}.{$baseDomain}/restablecer/{$this->rawToken}";

        Mail::to($this->recipientEmail)->locale($this->recipientLocale)->send(new PasswordResetMail(
            tenantName: $this->tenantName,
            givenName: $this->recipientGivenName,
            resetUrl: $url,
            expiresInMinutes: $this->expiresInMinutes,
        ));
    }
}
