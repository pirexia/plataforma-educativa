<?php

namespace App\Modules\Acad\Http\Requests;

use App\Modules\Acad\Application\StructureFields;
use App\Modules\Acad\Application\StructureLimits;
use App\Support\Api\ValidationErrorBag;

/**
 * REQ-ACAD/api.md §12.3, `PATCH /facilities/{public_id}`: editables `code` y
 * `name`. `facility_type` es inmutable (`RN-ACAD-100`): enviarlo, aunque sea el
 * mismo valor, es `422 facility_type_immutable`.
 */
class UpdateFacilityRequest extends StructureRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'nullable', 'string', 'max:'.StructureLimits::MAX_TEXT_LENGTH],
            'name' => ['sometimes', 'nullable', 'string', 'max:'.StructureLimits::MAX_TEXT_LENGTH],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $body = $this->body();
        $errors = new ValidationErrorBag;
        $input = [];

        if (array_key_exists('facility_type', $body)) {
            $errors->add('facility_type', 'acad.validation.facility_type_immutable', 'acad.validation.facility_type_immutable');
        }

        if (array_key_exists('code', $body) && ($code = StructureFields::code($body['code'], $errors)) !== null) {
            $input['code'] = $code;
        }

        if (array_key_exists('name', $body) && ($name = StructureFields::name($body['name'], $errors)) !== null) {
            $input['name'] = $name;
        }

        $errors->throwIfAny();

        return $input;
    }
}
