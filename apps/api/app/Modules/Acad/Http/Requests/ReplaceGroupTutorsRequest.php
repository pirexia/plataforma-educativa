<?php

namespace App\Modules\Acad\Http\Requests;

use App\Support\Api\ValidationErrorBag;

/**
 * REQ-ACAD/api.md §12.3, `PUT /groups/{public_id}/tutors`:
 * `{"tutors": ["<usuario>", …]}`. Lista vacía = sin tutores; repetidos =
 * conjunto (`RN-ACAD-97`). Un elemento que no es una cadena es
 * `422 user_not_found` en `tutors.<n>`.
 */
class ReplaceGroupTutorsRequest extends StructureRequest
{
    use ReadsReferences;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'tutors' => ['present', 'array'],
        ];
    }

    /**
     * @return list<string>
     */
    public function tutors(): array
    {
        $errors = new ValidationErrorBag;
        $tutors = [];

        foreach (array_values((array) ($this->body()['tutors'] ?? [])) as $index => $value) {
            $reference = $this->reference($value);

            if ($reference === null) {
                $errors->add("tutors.{$index}", 'acad.validation.user_not_found', 'acad.validation.user_not_found');

                continue;
            }

            $tutors[] = $reference;
        }

        $errors->throwIfAny();

        return $tutors;
    }
}
