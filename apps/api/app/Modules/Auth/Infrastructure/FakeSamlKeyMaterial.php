<?php

namespace App\Modules\Auth\Infrastructure;

use RuntimeException;

/**
 * `operacion.md §G.10.2` (REQ-AUTH-004, 1.4c). El certificado y la clave
 * del IdP SAML simulado son de desarrollo, se generan al primer uso del
 * entorno y **nunca se commitean** (`CLAUDE.md §4`) ni son los mismos que
 * ningún material de producción.
 *
 * **Persistidos en un fichero fijo bajo `storage/framework/testing/`**,
 * no en `Cache::rememberForever()` (así empezó esta clase). El flujo
 * cruza dos **procesos** distintos, no solo dos peticiones: el
 * `artisan serve` de desarrollo (que sirve `GET .../metadata` cuando un
 * administrador cataloga el proveedor) y el proceso de Pest que completa
 * el acceso son procesos de PHP separados, y `phpunit.xml` fuerza
 * `CACHE_STORE=redis` **solo dentro del proceso de Pest** — el mismo
 * hueco de entorno, en la misma forma exacta, que ya obligó a fijar
 * `DB_DATABASE` a nivel de paso en CI para 1.4b, no solo dentro de
 * `phpunit.xml`. Con `Cache::rememberForever()`, cada proceso podía
 * generar su propia clave sin que el otro se enterase: el certificado
 * catalogado (fetched vía HTTP real desde `artisan serve`) no
 * correspondía a la clave usada para firmar el `SAMLResponse` (generada
 * en el proceso de Pest), y toda firma fallaba la verificación sin que
 * el fallo dijera por qué — bug real encontrado escribiendo los tests
 * del ACS (issue #155). Un fichero en el sistema de ficheros del propio
 * contenedor, compartido por ambos procesos sin depender de qué motor de
 * caché tenga configurado cada uno, no tiene ese problema.
 *
 * Generación protegida con `flock()`: el primer proceso que llega genera
 * y escribe; cualquier otro que llegue mientras tanto espera el bloqueo
 * y relee el fichero ya escrito, en vez de generar una clave propia.
 */
final class FakeSamlKeyMaterial
{
    private const KEY_PATH = 'framework/testing/saml-fake-idp/key.pem';

    private const CERT_PATH = 'framework/testing/saml-fake-idp/cert.pem';

    /**
     * @return array{key: string, cert: string}
     */
    public static function get(): array
    {
        $keyPath = storage_path(self::KEY_PATH);
        $certPath = storage_path(self::CERT_PATH);

        $existing = self::readIfPresent($keyPath, $certPath);

        if ($existing !== null) {
            return $existing;
        }

        return self::generateAndPersist($keyPath, $certPath);
    }

    /**
     * @return array{key: string, cert: string}|null
     */
    private static function readIfPresent(string $keyPath, string $certPath): ?array
    {
        if (! is_file($keyPath) || ! is_file($certPath)) {
            return null;
        }

        $key = file_get_contents($keyPath);
        $cert = file_get_contents($certPath);

        if ($key === false || $cert === false || $key === '' || $cert === '') {
            return null;
        }

        return ['key' => $key, 'cert' => $cert];
    }

    /**
     * @return array{key: string, cert: string}
     */
    private static function generateAndPersist(string $keyPath, string $certPath): array
    {
        if (! is_dir(dirname($keyPath))) {
            mkdir(dirname($keyPath), 0775, true);
        }

        $lock = fopen($keyPath.'.lock', 'c');

        if ($lock === false) {
            throw new RuntimeException("No se pudo abrir el fichero de bloqueo del material del IdP SAML simulado: {$keyPath}.lock");
        }

        flock($lock, LOCK_EX);

        try {
            // Otro proceso pudo generarlo y escribirlo mientras
            // esperábamos el bloqueo — no generar una segunda clave.
            $existing = self::readIfPresent($keyPath, $certPath);

            if ($existing !== null) {
                return $existing;
            }

            $privateKey = openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]);

            $csr = openssl_csr_new(['CN' => 'saml-simulator.local'], $privateKey, ['digest_alg' => 'sha256']);
            $x509 = openssl_csr_sign($csr, null, $privateKey, 3650, ['digest_alg' => 'sha256']);

            openssl_pkey_export($privateKey, $keyPem);
            openssl_x509_export($x509, $certPem);

            file_put_contents($keyPath, $keyPem, LOCK_EX);
            file_put_contents($certPath, $certPem, LOCK_EX);

            return ['key' => $keyPem, 'cert' => $certPem];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
