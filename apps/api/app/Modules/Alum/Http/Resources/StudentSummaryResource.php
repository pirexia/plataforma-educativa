<?php

namespace App\Modules\Alum\Http\Resources;

use App\Modules\Alum\Domain\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * REQ-ALUM/api.md §1, `GET /students` (1.15-a): alumno resumido, sin matrícula vigente (llega con `1.15-b`). Los datos de
 * la persona los pone `StudentService::attachPeople()` (vienen de `Core`; `alum` no los guarda, `RN-ALUM-02`).
 * `deleted_at` solo aparece en un alumno dado de baja (listado con `include_deleted`).
 *
 * @mixin Student
 */
class StudentSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $view */
        $view = $this->getAttribute('person_view') ?? [];

        return [
            'public_id' => $this->public_id,
            'person' => ['public_id' => $view['person_public_id'] ?? null],
            'display_name' => $view['display_name'] ?? '',
            'birth_date' => $view['birth_date'] ?? null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->when($this->trashed(), fn () => $this->deleted_at),
        ];
    }
}
