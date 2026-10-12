<?php

namespace App\Modules\Acad\Application;

use App\Models\User;
use App\Modules\Acad\Domain\Models\Course;
use App\Modules\Acad\Domain\Models\EducationLevel;
use App\Modules\Acad\Domain\Models\Group;
use App\Modules\Acad\Domain\Models\Subject;
use App\Support\Api\ValidationErrorBag;
use App\Support\I18n\TranslationsValidator;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD/funcional.md §4.3-§4.5 (cursos de etapa). El curso académico es el
 * de la etapa padre (`RN-ACAD-05`); el padre del cuerpo ausente, borrado o de
 * otro centro es `422 parent_not_found` (`CA-ACAD-007`).
 */
final class CourseService
{
    use WritesStructure;

    public function __construct(
        private readonly StructureYears $years,
        private readonly TranslationsValidator $translations,
    ) {}

    /** Detalle: `404` si no existe o es de otro centro; `404` también si su curso es de solo lectura sin `curso_historico.leer` (`RN-ACAD-08`). */
    public function find(string $publicId, User $actor): Course
    {
        $course = Course::query()->where('public_id', $publicId)->firstOrFail();
        $this->years->forRow($course->academic_year_id, $actor);

        return $course;
    }

    /**
     * @param  array{education_level: string, code: string, name_translations: array<string, string>, position: ?int}  $input
     */
    public function create(array $input, User $actor): Course
    {
        return $this->inTransaction(function () use ($input, $actor): Course {
            // `FOR SHARE`: el borrado concurrente del padre espera o pierde (funcional.md §6).
            $level = EducationLevel::query()->where('public_id', $input['education_level'])->sharedLock()->first();

            if ($level === null) {
                throw StructureErrors::invalid('education_level', 'acad.validation.parent_not_found');
            }

            $this->years->forBodyParent($level->academic_year_id, $actor, 'education_level');

            $siblings = Course::query()->where('academic_year_id', $level->academic_year_id);
            $this->assertCodeFree(clone $siblings, $input['code']);

            return Course::create([
                'academic_year_id' => $level->academic_year_id,
                'education_level_id' => $level->id,
                'code' => $input['code'],
                'name_translations' => $input['name_translations'],
                'position' => $input['position'] ?? $this->nextPosition(clone $siblings),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(string $publicId, array $input, User $actor): Course
    {
        $course = Course::query()->where('public_id', $publicId)->firstOrFail();
        $this->years->forRow($course->academic_year_id, $actor);

        $changes = [];

        if (array_key_exists('code', $input)) {
            $this->assertCodeFree(Course::query()->where('academic_year_id', $course->academic_year_id), $input['code'], $course->id);
            $changes['code'] = $input['code'];
        }

        if (array_key_exists('position', $input)) {
            $changes['position'] = $input['position'];
        }

        if (array_key_exists('name_translations', $input)) {
            $errors = new ValidationErrorBag;
            $changes['name_translations'] = $this->translations->validateForPatch($input['name_translations'], $course->name_translations, 'name_translations', $errors, StructureLimits::MAX_TEXT_LENGTH);
            $errors->throwIfAny();
        }

        return $this->inTransaction(function () use ($course, $changes): Course {
            $course->fill($changes)->save();

            return $course;
        });
    }

    /** `RN-ACAD-20`: no se borra un curso de etapa con grupos o asignaturas vivos. */
    public function delete(string $publicId, User $actor): void
    {
        DB::transaction(function () use ($publicId, $actor): void {
            $course = Course::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            $this->years->forRow($course->academic_year_id, $actor);

            $dependents = [];

            if (Group::query()->where('course_id', $course->id)->exists()) {
                $dependents[] = 'groups';
            }

            if (Subject::query()->where('course_id', $course->id)->exists()) {
                $dependents[] = 'subjects';
            }

            if ($dependents !== []) {
                throw StructureErrors::hasDependents('course', $course->code, $dependents);
            }

            $course->delete();
        });
    }
}
