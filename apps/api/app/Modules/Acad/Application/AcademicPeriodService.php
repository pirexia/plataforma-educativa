<?php

namespace App\Modules\Acad\Application;

use App\Models\User;
use App\Modules\Acad\Domain\Models\AcademicPeriod;
use App\Modules\Acad\Domain\Models\EducationLevel;
use App\Modules\Curso\Domain\AcademicYearSharedLock;
use App\Modules\Curso\Domain\AcademicYearSummary;
use App\Support\Api\ApiException;
use App\Support\Api\ValidationErrorBag;
use App\Support\I18n\TranslationsValidator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD/funcional.md §4.8, §5.7-§5.8 (`RN-ACAD-50` a `-61`), 1.11d-a:
 * períodos lectivos de una etapa.
 *
 * Serialización (`RN-ACAD-54`, `datos.md §8.3`), el motor no impone ni el
 * solape, ni el tipo único, ni la pertenencia al curso:
 *
 * - **Alta y edición**, en una transacción y en este orden: (a) resolver la
 *   etapa y comprobar que es legible; (b) `AcademicYearSharedLock::lockForShareById()`
 *   del curso (`FOR SHARE`, las fechas se toman del resumen leído bajo el
 *   bloqueo, `RN-ACAD-57`); (c) `FOR UPDATE` sobre la fila de la etapa;
 *   (d) leer los hermanos y validar. Orden curso → etapa: ninguna fila propia
 *   se bloquea antes de pedir el curso (`REQ-CURSO RN-CURSO-58`).
 * - **Borrado**: solo `FOR UPDATE` sobre la etapa; no depende de las fechas
 *   del curso. Su disparador pide `FOR SHARE` sobre el curso después, como
 *   cualquier escritura.
 *
 * Las escrituras sobre un curso de solo lectura las rechaza el disparador
 * (`409`, `RN-ACAD-07`): el bloqueo compartido devuelve el curso cerrado y no
 * decide la escritura (`REQ-CURSO RN-CURSO-57`).
 */
final class AcademicPeriodService
{
    public function __construct(
        private readonly StructureYears $years,
        private readonly TranslationsValidator $translations,
        private readonly AcademicYearSharedLock $sharedLock,
    ) {}

    /** Detalle: `404` si no existe o es de otro centro; `404` también si su curso es de solo lectura sin `curso_historico.leer` (`RN-ACAD-52`). */
    public function find(string $publicId, User $actor): AcademicPeriod
    {
        $period = AcademicPeriod::query()->where('public_id', $publicId)->firstOrFail();
        $this->years->forRow($period->academic_year_id, $actor);

        return $period;
    }

