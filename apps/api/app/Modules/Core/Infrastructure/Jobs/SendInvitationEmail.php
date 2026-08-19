<?php

namespace App\Modules\Core\Infrastructure\Jobs;

use App\Modules\Core\Infrastructure\Mail\InvitationMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * operacion.md §4: cola `core-mail`, 5 reintentos con retroceso
 * exponencial (1 min a 30 min). INV-012: nunca se envía en la petición
 * HTTP. El token en claro solo existe en este payload y en el correo
 * generado — nunca se escribe en base de datos (RN-CORE-19).
 *
 * El contexto de tenant lo entra/sale automáticamente
 * TenancyServiceProvider::registerTenantAwareQueues() (ADR-033 §8) a
 * partir del `tenant_id` que Queue::createPayloadUsing() ya estampó al
 * despachar: este job no lo gestiona a mano.
 */
class SendInvitationEmail implements ShouldQueue
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
        public readonly int $expiresInDays,
    ) {
        $this->onQueue('core-mail');
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
        $url = "https://{$this->tenantSlug}.{$baseDomain}/activar/{$this->rawToken}";

        Mail::to($this->recipientEmail)->locale($this->recipientLocale)->send(new InvitationMail(
            tenantName: $this->tenantName,
            givenName: $this->recipientGivenName,
            activationUrl: $url,
            expiresInDays: $this->expiresInDays,
        ));
    }
}
