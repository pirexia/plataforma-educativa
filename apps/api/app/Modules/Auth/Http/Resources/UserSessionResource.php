<?php

namespace App\Modules\Auth\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * api.md §B.2, `GET /auth/sessions`. El recurso se construye en el
 * controlador como array plano (no directamente desde `UserSession`):
 * `current` y `last_activity_at` son derivados que combinan la fila de
 * `user_sessions` con `sessions.last_activity` del framework, y
 * `location` es siempre `null` en 1.2b (`RN-AUTH-47`). Nunca aparece el
 * identificador de sesión, el *payload*, ni material de la cookie de
 * dispositivo (`RN-AUTH-40`, `CA-AUTH-083`).
 *
 * @mixin array{public_id: string, current: bool, started_at: \Illuminate\Support\Carbon, last_activity_at: \Illuminate\Support\Carbon, ip_address: ?string, client: array{browser: string, platform: string, device_type: string}, location: ?string, device_known: bool}
 */
class UserSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this['public_id'],
            'current' => $this['current'],
            'started_at' => $this['started_at'],
            'last_activity_at' => $this['last_activity_at'],
            'ip_address' => $this['ip_address'],
            'client' => $this['client'],
            'location' => $this['location'],
            'device_known' => $this['device_known'],
        ];
    }
}
