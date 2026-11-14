<?php

namespace App\Modules\Acad\Application;

use App\Models\User;
use App\Modules\Acad\Domain\Models\GradingScale;
use App\Modules\Acad\Domain\Models\GradingScaleValue;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD/funcional.md §4.9, §5.9-§5.10 (`RN-ACAD-70` a `-89`), 1.11d-b:
 * escalas de calificación y sus valores o tramos.
 *
 * Los valores solo se escriben dentro de la escala (`RN-ACAD-80`): el alta y el
 * `PATCH` reciben el conjunto completo y se aplican en **una** transacción, por
 * instancia (`AR-15`), o se aplican enteros o no se aplica nada. El `PATCH`
 * toma `FOR UPDATE` de la fila de la escala antes de leer sus valores
 * (`datos.md §8.3`): las reglas entre valores (al menos un aprobado, tramos
 * contiguos, umbral en frontera) se evalúan sobre el conjunto resultante sin
 * que otra escritura lo cambie. En el alta no hace falta: la escala es nueva y
 * nadie más la ve.
 *
 * El orden de los bloqueos es solo escala; no se bloquea la fila del curso
 * (ninguna regla de escala depende de sus fechas), así que no añade ninguna
 * inversión a `datos.md §8.3`. Las escrituras sobre un curso de solo lectura
 * las rechaza el disparador (`409`, `RN-ACAD-07`).
 */
final class GradingScaleService
{
    use WritesStructure;

    public function __construct(
        private readonly StructureYears $years,
        private readonly GradingScaleValidator $validator,
    ) {}

    /** Detalle: `404` si no existe o es de otro centro; `404` también si su curso es de solo lectura sin `curso_historico.leer` (`RN-ACAD-52`). */
    public function find(string $publicId, User $actor): GradingScale
    {
        $scale = GradingScale::query()->where('public_id', $publicId)->firstOrFail();
        $this->years->forRow($scale->academic_year_id, $actor);

        return $scale;
    }

    /**
     * @param  array<string, mixed>  $body  cuerpo en crudo (la validación de negocio es de `GradingScaleValidator`)
     */
    public function create(string $academicYear, string $scaleType, array $body, User $actor): GradingScale
    {
        // RN-ACAD-51/-52: inexistente, de otro centro o de solo lectura sin curso_historico.leer ⇒ 422 (S-3).
        $year = StructureFields::isSafeText($academicYear) ? $this->years->fromBody($academicYear, $actor) : null;

        if ($year === null) {
            throw StructureErrors::invalid('academic_year', 'acad.validation.academic_year_not_found');
        }

        $resolved = $this->validator->resolve($scaleType, $body, null, []);

        return $this->inTransaction(function () use ($year, $scaleType, $resolved): GradingScale {
            $this->assertCodeFree(GradingScale::query()->where('academic_year_id', $year->id), $resolved->code);

            $scale = GradingScale::create([
                'academic_year_id' => $year->id,
                'code' => $resolved->code,
                'name_translations' => $resolved->nameTranslations,
                'scale_type' => $scaleType,
            ] + $this->numericAttributes($resolved));

            $this->syncValues($scale, [], $resolved->values);

            return $scale->load('values');
        });
    }

    /**
     * @param  array<string, mixed>  $body  cuerpo en crudo del `PATCH`
     */
    public function update(string $publicId, array $body, User $actor): GradingScale
    {
        $found = GradingScale::query()->where('public_id', $publicId)->firstOrFail();
        $this->years->forRow($found->academic_year_id, $actor);

        return $this->inTransaction(function () use ($found, $body): GradingScale {
            // FOR UPDATE de la escala; se relee por si otra escritura (o un borrado) confirmó antes.
            $scale = GradingScale::query()->whereKey($found->getKey())->lockForUpdate()->firstOrFail();
            $stored = $scale->values()->get()->all();

            $resolved = $this->validator->resolve($scale->scale_type, $body, $scale, $stored);

            if ($resolved->code !== $scale->code) {
                $this->assertCodeFree(GradingScale::query()->where('academic_year_id', $scale->academic_year_id), $resolved->code, $scale->id);
            }

            $changes = ['code' => $resolved->code] + $this->numericAttributes($resolved);

            if (! self::sameTranslations($resolved->nameTranslations, $scale->name_translations)) {
                $changes['name_translations'] = $resolved->nameTranslations;
            }

            $scale->fill($changes)->save();

            if ($resolved->valuesSent) {
                $this->syncValues($scale, $stored, $resolved->values);
            }

            return $scale->load('values');
        });
    }

