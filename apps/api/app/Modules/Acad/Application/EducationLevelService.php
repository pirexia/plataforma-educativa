<?php

namespace App\Modules\Acad\Application;

use App\Models\User;
use App\Modules\Acad\Domain\Models\Course;
use App\Modules\Acad\Domain\Models\EducationLevel;
use App\Support\Api\ValidationErrorBag;
use App\Support\I18n\TranslationsValidator;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD/funcional.md §4.2, §4.4, §4.5 (etapas). Las escrituras sobre un
 * curso de solo lectura las rechaza el disparador (`409`, `RN-ACAD-07`).
 */
final class EducationLevelService
{
    use WritesStructure;

    public function __construct(
        private readonly StructureYears $years,
        private readonly TranslationsValidator $translations,
    ) {}

    /** Detalle: `404` si no existe o es de otro centro; `404` también si su curso es de solo lectura sin `curso_historico.leer` (`RN-ACAD-08`). */
    public function find(string $publicId, User $actor): EducationLevel
    {
        $level = EducationLevel::query()->where('public_id', $publicId)->firstOrFail();
        $this->years->forRow($level->academic_year_id, $actor);

        return $level;
    }

    /**
     * @param  array{academic_year: string, code: string, name_translations: array<string, string>, stage: string, regime: string, position: ?int}  $input
     */
    public function create(array $input, User $actor): EducationLevel
    {
        $year = StructureFields::isSafeText($input['academic_year']) ? $this->years->fromBody($input['academic_year'], $actor) : null;

        if ($year === null) {
            throw StructureErrors::invalid('academic_year', 'acad.validation.academic_year_not_found');
        }

        return $this->inTransaction(function () use ($input, $year): EducationLevel {
            $siblings = EducationLevel::query()->where('academic_year_id', $year->id);
            $this->assertCodeFree(clone $siblings, $input['code']);

            return EducationLevel::create([
                'academic_year_id' => $year->id,
                'code' => $input['code'],
                'name_translations' => $input['name_translations'],
                'stage' => $input['stage'],
                'regime' => $input['regime'],
                'position' => $input['position'] ?? $this->nextPosition(clone $siblings),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $input  solo las claves presentes en el `PATCH`
     */
    public function update(string $publicId, array $input, User $actor): EducationLevel
    {
        $level = EducationLevel::query()->where('public_id', $publicId)->firstOrFail();
        $this->years->forRow($level->academic_year_id, $actor);

        $changes = [];

        if (array_key_exists('code', $input)) {
            $this->assertCodeFree(EducationLevel::query()->where('academic_year_id', $level->academic_year_id), $input['code'], $level->id);
            $changes['code'] = $input['code'];
        }

        foreach (['position', 'stage', 'regime'] as $field) {
            if (array_key_exists($field, $input)) {
                $changes[$field] = $input[$field];
            }
        }

        if (array_key_exists('name_translations', $input)) {
            $errors = new ValidationErrorBag;
            $merged = $this->translations->validateForPatch($input['name_translations'], $level->name_translations, 'name_translations', $errors, StructureLimits::MAX_TEXT_LENGTH);
            $errors->throwIfAny();
            $changes['name_translations'] = $merged;
        }

        return $this->inTransaction(function () use ($level, $changes): EducationLevel {
            $level->fill($changes)->save();

            return $level;
        });
    }

    /** `RN-ACAD-20`: no se borra una etapa con cursos de etapa vivos. */
    public function delete(string $publicId, User $actor): void
    {
        DB::transaction(function () use ($publicId, $actor): void {
            $level = EducationLevel::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            $this->years->forRow($level->academic_year_id, $actor);

            if (Course::query()->where('education_level_id', $level->id)->exists()) {
                throw StructureErrors::hasDependents('education_level', $level->code, ['courses']);
            }

            $level->delete();
        });
    }
}
