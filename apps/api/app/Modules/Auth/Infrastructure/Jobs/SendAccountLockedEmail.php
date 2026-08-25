<?php

namespace App\Modules\Auth\Infrastructure\Jobs;

use App\Modules\Auth\Infrastructure\Mail\AccountLockedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * operacion.md §4. Solo se despacha cuando el bloqueo corresponde a una
 * cuenta real (funcional.md §4.4, RN-AUTH-15): un correo inexistente no
 * genera ningún trabajo. Issue #73: `ShouldBeEncrypted` cifra el `payload`
 * completo (con `APP_KEY`), también el que queda en `failed_jobs` si se
 * agotan los 5 reintentos — sin esto, el token de desbloqueo quedaba en
 * texto plano indefinidamente.
 */
class SendAccountLockedEmail implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly string $rawUnlockToken,
        public readonly string $recipientEmail,
        public readonly string $recipientGivenName,
        public readonly string $recipientLocale,
        public readonly string $tenantName,
        public readonly string $tenantSlug,
        public readonly int $failedCount,
        public readonly Carbon $lockedAt,
        public readonly Carbon $autoUnlocksAt,
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
        $url = "https://{$this->tenantSlug}.{$baseDomain}/desbloquear/{$this->rawUnlockToken}";

        Mail::to($this->recipientEmail)->locale($this->recipientLocale)->send(new AccountLockedMail(
            tenantName: $this->tenantName,
            givenName: $this->recipientGivenName,
            unlockUrl: $url,
            failedCount: $this->failedCount,
            autoUnlocksAt: $this->autoUnlocksAt,
        ));
    }
}
