<?php

namespace App\Modules\Backoffice\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use App\Modules\Backoffice\Domain\PlatformAdminStatus;
use Illuminate\Validation\Rule;

/** api.md §2.2, `POST /admins/{public_id}/status`. Suspender o reactivar. */
class UpdatePlatformAdminStatusRequest extends ApiFormRequest
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
            'status' => ['required', 'string', Rule::enum(PlatformAdminStatus::class)],
            'reason' => ['required', 'string', 'min:1'],
        ];
    }
}
