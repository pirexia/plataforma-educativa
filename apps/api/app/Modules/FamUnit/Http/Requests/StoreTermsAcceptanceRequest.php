<?php

namespace App\Modules\FamUnit\Http\Requests;

use App\Support\Api\ValidationErrorBag;

/**
 * REQ-FAM-UNIT/api.md §2.8, `POST /me/terms-acceptances`: cuerpo `{ "terms_version": "<public_id>" }`. El idioma en que
 * se sirvió el texto (`shown_locale`) lo decide el servidor, no el cliente (`RN-FAM-UNIT-65`).
 */
class StoreTermsAcceptanceRequest extends FamUnitRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }

    public function termsVersion(): string
    {
        $errors = new ValidationErrorBag;
        $reference = self::requiredReference($this->body(), 'terms_version', $errors);
        $errors->throwIfAny();

        return (string) $reference;
    }
}
