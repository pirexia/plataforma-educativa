<?php

namespace App\Modules\Auth\Infrastructure;

use RuntimeException;

/**
 * `operacion.md §E.2.1`, `§E.10.3`. Tres guardas, en **todos** los
 * entornos, lanzadas desde `AuthServiceProvider::boot()` antes de que la
 * aplicación sirva ninguna petición — mismo patrón que
 * `SessionEnvironmentGuard`. La primera es «la guarda más importante que
 * introduce este paso»: un proveedor de identidad simulado en producción
 * es una evasión completa de la autenticación.
 */
final class OAuthEnvironmentGuard
{
    public function verify(): void
    {
        $this->assertFakeDriverOnlyInDevelopment();
        $this->assertGoogleDriverHasSecret();
        $this->assertGoogleDriverRequiresHttps();
    }

    /**
     * `CA-AUTH-230`. Primera de las dos barreras contra que el proveedor
     * simulado llegue a producción — la segunda es que su ruta no se
     * registra fuera de `local`/`testing` (`routes.php`).
     */
    private function assertFakeDriverOnlyInDevelopment(): void
    {
        $driver = (string) config('auth-local.oauth.driver');

        if ($driver === 'fake' && ! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'AUTH_OAUTH_DRIVER=fake fuera de local/testing: el proveedor de identidad simulado es una '.
                'evasión completa de la autenticación (docs/modulos/REQ-AUTH/operacion.md §E.2.1, §E.10.3).'
            );
        }
    }

    private function assertGoogleDriverHasSecret(): void
    {
        $driver = (string) config('auth-local.oauth.driver');
        $secret = config('auth-local.oauth.google_client_secret');

        if ($driver === 'google' && ($secret === null || $secret === '')) {
            throw new RuntimeException(
                'AUTH_OAUTH_DRIVER=google sin AUTH_GOOGLE_CLIENT_SECRET: el sistema levantaría con el botón '.
                'pintado y todo el mundo terminaría en error_proveedor sin que nadie supiera por qué '.
                '(docs/modulos/REQ-AUTH/operacion.md §E.2.1).'
            );
        }
    }

    private function assertGoogleDriverRequiresHttps(): void
    {
        $driver = (string) config('auth-local.oauth.driver');

        if ($driver !== 'google' || app()->environment('local')) {
            return;
        }

        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME);

        if ($scheme !== 'https') {
            throw new RuntimeException(
                'AUTH_OAUTH_DRIVER=google con APP_URL sobre http fuera de local: Google no admite URIs de '.
                'redirección sin TLS (docs/modulos/REQ-AUTH/operacion.md §E.2.1).'
            );
        }
    }
}
