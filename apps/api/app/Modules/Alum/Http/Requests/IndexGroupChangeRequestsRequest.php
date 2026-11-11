<?php

namespace App\Modules\Alum\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use App\Modules\Alum\Application\GroupChangeService;
use Illuminate\Validation\Rule;

/**
 * REQ-ALUM/api.md §3, `GET /group-change-requests`: `academic_year` (obligatorio; lo exige y valida el servicio, que responde
 * `422 required` o `404`), `status`, `group` (`public_id`; origen o destino) y paginación por página (`per_page` defecto 25,
 * máximo 100). Un parámetro desconocido se ignora (`ADR-038 §5.2`).
 */
class IndexGroupChangeRequestsRequest extends ApiFormRequest
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
        return [
            'academic_year' => ['sometimes', 'string', 'max:64'],
            'group' => ['sometimes', 'string', 'max:64'],
            'status' => ['sometimes', Rule::in(GroupChangeService::STATUSES)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array{academic_year: ?string, group: ?string, status: ?string}
     */
    public function filters(): array
    {
        $get = fn (string $key): ?string => is_string($this->query($key)) ? $this->query($key) : null;

        return ['academic_year' => $get('academic_year'), 'group' => $get('group'), 'status' => $get('status')];
    }
}
