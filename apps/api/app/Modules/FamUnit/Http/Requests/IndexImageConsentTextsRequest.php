<?php

namespace App\Modules\FamUnit\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use App\Modules\FamUnit\Domain\Models\ImageConsentDecision;
use App\Support\Api\Rules\InList;

/**
 * REQ-FAM-UNIT/api.md §2.7, `GET /image-consent-texts` (`RN-FAM-UNIT-75`): filtro opcional `level`. Sin paginación.
 */
class IndexImageConsentTextsRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return ['level' => ['sometimes', 'string', new InList(ImageConsentDecision::LEVELS)]];
    }

    /**
     * @return list<string> los niveles pedidos, o los dos si no se filtra
     */
    public function levels(): array
    {
        $level = $this->query('level');

        return is_string($level) ? array_values(array_unique(explode(',', $level))) : ImageConsentDecision::LEVELS;
    }
}
