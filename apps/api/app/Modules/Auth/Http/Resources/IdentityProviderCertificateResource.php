<?php

namespace App\Modules\Auth\Http\Resources;

use App\Modules\Auth\Domain\Models\IdentityProviderCertificate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `api.md §G.2`, `§G.5`. Nunca el PEM (`RN-AUTH-127`): el certificado es
 * material público, pero la lista de administración no tiene por qué
 * repetir un bloque de kilobytes por fila — la huella es lo que
 * identifica a un certificado de un vistazo.
 *
 * @mixin IdentityProviderCertificate
 */
class IdentityProviderCertificateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'fingerprint_sha256' => $this->fingerprint_sha256,
            'not_before' => $this->not_before->toISOString(),
            'not_after' => $this->not_after->toISOString(),
            'source' => $this->source->value,
            'retired_at' => $this->retired_at?->toISOString(),
        ];
    }
}
