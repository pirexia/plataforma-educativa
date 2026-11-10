<?php

namespace App\Modules\Alum\Http\Resources;

use App\Modules\Alum\Domain\Models\Enrollment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * REQ-ALUM/api.md §2: matrícula con alumno (`public_id` y nombre visible), curso académico, curso de etapa y grupo
 * (resumen de `GroupDirectory`: `public_id`, `code`, `name`), estado y fechas. Los atributos `*_view` los pone
 * `EnrollmentService::attachViews()` (datos de otros módulos, leídos por sus interfaces). Es también la forma de cada
 * elemento de `enrollments[]` de la ficha del alumno.
 *
 * @mixin Enrollment
 */
class EnrollmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'student' => $this->getAttribute('student_view') ?? ['public_id' => null, 'display_name' => ''],
            'academic_year' => $this->getAttribute('year_view') ?? ['public_id' => null, 'code' => null],
            'course' => $this->getAttribute('course_view') ?? ['public_id' => null, 'code' => null, 'name_translations' => []],
            'group' => $this->getAttribute('group_view'),
            'status' => $this->status,
            'enrolled_on' => $this->enrolled_on->format('Y-m-d'),
            'withdrawn_on' => $this->withdrawn_on?->format('Y-m-d'),
            'withdrawal_reason' => $this->withdrawal_reason,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
