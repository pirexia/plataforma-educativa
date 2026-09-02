<?php

namespace App\Modules\Auth\Infrastructure;

use Illuminate\Support\Facades\Cache;

/**
 * `operacion.md §G.10.2` (REQ-AUTH-004, 1.4c). El certificado y la clave
 * del IdP SAML simulado son de desarrollo, se generan al primer uso del
 * entorno y **nunca se commitean** (`CLAUDE.md §4`) ni son los mismos que
 * ningún material de producción. Se cachean con `Cache::rememberForever()`
 * —almacén de `storage/framework/cache` en desarrollo, ya excluido del
 * repositorio por el `.gitignore` estándar de Laravel— porque el flujo
 * cruza dos peticiones (la del `AuthnRequest` y la del `SAMLResponse`) y
 * la clave tiene que ser estable entre ellas y también estable entre el
 * momento en que un administrador de prueba cataloga el proveedor —leyendo
 * `GET /_sso-simulator/saml/metadata`— y el momento en que completa un
 * acceso.
 */
final class FakeSamlKeyMaterial
{
    private const CACHE_KEY = 'auth.saml.fake_idp.key_material';

    /**
     * @return array{key: string, cert: string}
     */
    public static function get(): array
    {
        /** @var array{key: string, cert: string} */
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            $key = openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]);

            $csr = openssl_csr_new(['CN' => 'saml-simulator.local'], $key, ['digest_alg' => 'sha256']);
            $cert = openssl_csr_sign($csr, null, $key, 3650, ['digest_alg' => 'sha256']);

            openssl_pkey_export($key, $keyPem);
            openssl_x509_export($cert, $certPem);

            return ['key' => $keyPem, 'cert' => $certPem];
        });
    }
}
