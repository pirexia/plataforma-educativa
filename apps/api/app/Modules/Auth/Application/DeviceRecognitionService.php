<?php

namespace App\Modules\Auth\Application;

use App\Models\User;
use App\Modules\Auth\Domain\ClientDescription;
use App\Modules\Auth\Domain\Models\UserKnownDevice;

/**
 * funcional.md §B.4.5, RN-AUTH-46. El criterio de "dispositivo nuevo" es
 * exactamente uno: la cookie `pge_device` no corresponde a un dispositivo
 * vivo de ese (tenant_id, user_id). Ni el `User-Agent`, ni la IP,
 * participan en la decisión — `$clientDescription` solo se usa para la
 * etiqueta legible del dispositivo nuevo (`§B.6.4`).
 *
 * Se ejecuta después de verificar la credencial (`§B.4.5`, encabezado):
 * un intento fallido no llega a llamar a este servicio.
 */
final class DeviceRecognitionService
{
    public function recognize(
        User $user,
        ?string $deviceCookieValue,
        ?string $ipAddress,
        ClientDescription $clientDescription,
    ): DeviceRecognitionResult {
        if ($deviceCookieValue !== null) {
            $existing = UserKnownDevice::query()
                ->where('user_id', $user->id)
                ->where('device_token_hash', hash('sha256', $deviceCookieValue))
                ->first();

            if ($existing !== null) {
                $existing->last_seen_at = now();
                $existing->last_ip_address = $ipAddress;
                $existing->login_count = $existing->login_count + 1;
                $existing->save();

                return new DeviceRecognitionResult($existing, false, null, false);
            }
        }

        // RN-AUTH-46: sin cookie, o cookie que no corresponde a nada
        // nuestro (de otro usuario, de otro tenant, caducada en servidor o
        // manipulada) — dispositivo nuevo en cualquiera de los casos.
        $rawCookieValue = bin2hex(random_bytes(32));
        $cap = (int) config('auth-local.new_device_alerts_per_day');

        $alertsToday = UserKnownDevice::query()
            ->where('user_id', $user->id)
            ->whereDate('alerted_at', now()->toDateString())
            ->count();

        $shouldAlert = $alertsToday < $cap;

        $device = UserKnownDevice::create([
            'user_id' => $user->id,
            'device_token_hash' => hash('sha256', $rawCookieValue),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'login_count' => 1,
            'label' => trim("{$clientDescription->browser} · {$clientDescription->platform}"),
            'last_ip_address' => $ipAddress,
            // §B.4.5 punto 2.4: NULL cuando el tope diario impidió el
            // aviso — la distinción importa para poder auditarlo después.
            'alerted_at' => $shouldAlert ? now() : null,
        ]);

        return new DeviceRecognitionResult($device, true, $rawCookieValue, $shouldAlert);
    }
}
