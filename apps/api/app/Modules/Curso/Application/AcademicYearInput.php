<?php

namespace App\Modules\Curso\Application;

use App\Modules\Curso\Domain\Models\AcademicYear;
use Carbon\CarbonImmutable;

/**
 * REQ-CURSO/funcional.md §5.1 (RN-CURSO-01, -02, -05), `INV-010`:
 * validación de negocio en servidor de los tres campos editables de un
 * curso. Devuelve el mapa de errores por campo (vacío si es válido) para que
 * el servicio los lance juntos como un único `422`.
 */
final class AcademicYearInput
{
    /**
     * @param  array{code?: mixed, starts_on?: mixed, ends_on?: mixed}  $input  claves ya presentes (PATCH parcial) o todas (POST)
     * @param  AcademicYear|null  $current  el curso que se edita (`null` en el alta)
     * @return array<string, list<array{code: string, message: string, params: array<string, mixed>}>>
     */
    public static function errors(array $input, ?AcademicYear $current): array
    {
        $errors = [];

        $code = array_key_exists('code', $input) ? self::normalizeCode($input['code']) : $current?->code;

        if (array_key_exists('code', $input)) {
            if ($code === '') {
                $errors['code'][] = CursoErrors::item('curso.validation.code_required');
            } elseif (self::codeTaken($code, $current)) {
                $errors['code'][] = CursoErrors::item('curso.validation.code_taken');
            }
        }

        $startsOn = array_key_exists('starts_on', $input) ? self::date($input['starts_on']) : $current?->starts_on->toImmutable()->startOfDay();
        $endsOn = array_key_exists('ends_on', $input) ? self::date($input['ends_on']) : $current?->ends_on->toImmutable()->startOfDay();

        if ($startsOn !== null && $endsOn !== null) {
            if ($endsOn->lessThanOrEqualTo($startsOn)) {
                $errors['ends_on'][] = CursoErrors::item('curso.validation.ends_before_start');
            } else {
                $overlapping = self::overlapping($startsOn, $endsOn, $current);

                if ($overlapping !== null) {
                    $errors['starts_on'][] = CursoErrors::item('curso.validation.dates_overlap', ['code' => $overlapping->code]);
                }
            }
        }

        return $errors;
    }

    public static function normalizeCode(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        return is_string($value) ? CarbonImmutable::createFromFormat('!Y-m-d', $value) ?: null : null;
    }

    /** RN-CURSO-01: único por centro entre cursos no borrados. */
    private static function codeTaken(string $code, ?AcademicYear $current): bool
    {
        return AcademicYear::query()
            ->where('code', $code)
            ->when($current !== null, fn ($query) => $query->whereKeyNot($current->getKey()))
            ->exists();
    }

    /**
     * RN-CURSO-05: dos cursos se solapan si comparten algún día (límites
     * inclusive). Un curso que acaba el 31 de agosto y otro que empieza el 1
     * de septiembre son contiguos, no solapados.
     */
    private static function overlapping(CarbonImmutable $startsOn, CarbonImmutable $endsOn, ?AcademicYear $current): ?AcademicYear
    {
        return AcademicYear::query()
            ->when($current !== null, fn ($query) => $query->whereKeyNot($current->getKey()))
            ->whereDate('starts_on', '<=', $endsOn->toDateString())
            ->whereDate('ends_on', '>=', $startsOn->toDateString())
            ->orderBy('starts_on')
            ->first();
    }
}
