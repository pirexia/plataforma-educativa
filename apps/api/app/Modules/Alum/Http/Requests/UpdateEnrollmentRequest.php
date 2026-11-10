<?php

namespace App\Modules\Alum\Http\Requests;

/**
 * REQ-ALUM/api.md §2, `PATCH /enrollments/{public_id}`: `enrolled_on` y `group` (solo si no tiene). Alumno, curso
 * académico y curso de etapa se reenvían al servicio, que los rechaza con `422 immutable` (`RN-ALUM-28`).
 */
class UpdateEnrollmentRequest extends EnrollmentBodyRequest
{
    protected function allowedKeys(): array
    {
        return ['enrolled_on', 'group', 'student', 'academic_year', 'course'];
    }
}
