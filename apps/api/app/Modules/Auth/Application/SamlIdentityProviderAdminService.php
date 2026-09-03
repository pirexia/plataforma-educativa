<?php

namespace App\Modules\Auth\Application;

use App\Modules\Auth\Domain\Models\IdentityProvider;
use App\Modules\Auth\Domain\Protocol;
use App\Modules\Auth\Domain\SamlCertificateSource;
use App\Modules\Auth\Domain\SamlMetadata;
use App\Modules\Auth\Domain\SamlMetadataFailureCode;
use App\Modules\Auth\Domain\SamlMetadataValidationException;
use App\Modules\Auth\Domain\SamlMetadataValidator;
use App\Modules\Auth\Domain\SamlNameIdFormat;
use App\Support\Api\ApiException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * `funcional.md §G.4.1`, `api.md §G.2`. Alta y edición del catálogo para
 * `protocol = "saml"`, con la validación de metadatos síncrona
 * (`funcional.md §G.4.2`). Hermana de `IdentityProviderService`, con dos
 * diferencias: crea también la hija `saml_identity_provider_settings` y
 * una fila de `identity_provider_certificates` por cada certificado de
 * firma que los metadatos declaren (solo en el **alta**: revalidar
 * metadatos en un `PATCH` actualiza `issuer`/`authorization_endpoint`/
 * `name_id_format`, pero no toca certificados — eso es
 * `POST .../metadata-refreshes` o las dos operaciones dedicadas de
 * `IdentityProviderCertificateService`, mismo criterio que las
 * credenciales de 1.4b).
 *
 * `SamlMetadataValidationException` se deja propagar: el controlador la
 * traduce a `422` (o a `409` para `emisor_ya_catalogado`, el único código
 * de esta lista que es un conflicto de estado y no un cuerpo mal
 * formado — `api.md §G.4`).
 */
