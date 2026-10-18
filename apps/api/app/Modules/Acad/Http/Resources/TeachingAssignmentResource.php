<?php

namespace App\Modules\Acad\Http\Resources;

use App\Modules\Acad\Application\StaffDirectory;
use App\Modules\Acad\Domain\Models\TeachingAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * REQ-ACAD/api.md §12.1. `teacher.name` es el nombre visible que devuelve
 * `Core` (sin correo ni otros datos). La fila de `group_subjects` no se expone:
 * `subject` es el `public_id` de la asignatura.
 *
 * @mixin TeachingAssignment
 */
class TeachingAssignmentResource extends JsonResource
{
    use ResolvesStructure;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'academic_year' => $this->academicYearPublicId($this->academic_year_id),
            'teacher' => app(StaffDirectory::class)->summary($this->user_id),
            'group' => $this->group->public_id,
            'subject' => $this->groupSubject->subject->public_id,
            'subgroup' => $this->subgroup?->public_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
