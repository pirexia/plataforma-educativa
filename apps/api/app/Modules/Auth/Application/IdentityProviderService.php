<?php

namespace App\Modules\Auth\Application;

use App\Modules\Auth\Domain\DiscoveryDocumentValidator;
use App\Modules\Auth\Domain\DiscoveryValidationException;
use App\Modules\Auth\Domain\Models\IdentityProvider;
use App\Support\Api\ApiException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * `funcional.md §F.4.1`, `api.md §F.3`. Alta y edición del catálogo, con
 * la validación de metadatos síncrona (`funcional.md §F.4.2`).
 * `DiscoveryValidationException` se deja propagar: el controlador la
 * traduce al `422` de forma con `errors.discovery_url` (`api.md §F.3`).
 */
final class IdentityProviderService
{
    public function __construct(
        private readonly DiscoveryDocumentValidator $discoveryValidator,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws DiscoveryValidationException
     */
    public function create(array $attributes): IdentityProvider
    {
        // Efecto obligatorio y síncrono (api.md §F.3): si falla, no se
        // crea nada.
        $document = $this->discoveryValidator->validate($attributes['discovery_url']);

        try {
            return DB::transaction(function () use ($attributes, $document): IdentityProvider {
                return IdentityProvider::create([
                    'display_name' => $attributes['display_name'],
                    'discovery_url' => $attributes['discovery_url'],
                    'issuer' => $document->issuer,
                    'authorization_endpoint' => $document->authorizationEndpoint,
                    'token_endpoint' => $document->tokenEndpoint,
                    'userinfo_endpoint' => $document->userinfoEndpoint,
                    'claims_source' => $attributes['claims_source'] ?? 'id_token',
                    'email_claim' => $attributes['email_claim'] ?? 'email',
                    'scopes' => $attributes['scopes'] ?? ['openid', 'email', 'profile'],
                    'client_id' => $attributes['client_id'],
                    'allowed_email_domains' => $attributes['allowed_email_domains'] ?? [],
                    'provisioning_mode' => $attributes['provisioning_mode'] ?? 'desactivado',
                    // funcional.md §F.4.1: is_enabled no se acepta en el
                    // alta. Nace siempre no activo.
                    'is_enabled' => false,
                    'discovery_fetched_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            throw ApiException::conflict('auth.sso.identity_provider_issuer_already_catalogued');
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws DiscoveryValidationException
     */
    public function update(IdentityProvider $provider, array $attributes): IdentityProvider
    {
        if (array_key_exists('discovery_url', $attributes)) {
            // api.md §F.3: si falla, no se cambia nada, ni siquiera los
            // campos que sí eran válidos — se valida antes de tocar el
            // modelo.
            $document = $this->discoveryValidator->validate($attributes['discovery_url']);

            $provider->fill([
                'discovery_url' => $attributes['discovery_url'],
                'issuer' => $document->issuer,
                'authorization_endpoint' => $document->authorizationEndpoint,
                'token_endpoint' => $document->tokenEndpoint,
                'userinfo_endpoint' => $document->userinfoEndpoint,
                'discovery_fetched_at' => now(),
                'discovery_failed_at' => null,
            ]);
        }

        if (($attributes['is_enabled'] ?? null) === true && $provider->activeSecret() === null) {
            throw ApiException::conflict('auth.sso.identity_provider_enable_without_secret');
        }

        $provider->fill(Arr::only($attributes, [
            'display_name', 'client_id', 'email_claim', 'claims_source',
            'scopes', 'allowed_email_domains', 'provisioning_mode', 'is_enabled',
        ]));

        try {
            $provider->save();
        } catch (UniqueConstraintViolationException $e) {
            throw ApiException::conflict('auth.sso.identity_provider_issuer_already_catalogued');
        }

        return $provider;
    }

    public function destroy(IdentityProvider $provider): void
    {
        // api.md §F.3: borrado lógico. Los vínculos ya creados no se
        // borran ni se desconectan (datos.md §F.4.2, sin ON DELETE).
        $provider->delete();
    }
}
