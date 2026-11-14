<?php

namespace App\Modules\Auth\Infrastructure;

use RuntimeException;

/**
 * `operacion.md §G.2.2` (REQ-AUTH-004, 1.4c). Las dos primeras de las tres
 * guardas de arranque del paso, en **todos** los entornos — mismo patrón
 * que `SsoEnvironmentGuard`. La tercera (la ruta del IdP simulado no
 * registrada fuera de `local`/`testing`) vive en `routes/api.php`, junto
 * a su hermana de OIDC.
 *
 * **Ninguna de las dos dispara nada por defecto** (`CA-AUTH-365`, lección
 * del issue #140): `AUTH_SAML_ALLOW_INSECURE_METADATA` vale `false` y
 * `AUTH_SAML_SP_SIGNING_KEY_PATH` vale vacío.
 */
final class SamlEnvironmentGuard
{
    public function verify(): void
    {
        $this->guardInsecureMetadata();
        $this->guardSigningKeyReadable();
    }

    /**
     * 1. Con `http` admitido, el documento que declara con qué
     * certificado se verifica quién entra en un centro viaja en claro.
     * Es la hermana exacta de la guarda de `SsoEnvironmentGuard`, y aquí
     * es peor: en OIDC lo que se reescribiría son URLs; aquí es material
     * criptográfico de confianza.
     */
    private function guardInsecureMetadata(): void
    {
        $insecure = (bool) config('auth-local.saml.allow_insecure_metadata');

        if ($insecure && ! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'AUTH_SAML_ALLOW_INSECURE_METADATA=true fuera de local/testing: el documento de metadatos que '.
                'declara con qué certificado se verifica quién entra en un centro viajaría en claro '.
                '(docs/modulos/REQ-AUTH/operacion.md §G.2.2).'
            );
        }
    }

    /**
     * 2. Una clave configurada y ausente es peor que ninguna clave: los
     * proveedores con `sign_authn_requests = true` fallarían en el
     * camino del acceso, uno a uno, sin que nada lo agregue. Fallar al
     * arrancar es fallar donde alguien lo ve.
     */
    private function guardSigningKeyReadable(): void
    {
        $path = (string) config('auth-local.saml.sp_signing_key_path');

        if ($path === '') {
            return;
        }

        if (! is_readable($path)) {
            throw new RuntimeException(
                "AUTH_SAML_SP_SIGNING_KEY_PATH={$path} configurada pero el fichero no es legible: los proveedores ".
                'con sign_authn_requests activo fallarían uno a uno en el camino del acceso '.
                '(docs/modulos/REQ-AUTH/operacion.md §G.2.2).'
            );
        }
    }
}