final class SamlIdentityProviderAdminService
{
    public function __construct(
        private readonly SamlMetadataValidator $validator,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws SamlMetadataValidationException
     * @throws ApiException validation() (422)
     */
    public function create(array $attributes): IdentityProvider
    {
        if (isset($attributes['metadata_url']) && isset($attributes['metadata_xml'])) {
            throw new SamlMetadataValidationException(SamlMetadataFailureCode::MetadatosAmbiguos);
        }

        $metadataSource = isset($attributes['metadata_url']) ? 'url' : 'xml';

        $metadata = $metadataSource === 'url'
            ? $this->validator->validateFromUrl($attributes['metadata_url'])
            : $this->validator->validateFromXml($attributes['metadata_xml']);

        $emailAttribute = Arr::get($attributes, 'email_attribute');
        $this->guardEmailSource($emailAttribute, $metadata->nameIdFormat);

        // Comprobación proactiva para dar el código exacto de §G.4; el
        // UNIQUE (tenant_id, issuer) —entre protocolos, CA-AUTH-315—
        // sigue siendo la garantía real y se atrapa como red de
        // seguridad si hay una carrera.
        $alreadyCatalogued = IdentityProvider::query()->where('issuer', $metadata->entityId)->exists();

        if ($alreadyCatalogued) {
            throw new SamlMetadataValidationException(SamlMetadataFailureCode::EmisorYaCatalogado);
        }

        try {
            return DB::transaction(function () use ($attributes, $metadata, $metadataSource, $emailAttribute): IdentityProvider {
                $provider = IdentityProvider::create([
                    'protocol' => Protocol::Saml,
                    'display_name' => $attributes['display_name'],
                    'issuer' => $metadata->entityId,
                    'authorization_endpoint' => $metadata->singleSignOnServiceUrl,
                    'allowed_email_domains' => $attributes['allowed_email_domains'] ?? [],
                    'provisioning_mode' => $attributes['provisioning_mode'] ?? 'desactivado',
                    // funcional.md §G.4.1: nace siempre no activo.
                    'is_enabled' => false,
                ]);

                $provider->samlSettings()->create([
                    'metadata_source' => $metadataSource,
                    'metadata_url' => $attributes['metadata_url'] ?? null,
                    'metadata_xml' => $attributes['metadata_xml'] ?? null,
                    'name_id_format' => $metadata->nameIdFormat,
                    'email_attribute' => $emailAttribute,
                    // funcional.md §G.4.1: nace siempre sin firmar.
                    'sign_authn_requests' => false,
                    'metadata_fetched_at' => now(),
                ]);

                $this->createCertificates($provider, $metadata);

                return $provider;
            });
        } catch (UniqueConstraintViolationException) {
            throw new SamlMetadataValidationException(SamlMetadataFailureCode::EmisorYaCatalogado);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws SamlMetadataValidationException
     * @throws ApiException conflict() (409), validation() (422)
     */
    public function update(IdentityProvider $provider, array $attributes): IdentityProvider
    {
        $settings = $provider->samlSettings;

        if (isset($attributes['metadata_url']) && isset($attributes['metadata_xml'])) {
            throw new SamlMetadataValidationException(SamlMetadataFailureCode::MetadatosAmbiguos);
        }

        $revalidate = isset($attributes['metadata_url']) || isset($attributes['metadata_xml']);
        $emailAttribute = array_key_exists('email_attribute', $attributes) ? $attributes['email_attribute'] : $settings->email_attribute;
        $nameIdFormat = $settings->name_id_format;
        // Definida siempre (aunque $revalidate sea false): el `use()` de
        // un closure solo admite nombres de variable, nunca una
        // expresión como `$metadata ?? null` — tiene que existir ya
        // como variable real antes de capturarla.
        $metadata = null;

        if ($revalidate) {
            $metadataSource = isset($attributes['metadata_url']) ? 'url' : 'xml';

            $metadata = $metadataSource === 'url'
                ? $this->validator->validateFromUrl($attributes['metadata_url'])
                : $this->validator->validateFromXml($attributes['metadata_xml']);

            $nameIdFormat = $metadata->nameIdFormat;
        }

        $this->guardEmailSource($emailAttribute, $nameIdFormat);

        if (($attributes['sign_authn_requests'] ?? null) === true && ! $this->platformSigningKeyConfigured()) {
            throw ApiException::conflict('auth.saml.sign_authn_requests_without_platform_key');
        }

        if (($attributes['is_enabled'] ?? null) === true && $provider->activeCertificates()->isEmpty()) {
            throw ApiException::conflict('auth.saml.identity_provider_enable_without_certificate');
        }

        try {
            DB::transaction(function () use ($provider, $settings, $attributes, $revalidate, $metadata, $emailAttribute): void {
                if ($revalidate) {
                    /** @var SamlMetadata $metadata */
                    $provider->fill([
                        'issuer' => $metadata->entityId,
                        'authorization_endpoint' => $metadata->singleSignOnServiceUrl,
                    ]);

                    $settings->fill([
                        'metadata_source' => isset($attributes['metadata_url']) ? 'url' : 'xml',
                        'metadata_url' => $attributes['metadata_url'] ?? null,
                        'metadata_xml' => $attributes['metadata_xml'] ?? null,
                        'name_id_format' => $metadata->nameIdFormat,
                        'metadata_fetched_at' => now(),
                        'metadata_failed_at' => null,
                    ]);
                }

                $settings->fill(Arr::only($attributes, ['sign_authn_requests']));

                if (array_key_exists('email_attribute', $attributes)) {
                    $settings->email_attribute = $emailAttribute;
                }

                $settings->save();

                $provider->fill(Arr::only($attributes, [
                    'display_name', 'allowed_email_domains', 'provisioning_mode', 'is_enabled',
                ]));
                $provider->save();
            });
        } catch (UniqueConstraintViolationException) {
            throw new SamlMetadataValidationException(SamlMetadataFailureCode::EmisorYaCatalogado);
        }

        return $provider->refresh();
    }

    public function destroy(IdentityProvider $provider): void
    {
        // api.md §G.2: borrado lógico. Los vínculos ya creados no se
        // borran ni se desconectan, mismo criterio que 1.4b.
        $provider->delete();
    }

    private function createCertificates(IdentityProvider $provider, SamlMetadata $metadata): void
    {
        foreach ($metadata->signingCertificates as $certificate) {
            $provider->certificates()->create([
                'certificate' => $certificate->pem,
                'fingerprint_sha256' => $certificate->fingerprintSha256,
                'not_before' => $certificate->notBefore,
                'not_after' => $certificate->notAfter,
                'source' => SamlCertificateSource::Metadata,
            ]);
        }
    }

    /**
     * `funcional.md §G.5.1`: `email_attribute` puede omitirse SOLO si el
     * `NameIDFormat` catalogado es `emailAddress` — mismo `CHECK` que
     * `datos.md §G.3` impone en el motor, devuelto aquí como `422` legible
     * en vez de una violación de restricción cruda.
     */
    private function guardEmailSource(mixed $emailAttribute, SamlNameIdFormat $nameIdFormat): void
    {
        if ($emailAttribute !== null) {
            return;
        }

        if ($nameIdFormat === SamlNameIdFormat::EmailAddress) {
            return;
        }

        throw ApiException::validation([
            'email_attribute' => [[
                'code' => 'auth.validation.saml_email_attribute_required',
                'message' => __('auth.validation.saml_email_attribute_required'),
                'params' => [],
            ]],
        ]);
    }

    private function platformSigningKeyConfigured(): bool
    {
        $path = (string) config('auth-local.saml.sp_signing_key_path');

        return $path !== '' && is_readable($path);
    }
}
