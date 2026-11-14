<?php

namespace App\Modules\Auth\Application;

use App\Modules\Auth\Domain\Models\IdentityProvider;
use App\Modules\Auth\Domain\Models\IdentityProviderCertificate;
use App\Modules\Auth\Domain\Protocol;
use App\Modules\Auth\Domain\SamlCertificateSource;
use App\Modules\Auth\Infrastructure\SamlCertificateParser;
use App\Support\Api\ApiException;

/**
 * `api.md §G.5`, `funcional.md §G.4.1` (REQ-AUTH-004, 1.4c). Carga y
 * retirada manual de un certificado de firma del IdP. Sin permiso propio
 * (`permisos.md §G.4`): las dos operaciones viajan con
 * `proveedor_identidad.actualizar`, mismo criterio que las credenciales
 * de 1.4b.
 */
final class IdentityProviderCertificateService
{
    public function __construct(
        private readonly SamlCertificateParser $parser,
    ) {}

    /**
     * @throws ApiException conflict() (409), validation() (422)
     */
    public function store(IdentityProvider $provider, string $certificatePem): IdentityProviderCertificate
    {
        if ($provider->protocol !== Protocol::Saml) {
            // Un proveedor OIDC no tiene certificados de firma del
            // emisor: aceptar la fila crearía un estado que ningún
            // camino de código lee (datos.md §G.9).
            throw ApiException::conflict('auth.saml.certificate_provider_not_saml');
        }

        // RN-AUTH-126, CA-AUTH-328/CA-AUTH-329: not_before/not_after se
        // extraen del propio certificado, nunca del cuerpo de la
        // petición (que ni siquiera se lee para eso).
        $parsed = $this->parser->parse($certificatePem);

        if ($parsed === null) {
            throw ApiException::validation([
                'certificate' => [[
                    'code' => 'auth.validation.saml_certificate_invalid',
                    'message' => __('auth.validation.saml_certificate_invalid'),
                    'params' => [],
                ]],
            ]);
        }

        $duplicate = $provider->certificates()
            ->where('fingerprint_sha256', $parsed->fingerprintSha256)
            ->exists();

        if ($duplicate) {
            throw ApiException::conflict('auth.saml.certificate_already_catalogued');
        }

        return $provider->certificates()->create([
            'certificate' => $parsed->pem,
            'fingerprint_sha256' => $parsed->fingerprintSha256,
            'not_before' => $parsed->notBefore,
            'not_after' => $parsed->notAfter,
            'source' => SamlCertificateSource::Manual,
        ]);
    }

    /**
     * `RN-AUTH-128`, `CA-AUTH-330`: retirar el último certificado vigente
     * de un proveedor **activo** deja un proveedor pintado y roto.
     *
     * @throws ApiException conflict() (409)
     */
    public function retire(IdentityProvider $provider, IdentityProviderCertificate $certificate): void
    {
        if ($provider->is_enabled) {
            // datos.md §G.5: "vigente" exige estar dentro de not_before/
            // not_after, no solo no retirado — un certificado ya caducado
            // no protege nada y no cuenta como respaldo (mismo criterio
            // que IdentityProvider::activeCertificates()).
            $otherActiveExists = $provider->activeCertificates()
                ->where('id', '!=', $certificate->id)
                ->isNotEmpty();

            if (! $otherActiveExists) {
                throw ApiException::conflict('auth.saml.certificate_last_active');
            }
        }

        // api.md §G.5: retira (retired_at) Y ADEMÁS borrado lógico. Una
        // fila retirada no se usa jamás, aunque siga vigente por fecha.
        $certificate->retired_at = now();
        $certificate->save();
        $certificate->delete();
    }
}
