<?php

namespace App\Modules\Auth\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use App\Modules\Auth\Domain\Models\IdentityProvider;
use App\Modules\Auth\Domain\Protocol;
use Illuminate\Validation\Rule;

/**
 * `api.md §F.3`, `§G.2`, `PATCH /api/v1/identity-providers/{public_id}`.
 * Si viene `discovery_url`/`metadata_url`/`metadata_xml`, se revalida
 * entero (`IdentityProviderService`/`SamlIdentityProviderAdminService`).
 * `is_enabled: true` exige credencial/certificado vigente — comprobación
 * de negocio, no de forma.
 *
 * `protocol` en el cuerpo ⇒ `422`, **siempre**, aunque el valor coincida
 * con el actual (`RN-AUTH-114`, `CA-AUTH-316`): cambiar de protocolo no
 * es editar una fila, es dar de alta otro proveedor.
 */
class UpdateIdentityProviderRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $common = [
            'protocol' => ['prohibited'],
            'display_name' => ['sometimes', 'string', 'max:255'],
            'allowed_email_domains' => ['sometimes', 'array'],
            'allowed_email_domains.*' => ['string', 'max:255'],
            'provisioning_mode' => ['sometimes', 'string', Rule::in(['desactivado', 'emparejamiento'])],
            'is_enabled' => ['sometimes', 'boolean'],
        ];

        if ($this->targetProtocol() === Protocol::Saml) {
            return $common + [
                'metadata_url' => ['sometimes', 'string', 'max:2048'],
                'metadata_xml' => ['sometimes', 'string', 'max:524288'],
                'email_attribute' => ['sometimes', 'nullable', 'string', 'max:255'],
                'sign_authn_requests' => ['sometimes', 'boolean'],
                'discovery_url' => ['prohibited'],
                'client_id' => ['prohibited'],
                'email_claim' => ['prohibited'],
                'claims_source' => ['prohibited'],
                'scopes' => ['prohibited'],
            ];
        }

        return $common + [
            'discovery_url' => ['sometimes', 'string', 'max:2048', 'url'],
            'client_id' => ['sometimes', 'string', 'max:255'],
            'email_claim' => ['sometimes', 'string', Rule::in(['email', 'preferred_username', 'upn'])],
            'claims_source' => ['sometimes', 'string', Rule::in(['id_token', 'userinfo'])],
            'scopes' => ['sometimes', 'array', 'min:1'],
            'scopes.*' => ['string', 'max:255'],
        ];
    }

    /**
     * `null` si el `public_id` de la ruta no resuelve — en ese caso las
     * reglas OIDC (las históricas) se aplican por defecto y el
     * controlador responde `404` de todas formas al no encontrar la fila.
     */
    private function targetProtocol(): ?Protocol
    {
        $publicId = (string) $this->route('publicId');
        $value = IdentityProvider::query()->where('public_id', $publicId)->value('protocol');

        return is_string($value) ? Protocol::from($value) : null;
    }
}
