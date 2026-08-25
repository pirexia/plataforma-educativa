<?php

namespace App\Modules\Auth\Application;

use App\Models\User;
use App\Modules\Auth\Domain\ClientDescriber;
use App\Modules\Auth\Domain\Events\NewDeviceDetected;
use App\Modules\Auth\Domain\Models\UserSession;
use App\Modules\Auth\Infrastructure\Jobs\SendNewDeviceLoginEmail;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * funcional.md §B.4.1. Orquesta el registro de la fila de `user_sessions`
 * y la detección de dispositivo (`§B.4.5`) tras un login correcto —
 * llamado desde `SessionController::store()` después de `regenerate()`,
 * `Auth::login()` y el registro de auditoría `login` que ya existían
 * (ese orden no lo toca este servicio).
 *
 * Las escrituras de base de datos (dispositivo + sesión) van en una sola
 * transacción: si algo falla, el login falla — una sesión que existe y no
 * aparece en el panel es peor que no poder entrar (§B.4.1 punto 2). El
 * envío del correo se despacha DESPUÉS de que la transacción confirme,
 * mismo patrón que `PasswordChangeService`/`PasswordResetService`.
 */
final class SessionRegistrationService
{
    public function __construct(
        private readonly ClientDescriber $clientDescriber,
        private readonly DeviceRecognitionService $deviceRecognition,
        private readonly TenantContext $tenantContext,
    ) {}

    public function register(
        User $user,
        string $sessionId,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $deviceCookieValue,
    ): SessionRegistrationResult {
        $clientDescription = $this->clientDescriber->describe($userAgent);
        $maxUserAgentLength = (int) config('auth-local.user_agent_max_length');
        $truncatedUserAgent = $userAgent !== null ? Str::limit($userAgent, $maxUserAgentLength, '') : null;

        [$session, $deviceResult] = DB::transaction(function () use (
            $user, $sessionId, $ipAddress, $truncatedUserAgent, $clientDescription, $deviceCookieValue,
        ): array {
            $deviceResult = $this->deviceRecognition->recognize($user, $deviceCookieValue, $ipAddress, $clientDescription);

            $session = UserSession::create([
                'user_id' => $user->id,
                'session_id' => $sessionId,
                'started_at' => now(),
                'ip_address' => $ipAddress,
                'user_agent' => $truncatedUserAgent,
                'client_browser' => $clientDescription->browser,
                'client_platform' => $clientDescription->platform,
                'client_device_type' => $clientDescription->deviceType,
                'known_device_id' => $deviceResult->device->id,
            ]);

            return [$session, $deviceResult];
        });

        if ($deviceResult->isNew) {
            if ($deviceResult->shouldAlert) {
                SendNewDeviceLoginEmail::dispatch($user->public_id, $session->public_id, $deviceResult->device->id);
            }

            event(new NewDeviceDetected(
                $this->tenantContext->tenantId(),
                $user->public_id,
                now(),
                $deviceResult->shouldAlert,
            ));
        }

        return new SessionRegistrationResult($session, $deviceResult->isNew ? $deviceResult->rawCookieValue : null);
    }
}
