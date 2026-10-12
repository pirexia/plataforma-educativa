<?php

namespace App\Modules\Acad\Http\Requests;

use App\Modules\Acad\Http\Requests\Rules\PlainText;

/**
 * REQ-ACAD/api.md §2.1, `PUT /groups/{public_id}/subjects`:
 * `{"subjects": ["<public_id>", …]}`. Lista vacía = quitar todas.
 */
class ReplaceGroupSubjectsRequest extends StructureRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'subjects' => ['present', 'array'],
            'subjects.*' => ['string', new PlainText],
        ];
    }

    /**
     * @return list<string>
     */
    public function subjects(): array
    {
        /** @var list<string> $subjects */
        $subjects = array_values((array) $this->json('subjects', []));

        return $subjects;
    }
}
