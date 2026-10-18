<?php

namespace App\Modules\Acad\Http\Resources;

use App\Modules\Acad\Application\StaffDirectory;
use App\Modules\Acad\Application\TimetableFrameService;
use App\Modules\Acad\Domain\Models\TimetableSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * REQ-ACAD/api.md §12.1: sesión con los resúmenes que pintan la rejilla en una
 * sola llamada. Un aula dada de baja de un curso cerrado se sigue leyendo con
 * `facility.deleted: true` (`RN-ACAD-102`, `-128`).
 *
 * @mixin TimetableSession
 */
class TimetableSessionResource extends JsonResource
{
    use ResolvesStructure;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $assignment = $this->teachingAssignment;
        $subject = $assignment->groupSubject->subject;
        $facility = $this->facility;

        return [
            'public_id' => $this->public_id,
            'academic_year' => $this->academicYearPublicId($this->academic_year_id),
            'frame' => $this->timeSlot->frame->public_id,
            'slot' => [
                'public_id' => $this->timeSlot->public_id,
                'weekday' => $this->timeSlot->weekday,
                'starts_at' => TimetableFrameService::hhmm($this->timeSlot->starts_at),
                'ends_at' => TimetableFrameService::hhmm($this->timeSlot->ends_at),
            ],
            'teaching_assignment' => $assignment->public_id,
            'teacher' => app(StaffDirectory::class)->summary($assignment->user_id),
            'group' => ['public_id' => $assignment->group->public_id, 'code' => $assignment->group->code, 'name' => $assignment->group->name],
            'subgroup' => $assignment->subgroup === null ? null : ['public_id' => $assignment->subgroup->public_id, 'code' => $assignment->subgroup->code, 'name' => $assignment->subgroup->name],
            'subject' => ['public_id' => $subject->public_id, 'code' => $subject->code, 'name' => $this->resolvedName($subject->name_translations)],
            'facility' => $facility === null ? null : ['public_id' => $facility->public_id, 'code' => $facility->code, 'name' => $facility->name, 'deleted' => $facility->trashed()],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
