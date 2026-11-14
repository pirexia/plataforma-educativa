<?php

namespace App\Modules\FamUnit\Http\Requests;

use App\Support\Api\ValidationErrorBag;

/**
 * REQ-FAM-UNIT/api.md §2.8, `POST /terms-versions/{public_id}/publish`: cuerpo opcional `{ "requires_reacceptance": bool }`.
 * Solo tiene sentido en `condiciones_uso` (`1.14-c`); en un tipo de imagen debe omitirse o ser `false` (el servicio
 * responde `422 terms.requires_reacceptance_not_allowed` con `true`).
 */
class PublishTermsVersionRequest extends FamUnitRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }

    public function requiresReacceptance(): bool
    {
        $errors = new ValidationErrorBag;
        $value = self::optionalBoolean($this->body(), 'requires_reacceptance', $errors);
        $errors->throwIfAny();

        return $value ?? false;
    }
}
