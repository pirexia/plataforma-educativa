<?php

namespace App\Modules\Auth\Infrastructure;

use App\Modules\Auth\Domain\SamlMetadataCertificate;
use Carbon\CarbonImmutable;

/**
 * `RN-AUTH-126` (REQ-AUTH-004, 1.4c). Analiza un certificado X.509 de
 * firma de un IdP y extrae vigencia y huella **del propio certificado**,
 * nunca tecleadas. Compartido por `CurlSamlMetadataValidator` (certificados
 * que llegan en los metadatos, `CA-AUTH-324`/`CA-AUTH-325`) y
 * `IdentityProviderCertificateService` (carga manual,
 * `CA-AUTH-328`/`CA-AUTH-329`): un certificado no analizable, ya
 * caducado, o cuya clave no alcance `AUTH_SAML_MIN_CERTIFICATE_KEY_BITS`
 * se rechaza al cargarlo por el mismo motivo y con el mismo código,
 * venga de donde venga.
 */
final class SamlCertificateParser
{
    /**
     * `$base64` admite tanto el contenido plano de un `ds:X509Certificate`
     * de metadatos como un PEM completo pegado a mano (con o sin
     * cabeceras `-----BEGIN/END CERTIFICATE-----`).
     */
    public function parse(string $base64): ?SamlMetadataCertificate
    {
        $stripped = preg_replace('/-----(BEGIN|END) CERTIFICATE-----/', '', $base64) ?? $base64;
        $stripped = preg_replace('/\s+/', '', $stripped) ?? '';

        if ($stripped === '') {
            return null;
        }

        $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split($stripped, 64, "\n")."-----END CERTIFICATE-----\n";

        $resource = @openssl_x509_read($pem);

        if ($resource === false) {
            return null;
        }

        $parsed = @openssl_x509_parse($resource);

        if ($parsed === false || ! isset($parsed['validFrom_time_t'], $parsed['validTo_time_t'])) {
            return null;
        }

        $publicKey = @openssl_pkey_get_public($resource);
        $keyBits = $publicKey !== false ? (int) (openssl_pkey_get_details($publicKey)['bits'] ?? 0) : 0;
        $minBits = (int) config('auth-local.saml.min_certificate_key_bits');

        if ($keyBits < $minBits) {
            return null;
        }

        $notBefore = CarbonImmutable::createFromTimestampUTC($parsed['validFrom_time_t']);
        $notAfter = CarbonImmutable::createFromTimestampUTC($parsed['validTo_time_t']);

        if ($notAfter->isPast()) {
            return null;
        }

        $fingerprint = openssl_x509_fingerprint($resource, 'sha256');

        if ($fingerprint === false) {
            return null;
        }

        return new SamlMetadataCertificate(
            pem: $pem,
            fingerprintSha256: $fingerprint,
            notBefore: $notBefore,
            notAfter: $notAfter,
        );
    }
}
