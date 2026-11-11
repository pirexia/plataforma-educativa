<?php

namespace App\Modules\Alum\Http\Requests;

/** REQ-ALUM/api.md §2, `POST /enrollments/{public_id}/withdrawal`: `withdrawn_on` y `withdrawal_reason`. */
class WithdrawEnrollmentRequest extends EnrollmentBodyRequest
{
    protected function allowedKeys(): array
    {
        return ['withdrawn_on', 'withdrawal_reason'];
    }
}
