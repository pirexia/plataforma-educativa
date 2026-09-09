<?php

namespace App\Modules\Backoffice\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use App\Modules\Backoffice\Domain\PlatformRole;
use Illuminate\Validation\Rule;

/** api.md §2.2, `PUT /admins/{public_id}/roles`. Conjunto completo. */
class ReplacePlatformAdminRolesRequest extends ApiFormRequest
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
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', 'distinct', Rule::enum(PlatformRole::class)],
            'reason' => ['required', 'string', 'min:1'],
        ];
    }
}
