<?php

namespace App\Modules\Auth\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * `api.md §F.3`, `§G.2`, `POST /api/v1/identity-providers`. `is_enabled`
 * no se acepta a propósito (`funcional.md §F.4.1`): un proveedor nace
 * siempre no activo. `protocol` es obligatorio desde 1.4c y **sin valor
 * por defecto en la API**, aunque la columna lo tenga en el esquema:
 * obligar a decirlo impide que un cliente que no conoce el campo cree un
 * proveedor del protocolo equivocado sin enterarse.
 *
 * La validación de forma aquí es deliberadamente laxa en un punto: si
 * `protocol = "saml"` y vienen **los dos** `metadata_url`/`metadata_xml`
 * a la vez, esta clase no lo rechaza — eso produce el código cerrado
 * `metadatos_ambiguos` (`api.md §G.4`), que es de negocio
 * (`SamlIdentityProviderAdminService`), no de forma. Aquí solo se exige
 * que venga **al menos uno** de los dos.
 *
 * La validación del documento de descubrimiento/de los metadatos es de
 * negocio (`IdentityProviderService`/`SamlIdentityProviderAdminService`),
 * no de forma.
 */
class StoreIdentityProviderRequest extends ApiFormRequest
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
            'protocol' => ['required', 'string', Rule::in(['oidc', 'saml'])],
            'display_name' => ['required', 'string', 'max:255'],
            'allowed_email_domains' => ['sometimes', 'array'],
            'allowed_email_domains.*' => ['string', 'max:255'],
            'provisioning_mode' => ['sometimes', 'string', Rule::in(['desactivado', 'emparejamiento'])],
        ];

        if ($this->input('protocol') === 'saml') {
            return $common + [
                'metadata_url' => ['required_without:metadata_xml', 'sometimes', 'string', 'max:2048'],
                'metadata_xml' => ['required_without:metadata_url', 'sometimes', 'string', 'max:524288'],
                'email_attribute' => ['sometimes', 'nullable', 'string', 'max:255'],
                'sign_authn_requests' => ['sometimes', 'boolean'],
                // api.md §G.2: los campos OIDC se rechazan si vienen, no
                // se ignoran en silencio.
                'discovery_url' => ['prohibited'],
                'client_id' => ['prohibited'],
                'email_claim' => ['prohibited'],
                'claims_source' => ['prohibited'],
                'scopes' => ['prohibited'],
            ];
        }

        return $common + [
            'discovery_url' => ['required', 'string', 'max:2048', 'url'],
            'client_id' => ['required', 'string', 'max:255'],
            'email_claim' => ['sometimes', 'string', Rule::in(['email', 'preferred_username', 'upn'])],
            'claims_source' => ['sometimes', 'string', Rule::in(['id_token', 'userinfo'])],
            'scopes' => ['sometimes', 'array', 'min:1'],
            'scopes.*' => ['string', 'max:255'],
        ];
    }
}
