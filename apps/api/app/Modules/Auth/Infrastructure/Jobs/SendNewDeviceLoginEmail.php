<?php

namespace App\Modules\Auth\Infrastructure\Jobs;

use App\Models\User;
use App\Modules\Auth\Domain\Models\UserKnownDevice;
use App\Modules\Auth\Domain\Models\UserSession;
use App\Modules\Auth\Infrastructure\Mail\NewDeviceLoginMail;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * operacion.md §B.3, funcional.md §B.4.5. El *payload* transporta
 * identificadores, no datos personales (issue #73 aplicado antes de
 * tropezar con él): el trabajo relee `user_sessions`/`user_known_devices`
 * para componer el correo, así que no necesita `ShouldBeEncrypted` — no
 * hay nada en el *payload* que cifrar. El contexto de tenant lo entra/sale
 * automáticamente `TenancyServiceProvider::registerTenantAwareQueues()`.
 *
 * Si la fila de la sesión ya no existe cuando el trabajo se ejecuta (el
 * usuario la revocó en los segundos siguientes), el correo se envía
 * igualmente con la información que quede del dispositivo — no avisar
 * porque el propio usuario reaccionó rápido sería perder el aviso
 * justo en el caso en que hubo reacción.
 */
class SendNewDeviceLoginEmail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $userPublicId,
        public readonly string $sessionPublicId,
        public readonly ?int $knownDeviceId,
    ) {
        $this->onQueue('auth-mail');
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(TenantContext $tenantContext): void
    {
        $user = User::query()->where('public_id', $this->userPublicId)->first();

        if ($user === null) {
            return;
        }

        $session = UserSession::withTrashed()->where('public_id', $this->sessionPublicId)->first();

        [$occurredAt, $ipAddress, $clientLabel] = $session !== null
            ? [$session->started_at, $session->ip_address, $this->composeClientLabel($session->client_browser, $session->client_platform)]
            : $this->fallbackFromDevice();

        $tenant = Tenant::query()->find($tenantContext->tenantId());
        $baseDomain = (string) config('tenancy.base_domain');
        $locale = $user->person->locale ?? 'es-ES';

        Mail::to($user->email)->locale($locale)->send(new NewDeviceLoginMail(
            tenantName: $tenant->name ?? '',
            givenName: $user->person->given_name ?? '',
            occurredAt: $occurredAt,
            ipAddress: $ipAddress !== null ? (string) $ipAddress : null,
            clientLabel: $clientLabel,
            // RN-AUTH-47, OPEN-AUTH-13: siempre null en 1.2b.
            locationLabel: null,
            sessionsUrl: "https://{$tenant->slug}.{$baseDomain}/cuenta/sesiones",
        ));
    }

    /**
     * @return array{0: Carbon, 1: mixed, 2: string}
     */
    private function fallbackFromDevice(): array
    {
        $device = $this->knownDeviceId !== null
            ? UserKnownDevice::withTrashed()->find($this->knownDeviceId)
            : null;

        return [
            $device->first_seen_at ?? now(),
            $device->last_ip_address ?? null,
            $device->label ?? __('auth.mail.new_device_login.unknown_client'),
        ];
    }

    private function composeClientLabel(?string $browser, ?string $platform): string
    {
        $browser ??= 'desconocido';
        $platform ??= 'desconocido';

        if ($browser === 'desconocido' && $platform === 'desconocido') {
            return __('auth.mail.new_device_login.unknown_client');
        }

        return "{$browser} · {$platform}";
    }
}
