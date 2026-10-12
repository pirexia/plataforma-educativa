<?php

namespace App\Modules\Acad\Application;

use App\Models\User;
use App\Modules\Acad\Domain\Models\Course;
use App\Modules\Acad\Domain\Models\GroupSubject;
use App\Modules\Acad\Domain\Models\Subject;
use App\Support\Api\ValidationErrorBag;
use App\Support\I18n\TranslationsValidator;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD/funcional.md §4.3-§4.5 (asignaturas): una asignatura pertenece a
 * un curso de etapa (`RN-ACAD-17`) y su nombre es traducible.
 */
final class SubjectService
{
    use WritesStructure;

    public function __construct(
        private readonly StructureYears $years,
        private readonly TranslationsValidator $translations,
    ) {}

    /** Detalle: `404` si no existe o es de otro centro; `404` también si su curso es de solo lectura sin `curso_historico.leer` (`RN-ACAD-08`). */
    public function find(string $publicId, User $actor): Subject
    {
        $subject = Subject::query()->where('public_id', $publicId)->firstOrFail();
        $this->years->forRow($subject->academic_year_id, $actor);

        return $subject;
    }

    /**
     * @param  array{course: string, code: string, name_translations: array<string, string>, position: ?int}  $input
     */
    public function create(array $input, User $actor): Subject
    {
        return $this->inTransaction(function () use ($input, $actor): Subject {
            $course = Course::query()->where('public_id', $input['course'])->sharedLock()->first();

            if ($course === null) {
                throw StructureErrors::invalid('course', 'acad.validation.parent_not_found');
            }

            $this->years->forBodyParent($course->academic_year_id, $actor, 'course');

            $siblings = Subject::query()->where('course_id', $course->id);
            $this->assertCodeFree(clone $siblings, $input['code']);

            return Subject::create([
                'academic_year_id' => $course->academic_year_id,
                'course_id' => $course->id,
                'code' => $input['code'],
                'name_translations' => $input['name_translations'],
                'position' => $input['position'] ?? $this->nextPosition(clone $siblings),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(string $publicId, array $input, User $actor): Subject
    {
        $subject = Subject::query()->where('public_id', $publicId)->firstOrFail();
        $this->years->forRow($subject->academic_year_id, $actor);

        $changes = array_intersect_key($input, array_flip(['code', 'position']));

        if (array_key_exists('code', $input)) {
            $this->assertCodeFree(Subject::query()->where('course_id', $subject->course_id), $input['code'], $subject->id);
        }

        if (array_key_exists('name_translations', $input)) {
            $errors = new ValidationErrorBag;
            $changes['name_translations'] = $this->translations->validateForPatch($input['name_translations'], $subject->name_translations, 'name_translations', $errors, StructureLimits::MAX_TEXT_LENGTH);
            $errors->throwIfAny();
        }

        return $this->inTransaction(function () use ($subject, $changes): Subject {
            $subject->fill($changes)->save();

            return $subject;
        });
    }

    /** `RN-ACAD-20`: no se borra una asignatura asignada a algún grupo vivo. */
    public function delete(string $publicId, User $actor): void
    {
        DB::transaction(function () use ($publicId, $actor): void {
            $subject = Subject::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            $this->years->forRow($subject->academic_year_id, $actor);

            if (GroupSubject::query()->where('subject_id', $subject->id)->exists()) {
                throw StructureErrors::hasDependents('subject', $subject->code, ['groups']);
            }

            $subject->delete();
        });
    }
}
