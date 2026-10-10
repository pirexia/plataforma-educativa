<?php

namespace App\Modules\Core\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use App\Models\UserStatus;
use App\Support\Api\ApiException;
use Illuminate\Validation\Rule;

/**
 * api.md §14.1, `POST /users/exports` (RN-CORE-85). Exactamente los
 * filtros estructurados de `GET /users` (`IndexUsersRequest`) con las
 * mismas reglas (ADR-054 §8.2) — los múltiples como array en el cuerpo
 * JSON — más `format`. Sin `q` (⇒ 422 con código propio, RN-CORE-58), sin
 * `sort`, `page` ni `per_page`.
 */
class StoreUserExportRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('q')) {
            throw ApiException::validation([
                'q' => [[
                    'code' => 'core.validation.export_search_not_supported',
                    'message' => __('core.validation.export_search_not_supported'),
                    'params' => [],
                ]],
            ]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'format' => ['sometimes', Rule::in(['csv'])],
            'status' => ['sometimes', 'array'],
            'status.*' => ['string', Rule::in(array_column(UserStatus::cases(), 'value'))],
            'role' => ['sometimes', 'array'],
            'role.*' => ['string', 'ulid'],
            'locale' => ['sometimes', 'array'],
            'locale.*' => ['string', Rule::in(['es-ES', 'en', 'de', 'fr'])],
            'include_deleted' => ['sometimes', 'boolean:strict'],
        ];
    }
}
