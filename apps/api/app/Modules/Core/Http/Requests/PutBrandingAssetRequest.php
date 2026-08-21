<?php

namespace App\Modules\Core\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/**
 * api.md §2.2, `PUT /tenant/settings/assets/{kind}`. Solo valida la
 * presencia de forma; el tipo real, el tamaño por `kind` y el saneado de
 * SVG los resuelve `UploadBrandingAsset` (INV-010: no es una regla de
 * Laravel sobre un campo aislado, depende de qué `kind` se está subiendo).
 */
class PutBrandingAssetRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file'],
        ];
    }
}