    /**
     * @param  array{education_level: string, period_type: string, name_translations: array<string, string>, starts_on: string, ends_on: string, position: ?int}  $input
     */
    public function create(array $input, User $actor): AcademicPeriod
    {
        return DB::transaction(function () use ($input, $actor): AcademicPeriod {
            // (a) La etapa del cuerpo: inexistente, borrada, de otro centro o de un curso ilegible ⇒ 422.
            $found = EducationLevel::query()->where('public_id', $input['education_level'])->first();

            if ($found === null) {
                throw StructureErrors::invalid('education_level', 'acad.validation.parent_not_found');
            }

            $this->years->forBodyParent($found->academic_year_id, $actor, 'education_level');

            // (b) El curso, con FOR SHARE, antes de bloquear ninguna fila propia.
            $year = $this->sharedLock->lockForShareById($found->academic_year_id)
                ?? throw StructureErrors::invalid('education_level', 'acad.validation.parent_not_found');

            // (c) La etapa, con FOR UPDATE; si un borrado concurrente ganó, ya no está viva.
            $level = EducationLevel::query()->whereKey($found->getKey())->lockForUpdate()->first()
                ?? throw StructureErrors::invalid('education_level', 'acad.validation.parent_not_found');

            // (d) Hermanos vivos y validación del estado resultante.
            $siblings = AcademicPeriod::query()->where('education_level_id', $level->id);

            $this->assertValidState($input['period_type'], $input['starts_on'], $input['ends_on'], $year, clone $siblings, null);

            return AcademicPeriod::create([
                'academic_year_id' => $level->academic_year_id,
                'education_level_id' => $level->id,
                'period_type' => $input['period_type'],
                'name_translations' => $input['name_translations'],
                'starts_on' => $input['starts_on'],
                'ends_on' => $input['ends_on'],
                'position' => $input['position'] ?? ((int) (clone $siblings)->max('position')) + 1,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $input  solo las claves presentes en el `PATCH`
     */
    public function update(string $publicId, array $input, User $actor): AcademicPeriod
    {
        $found = AcademicPeriod::query()->where('public_id', $publicId)->firstOrFail();
        $this->years->forRow($found->academic_year_id, $actor);

        return DB::transaction(function () use ($found, $input): AcademicPeriod {
            // (b) El curso (inmutable, así que leerlo del período sin bloqueo es seguro), con FOR SHARE.
            $year = $this->sharedLock->lockForShareById($found->academic_year_id) ?? throw ApiException::notFound();

            // (c) La etapa, con FOR UPDATE; después se relee el período: un borrado o una edición concurrentes ya confirmaron.
            EducationLevel::query()->whereKey($found->education_level_id)->lockForUpdate()->first()
                ?? throw ApiException::notFound();

            $period = AcademicPeriod::query()->whereKey($found->getKey())->firstOrFail();

            // (d) Estado resultante, excluyendo el propio período.
            $type = array_key_exists('period_type', $input) ? $input['period_type'] : $period->period_type;
            $startsOn = array_key_exists('starts_on', $input) ? $input['starts_on'] : $period->starts_on->toDateString();
            $endsOn = array_key_exists('ends_on', $input) ? $input['ends_on'] : $period->ends_on->toDateString();

            $this->assertValidState($type, $startsOn, $endsOn, $year, AcademicPeriod::query()->where('education_level_id', $period->education_level_id), $period->id);

            $changes = ['period_type' => $type, 'starts_on' => $startsOn, 'ends_on' => $endsOn];

            if (array_key_exists('position', $input)) {
                $changes['position'] = $input['position'];
            }

            if (array_key_exists('name_translations', $input)) {
                $errors = new ValidationErrorBag;
                $changes['name_translations'] = $this->translations->validateForPatch($input['name_translations'], $period->name_translations, 'name_translations', $errors, StructureLimits::MAX_TEXT_LENGTH);
                $errors->throwIfAny();
            }

            $period->fill($changes)->save();

            return $period;
        });
    }

    /** `RN-ACAD-61`: borrado lógico sin dependientes; solo `FOR UPDATE` sobre la etapa (`RN-ACAD-54` punto 2). */
    public function delete(string $publicId, User $actor): void
    {
        $found = AcademicPeriod::query()->where('public_id', $publicId)->firstOrFail();
        $this->years->forRow($found->academic_year_id, $actor);

        DB::transaction(function () use ($found): void {
            // Con la etapa bloqueada, un alta concurrente ya confirmó o espera; se relee el período por si otro lo borró.
            EducationLevel::query()->withTrashed()->whereKey($found->education_level_id)->lockForUpdate()->first();

            AcademicPeriod::query()->whereKey($found->getKey())->firstOrFail()->delete();
        });
    }

    /**
     * `RN-ACAD-56` a `-59` sobre el estado resultante, por este orden y
     * deteniéndose en la primera que falla: fechas ordenadas, dentro del curso
     * (fechas leídas bajo `FOR SHARE`), un solo tipo por etapa y sin solape
     * (intervalos cerrados) con los períodos vivos de la etapa.
     *
     * @param  Builder<AcademicPeriod>  $siblings  períodos vivos de la etapa
     */
    private function assertValidState(string $type, string $startsOn, string $endsOn, AcademicYearSummary $year, Builder $siblings, ?int $exceptId): void
    {
        $errors = new ValidationErrorBag;

        if (! PeriodFields::order($startsOn, $endsOn, $errors)) {
            $errors->throwIfAny();
        }

        $yearStart = $year->startsOn->toDateString();
        $yearEnd = $year->endsOn->toDateString();

        if ($startsOn < $yearStart || $endsOn > $yearEnd) {
            $params = ['academic_year_starts_on' => $yearStart, 'academic_year_ends_on' => $yearEnd];

            if ($startsOn < $yearStart) {
                $errors->add('starts_on', 'acad.validation.period_outside_academic_year', 'acad.validation.period_outside_academic_year', $params);
            }

            if ($endsOn > $yearEnd) {
                $errors->add('ends_on', 'acad.validation.period_outside_academic_year', 'acad.validation.period_outside_academic_year', $params);
            }

            $errors->throwIfAny();
        }

        if ($exceptId !== null) {
            $siblings->whereKeyNot($exceptId);
        }

        $other = (clone $siblings)->where('period_type', '<>', $type)->orderBy('starts_on')->orderBy('id')->first();

        if ($other !== null) {
            $errors->add('period_type', 'acad.validation.period_type_mismatch', 'acad.validation.period_type_mismatch', ['period_type' => $other->period_type]);
            $errors->throwIfAny();
        }

        // Intervalos cerrados (RN-ACAD-58): compartir un día es solape.
        $overlapping = $siblings->where('starts_on', '<=', $endsOn)->where('ends_on', '>=', $startsOn)->orderBy('starts_on')->orderBy('id')->first();

        if ($overlapping !== null) {
            $errors->add('starts_on', 'acad.validation.period_overlap', 'acad.validation.period_overlap', ['period' => $overlapping->public_id]);
            $errors->throwIfAny();
        }
    }
}
