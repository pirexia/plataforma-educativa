<?php

namespace App\Modules\Auth\Application;

use App\Modules\Auth\Domain\Models\UserSession;

/**
 * `newDeviceCookieValue` solo viene informado cuando el login se hizo
 * desde un dispositivo no reconocido — es el valor en claro que
 * `SessionController::store()` debe emitir como cookie `pge_device` en la
 * respuesta (`api.md §B.6`). `null` en cualquier otro caso.
 */
final class SessionRegistrationResult
{
    public function __construct(
        public readonly UserSession $session,
        public readonly ?string $newDeviceCookieValue,
    ) {}
}
