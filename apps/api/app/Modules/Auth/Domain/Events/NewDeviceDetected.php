<?php

namespace App\Modules\Auth\Domain\Events;

use Illuminate\Support\Carbon;

/**
 * funcional.md §B.4.5, §B.8.2. Acceso desde un dispositivo no reconocido.
 * Consumidor previsto: REQ-COM (1.19), que sustituirá el envío directo de
 * correo de 1.2b igual que sustituirá el de AccountLocked. Se publica
 * siempre que se detecta el dispositivo nuevo, también cuando el tope
 * diario de RN-AUTH-46 impide el aviso por correo (`alerted` distingue
 * los dos casos).
 */
final class NewDeviceDetected
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $userPublicId,
        public readonly Carbon $occurredAt,
        public readonly bool $alerted,
    ) {}
}
