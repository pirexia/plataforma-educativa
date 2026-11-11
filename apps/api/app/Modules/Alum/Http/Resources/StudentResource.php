<?php

namespace App\Modules\Alum\Http\Resources;

use App\Modules\Alum\Domain\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * REQ-ALUM/api.md §1: ficha del alumno (sin `emergency_contact` ni `guardians[]`; con `has_photo` desde 1.15-c; con `enrollments[]` desde 1.15-b):
 * nombres por partes, fecha de nacimiento e idioma de la persona. El documento de identidad va **enmascarado**, nunca
 * completo (decisión del 2026-11-09; `StudentService::attachPeople()`).
 *
 * @mixin Student
 */
class StudentResource extends JsonResource
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
            'given_name' => $view['given_name'] ?? null,
            'family_name_1' => $view['family_name_1'] ?? null,
            'family_name_2' => $view['family_name_2'] ?? null,
            'birth_date' => $view['birth_date'] ?? null,
            'locale' => $view['locale'] ?? null,
            'document_type' => $view['document_type'] ?? null,
            'document_number' => $view['document_number'] ?? null,
            // 1.15-c (decisión del 2026-11-11): solo el booleano; la URL firmada sale únicamente de `GET /students/{id}/photo`.
            'has_photo' => (bool) $this->getAttribute('has_photo_view'),
            'enrollments' => EnrollmentResource::collection($this->getAttribute('enrollments_view') ?? []),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->when($this->trashed(), fn () => $this->deleted_at),
        ];
    }
}
