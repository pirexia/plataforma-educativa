<?php

namespace App\Modules\Alum\Http\Requests;

/** REQ-ALUM/api.md §2, `POST /enrollments`: `student`, `academic_year`, `course`, `group` (opcional), `enrolled_on`. */
class StoreEnrollmentRequest extends EnrollmentBodyRequest
{
    protected function allowedKeys(): array
    {
        return ['student', 'academic_year', 'course', 'group', 'enrolled_on'];
    }
}
