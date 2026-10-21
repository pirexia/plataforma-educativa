<?php

namespace App\Modules\Auth\Infrastructure\Jobs;

use App\Modules\Auth\Infrastructure\Mail\MfaChallengeCodeMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * funcional.md §D.4.2, operacion.md §D.4.1. Apertura o reenvío de un
 * desafío de login con un método de entrega (`email`). `ShouldBeEncrypted`
 * (issue #73): el código en claro viaja en el *payload*.
 *
 * Retroceso corto: el desafío vive `AUTH_MFA_CHALLENGE_TTL_MINUTES` (5 min)
 * y el código `AUTH_MFA_CODE_TTL_MINUTES` (10 min) — un reintento tardío
 * entregaría un código para un desafío ya muerto.
 */
class SendMfaChallengeCodeEmail implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** Marca temporal (UNIX) a partir de la cual el código ya no vale. Nulo en trabajos encolados por la versión anterior (CLAUDE.md §9). */
    public ?int $expiresAt = null;

    public function __construct(
        public readonly string $recipientEmail,
        public readonly string $recipientGivenName,
        public readonly string $recipientLocale,
        public readonly string $tenantName,
        public readonly string $code,
        public readonly int $ttlMinutes,
    ) {
        $this->onQueue('auth-mail');
        $this->expiresAt = now()->addMinutes($ttlMinutes)->getTimestamp();
    }

    /**
     * Issue #128 (revisión B-1): con worker real, un trabajo retenido por
     * una cola atrasada o un reintento tardío no debe entregar un código ya
     * caducado. `retryUntil()` corta los reintentos; `handle()` descarta
     * sin enviar el que llega tarde en el primer intento.
     */
    public function retryUntil(): ?\DateTimeInterface
    {
        return $this->expiresAt === null ? null : now()->setTimestamp($this->expiresAt);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(): void
    {
        if ($this->expiresAt !== null && now()->getTimestamp() >= $this->expiresAt) {
            return;
        }

        Mail::to($this->recipientEmail)->locale($this->recipientLocale)->send(new MfaChallengeCodeMail(
            tenantName: $this->tenantName,
            givenName: $this->recipientGivenName,
            code: $this->code,
            ttlMinutes: $this->ttlMinutes,
        ));
    }
}
