<?php

namespace App\Modules\Auth\Application;

use App\Modules\Auth\Domain\Models\IdentityProvider;
use App\Modules\Auth\Domain\Models\IdentityProviderSecret;
use App\Support\Api\ApiException;
use Illuminate\Support\Carbon;

/**
 * `funcional.md §F.3.5`, `api.md §F.4`. Carga y retirada de la credencial
 * de cliente. Ningún método de esta clase la devuelve en claro
 * (`RN-AUTH-112`).
 */
final class IdentityProviderSecretService
{
    public function store(IdentityProvider $provider, string $clientSecret, ?Carbon $expiresAt): IdentityProviderSecret
    {
        // datos.md §F.3: cargar una credencial nueva no retira la
        // anterior. Las dos quedan activas — la ventana de rotación.
        return IdentityProviderSecret::create([
            'identity_provider_id' => $provider->id,
            'client_secret' => $clientSecret,
            'expires_at' => $expiresAt,
            'activated_at' => now(),
        ]);
    }

    public function retire(IdentityProvider $provider, IdentityProviderSecret $secret): void
    {
        if ($provider->is_enabled) {
            $otherActiveExists = $provider->secrets()
                ->whereNull('retired_at')
                ->where('id', '!=', $secret->id)
                ->exists();

            // api.md §F.4: retirar la última vigente de un proveedor
            // activo dejaría el proveedor pintado y roto.
            if (! $otherActiveExists) {
                throw ApiException::conflict('auth.sso.identity_provider_secret_last_active');
            }
        }

        $secret->retired_at = now();
        $secret->save();
    }
}
