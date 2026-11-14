<?php

namespace App\Modules\Auth\Infrastructure;

use RuntimeException;

/**
 * `operacion.md §F.2.1`. Guarda de arranque, en **todos** los entornos,
 * lanzada desde `AuthServiceProvider::boot()` — mismo patrón que
 * `OAuthEnvironmentGuard`. Es la guarda más importante que introduce
 * 1.4b: con `http` admitido en el descubrimiento, el documento que
 * decide dónde se autentica el personal de un centro viaja en claro.
 *
 * `AUTH_SSO_ALLOW_INSECURE_DISCOVERY=false` —el valor por defecto— no
 * dispara nada, en ningún entorno (`CA-AUTH-310`, mismo criterio que
 * `AUTH_OAUTH_DRIVER=none`, issue #140).
 */
final class SsoEnvironmentGuard
{
    public function verify(): void
    {
        $insecure = (bool) config('auth-local.sso.allow_insecure_discovery');

        if ($insecure && ! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'AUTH_SSO_ALLOW_INSECURE_DISCOVERY=true fuera de local/testing: el documento de descubrimiento '.
                'que decide dónde se autentica el personal de un centro viajaría en claro y las guardas contra '.
                'SSRF quedarían aflojadas (docs/modulos/REQ-AUTH/operacion.md §F.2.1, funcional.md §F.4.2).'
            );
        }
    }
}
