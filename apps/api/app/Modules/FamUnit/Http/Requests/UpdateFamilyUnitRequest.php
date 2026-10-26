<?php

namespace App\Modules\FamUnit\Http\Requests;

use App\Support\Api\ValidationErrorBag;

/**
 * REQ-FAM-UNIT/api.md §2.1, `PATCH /family-units/{public_id}`: `code` y `principal_guardian` (`public_id` de un tutor
 * de esta unidad, o `null` para quitarlo).
 */
class UpdateFamilyUnitRequest extends FamUnitRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * @return array{code?: string, principal_guardian?: ?string}
     */
    public function payload(): array
    {
        $body = $this->body();
        $errors = new ValidationErrorBag;
        $input = [];

        if (array_key_exists('code', $body)) {
            $code = StoreFamilyUnitRequest::code($body['code'], $errors);

            if ($code !== null) {
                $input['code'] = $code;
            }
        }

        if (array_key_exists('principal_guardian', $body)) {
            if ($body['principal_guardian'] === null) {
                $input['principal_guardian'] = null;
            } elseif (self::isUlid($body['principal_guardian'])) {
                $input['principal_guardian'] = (string) $body['principal_guardian'];
            } else {
                $errors->add('principal_guardian', 'fam_unit.validation.reference_invalid', 'fam_unit.validation.reference_invalid');
            }
        }

        $errors->throwIfAny();

        return $input;
    }
}
