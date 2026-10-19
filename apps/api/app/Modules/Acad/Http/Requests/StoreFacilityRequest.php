<?php

namespace App\Modules\Acad\Http\Requests;

use App\Modules\Acad\Application\FacilityService;
use App\Modules\Acad\Application\StructureFields;
use App\Modules\Acad\Application\StructureLimits;
use App\Support\Api\ValidationErrorBag;

/**
 * REQ-ACAD/api.md §12.3, `POST /facilities`: `facility_type` (`RN-ACAD-100`),
 * `code` (`RN-ACAD-101`) y `name`, texto simple obligatorio. Un `academic_year`
 * en el cuerpo se ignora: el espacio no tiene curso académico (`OPEN-ACAD-33`,
 * `RN-ACAD-13`).
 */
class StoreFacilityRequest extends StructureRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:'.StructureLimits::MAX_TEXT_LENGTH],
            'name' => ['nullable', 'string', 'max:'.StructureLimits::MAX_TEXT_LENGTH],
        ];
    }

    /**
     * @return array{facility_type: string, code: string, name: string}
     */
    public function payload(): array
    {
        $body = $this->body();
        $errors = new ValidationErrorBag;

        $type = $body['facility_type'] ?? null;

        if (! is_string($type) || ! in_array($type, FacilityService::TYPES, true)) {
            $errors->add('facility_type', 'acad.validation.facility_type_invalid', 'acad.validation.facility_type_invalid');
        }

        $code = StructureFields::code($body['code'] ?? null, $errors);
        $name = StructureFields::name($body['name'] ?? null, $errors);

        $errors->throwIfAny();

        return ['facility_type' => (string) $type, 'code' => (string) $code, 'name' => (string) $name];
    }
}
