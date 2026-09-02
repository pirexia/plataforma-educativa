<?php

namespace App\Modules\Auth\Application;

use App\Modules\Auth\Domain\Models\IdentityProvider;
use App\Modules\Auth\Domain\SamlCertificateSource;
use App\Modules\Auth\Domain\SamlMetadataValidationException;
use App\Modules\Auth\Domain\SamlMetadataValidator;
use Illuminate\Support\Facades\DB;

/**
 * `funcional.md §G.4.2`, `api.md §G.4`, `operacion.md §G.4` (REQ-AUTH-004,
 * 1.4c). Revalida los metadatos de un proveedor SAML de origen URL y
 * actualiza `issuer`/`authorization_endpoint`/`name_id_format` —usado por
 * `POST .../metadata-refreshes` (síncrono) y por
 * `RefreshSamlMetadataCommand` (programado)—. Hermana de
 * `DiscoveryRefreshService`, con una diferencia deliberada:
 * **el refresco añade certificados, nunca los retira** (`RN-AUTH-125`,
 * `CA-AUTH-325`) — retirar uno es siempre un acto del administrador.
 */
final class SamlMetadataRefreshService
{
    public function __construct(
        private readonly SamlMetadataValidator $validator,
    ) {}

    /**
     * @throws SamlMetadataValidationException
     */
    public function refresh(IdentityProvider $provider): IdentityProvider
    {
        $settings = $provider->samlSettings;

        try {
            $metadata = $this->validator->validateFromUrl((string) $settings->metadata_url);
        } catch (SamlMetadataValidationException $e) {
            $settings->forceFill(['metadata_failed_at' => now()])->save();

            throw $e;
        }

        DB::transaction(function () use ($provider, $settings, $metadata): void {
            $provider->fill([
                'issuer' => $metadata->entityId,
                'authorization_endpoint' => $metadata->singleSignOnServiceUrl,
            ])->save();

            $settings->fill([
                'name_id_format' => $metadata->nameIdFormat,
                'metadata_fetched_at' => now(),
                'metadata_failed_at' => null,
            ])->save();

            $existingFingerprints = $provider->certificates()
                ->pluck('fingerprint_sha256')
                ->all();

            foreach ($metadata->signingCertificates as $certificate) {
                if (in_array($certificate->fingerprintSha256, $existingFingerprints, true)) {
                    continue;
                }

                $provider->certificates()->create([
                    'certificate' => $certificate->pem,
                    'fingerprint_sha256' => $certificate->fingerprintSha256,
                    'not_before' => $certificate->notBefore,
                    'not_after' => $certificate->notAfter,
                    'source' => SamlCertificateSource::Metadata,
                ]);

                $existingFingerprints[] = $certificate->fingerprintSha256;
            }
        });

        return $provider->refresh();
    }
}