    /** `RN-ACAD-76`: borrado lógico de la escala **y de sus valores vivos**, por instancia y en una transacción. */
    public function delete(string $publicId, User $actor): void
    {
        $found = GradingScale::query()->where('public_id', $publicId)->firstOrFail();
        $this->years->forRow($found->academic_year_id, $actor);

        DB::transaction(function () use ($found): void {
            $scale = GradingScale::query()->whereKey($found->getKey())->lockForUpdate()->firstOrFail();

            foreach ($scale->values()->get() as $value) {
                $value->delete();
            }

            $scale->delete();
        });
    }

    /**
     * Campos numéricos de la escala en la forma que devuelve PostgreSQL
     * (`"5.00"`): así un `PATCH` sin cambios efectivos no deja nada sucio.
     *
     * @return array{min_value: ?string, max_value: ?string, decimals: ?int, passing_min_value: ?string}
     */
    private function numericAttributes(ResolvedGradingScale $resolved): array
    {
        return [
            'min_value' => $resolved->min === null ? null : ScaleDecimal::toStored($resolved->min),
            'max_value' => $resolved->max === null ? null : ScaleDecimal::toStored($resolved->max),
            'decimals' => $resolved->decimals,
            'passing_min_value' => $resolved->passing === null ? null : ScaleDecimal::toStored($resolved->passing),
        ];
    }

    /**
     * `RN-ACAD-83`, `RN-ACAD-88`: sincroniza el conjunto completo. Orden:
     * borrados, actualizaciones, altas (así un alta puede reutilizar el código
     * de un valor que el mismo `PATCH` borra). La `position` es el orden de la
     * lista (1, 2, …). Por instancia: cada alta, cambio y baja es una entrada
     * de auditoría propia (`INV-003`); un valor sin cambios no escribe ni audita.
     *
     * @param  list<GradingScaleValue>  $stored  valores vivos antes de la escritura
     * @param  list<ResolvedGradingValue>  $values  conjunto resultante, en orden
     */
    private function syncValues(GradingScale $scale, array $stored, array $values): void
    {
        $kept = [];

        foreach ($values as $value) {
            if ($value->existing !== null) {
                $kept[$value->existing->id] = true;
            }
        }

        foreach ($stored as $existing) {
            if (! isset($kept[$existing->id])) {
                $existing->delete();
            }
        }

        foreach ($values as $index => $value) {
            if ($value->existing === null) {
                continue;
            }

            $attributes = $this->valueAttributes($value, $index);

            if (self::sameTranslations($value->labelTranslations, $value->existing->label_translations)) {
                unset($attributes['label_translations']);
            }

            $value->existing->fill($attributes)->save();
        }

        foreach ($values as $index => $value) {
            if ($value->existing !== null) {
                continue;
            }

            GradingScaleValue::create([
                'academic_year_id' => $scale->academic_year_id,
                'grading_scale_id' => $scale->id,
                // Copia del tipo de la escala, fijada aquí: la FK de cuatro columnas la hace cumplir.
                'scale_type' => $scale->scale_type,
            ] + $this->valueAttributes($value, $index));
        }
    }

    /**
     * @return array{code: string, label_translations: array<string, string>, position: int, is_passing: ?bool, range_min: ?string, range_max: ?string}
     */
    private function valueAttributes(ResolvedGradingValue $value, int $index): array
    {
        return [
            'code' => $value->code,
            'label_translations' => $value->labelTranslations,
            'position' => $index + 1,
            'is_passing' => $value->isPassing,
            'range_min' => $value->rangeMin === null ? null : ScaleDecimal::toStored($value->rangeMin),
            'range_max' => $value->rangeMax === null ? null : ScaleDecimal::toStored($value->rangeMax),
        ];
    }

    /**
     * ¿Son iguales dos mapas de traducciones? PostgreSQL (`jsonb`) devuelve las
     * claves en su propio orden y el validador las ordena por el vocabulario de
     * idiomas: el mismo contenido en otro orden no es un cambio, y Eloquent lo
     * vería como tal (una entrada de auditoría vacía y un `updated_at` falso).
     *
     * @param  array<string, string>  $new
     * @param  array<string, string>  $current
     */
    private static function sameTranslations(array $new, array $current): bool
    {
        ksort($new);
        ksort($current);

        return $new === $current;
    }
}
