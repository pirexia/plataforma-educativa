<?php

namespace App\Modules\Core\Http\Resources;

use App\Modules\Core\Domain\Models\TenantSetting;
use App\Modules\Core\Infrastructure\BrandingAssetUrlSigner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * api.md §2, `GET /tenant/settings`. Recurso desnudo (ADR-038 §3.1). Las
 * tres URLs de branding se regeneran en cada respuesta, firmadas y de
 * caducidad corta — nunca se cachean más allá de su vencimiento.
 *
 * @mixin TenantSetting
 */
class TenantSettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $signer = app(BrandingAssetUrlSigner::class);

        return [
            'public_id' => $this->public_id,
            'regional' => [
                'default_locale' => $this->default_locale,
                'active_locales' => $this->active_locales,
                'timezone' => $this->timezone,
                'currency' => $this->currency,
                'autonomous_community' => $this->autonomous_community,
            ],
            'fiscal' => [
                'legal_name' => $this->legal_name,
                'tax_id' => $this->tax_id,
                'address' => $this->fiscal_address,
                'postal_code' => $this->fiscal_postal_code,
                'city' => $this->fiscal_city,
                'province' => $this->fiscal_province,
                'country_code' => $this->fiscal_country_code,
            ],
            'branding' => [
                'color_primary' => $this->color_primary,
                'color_secondary' => $this->color_secondary,
                'logo_url' => $signer->sign($this->logo_object_key),
                'favicon_url' => $signer->sign($this->favicon_object_key),
                'login_background_url' => $signer->sign($this->login_background_object_key),
            ],
            'updated_at' => $this->updated_at,
        ];
    }
}
