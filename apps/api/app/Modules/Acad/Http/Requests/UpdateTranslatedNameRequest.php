<?php

namespace App\Modules\Acad\Http\Requests;

use App\Modules\Acad\Application\StructureFields;
use App\Modules\Acad\Application\StructureLimits;
use App\Support\Api\ValidationErrorBag;

/**
 * REQ-ACAD/api.md §2.1, `PATCH /courses/{public_id}` y
 * `PATCH /subjects/{public_id}`: editables `code`, `position` y
 * `name_translations` (fusión por idioma en el servicio). `name` se ignora.
 */
class UpdateTranslatedNameRequest extends StructureRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'nullable', 'string', 'max:'.StructureLimits::MAX_TEXT_LENGTH],
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

        StructureFields::rejectImmutableKeys($body, $errors);

        if (array_key_exists('code', $body) && ($code = StructureFields::code($body['code'], $errors)) !== null) {
            $input['code'] = $code;
        }

        if (array_key_exists('position', $body) && ($position = StructureFields::position($body['position'], $errors)) !== null) {
            $input['position'] = $position;
        }

        if (array_key_exists('name_translations', $body)) {
            $input['name_translations'] = $body['name_translations'];
        }

        $errors->throwIfAny();

        return $input;
    }
}
