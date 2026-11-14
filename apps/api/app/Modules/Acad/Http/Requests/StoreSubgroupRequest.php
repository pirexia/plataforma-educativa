<?php

namespace App\Modules\Acad\Http\Requests;

use App\Modules\Acad\Application\StructureFields;
use App\Modules\Acad\Application\StructureLimits;
use App\Support\Api\ValidationErrorBag;

/**
 * REQ-ACAD/api.md §2.1, `POST /groups/{public_id}/subgroups`. El grupo es de
 * la ruta: nunca `parent_not_found` (`CA-ACAD-007`).
 */
class StoreSubgroupRequest extends StructureRequest
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
     * @return array{code: string, name: string, position: ?int}
     */
    public function payload(): array
    {
        $body = $this->body();
        $errors = new ValidationErrorBag;

        StructureFields::rejectAcademicYear($body, $errors);
        $code = StructureFields::code($body['code'] ?? null, $errors);
        $name = StructureFields::name($body['name'] ?? null, $errors);
        $position = isset($body['position']) ? StructureFields::position($body['position'], $errors) : null;

        $errors->throwIfAny();

        return ['code' => (string) $code, 'name' => (string) $name, 'position' => $position];
    }
}
