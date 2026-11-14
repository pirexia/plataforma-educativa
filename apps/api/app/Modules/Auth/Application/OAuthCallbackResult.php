<?php

namespace App\Modules\Auth\Application;

use App\Modules\Auth\Domain\OAuthCallbackOutcome;

/**
 * Resultado de `GoogleOAuthCallbackService::handle()`. `outcome === null`
 * es el único caso de éxito de login completo (`api.md §E.4.2`: "ninguno,
 * redirección al destino") — el controlador decide entonces la ruta de
 * destino de la SPA; para cualquier otro código construye
 * `/entrar/google?resultado=<código>` (`RN-AUTH-93`).
 *
 * `newDeviceCookieValue` solo se rellena cuando el propio *outcome* es
 * `null` (sesión creada) o `AltaMfaRequerida` (sesión creada, restringida):
 * son los dos únicos casos en los que `AuthenticatedSessionEstablisher`
 * llegó a ejecutarse.
 */
final class OAuthCallbackResult
{
    private function __construct(
        public readonly ?OAuthCallbackOutcome $outcome,
        public readonly ?string $newDeviceCookieValue = null,
    ) {}

    public static function success(?string $newDeviceCookieValue): self
    {
        return new self(null, $newDeviceCookieValue);
    }

    public static function outcome(OAuthCallbackOutcome $outcome, ?string $newDeviceCookieValue = null): self
    {
        return new self($outcome, $newDeviceCookieValue);
    }
}
