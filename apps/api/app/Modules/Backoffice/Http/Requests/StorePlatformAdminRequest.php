<?php

namespace App\Modules\Backoffice\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use App\Modules\Backoffice\Domain\PlatformRole;
use Illuminate\Validation\Rule;

/** api.md §2.2, `POST /admins`. Sensible. */
class StorePlatformAdminRequest extends ApiFormRequest
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
            'email' => ['required', 'string', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'locale' => ['required', 'string', Rule::in(['es-ES', 'en', 'de', 'fr'])],
            'role' => ['required', 'string', Rule::enum(PlatformRole::class)],
        ];
    }
}
