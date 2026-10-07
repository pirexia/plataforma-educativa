<?php

namespace App\Modules\Curso\Application;

use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Modules\Curso\Domain\Models\AcademicYear;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * REQ-CURSO/funcional.md §4.1, §4.2 (1.10): alta y edición de la ficha de un
 * curso (`curso_academico.crear`/`actualizar`). Cambiar el estado NO es de
 * aquí: lo hace solo `AcademicYearTransitions` (RN-CURSO-10). La validación
 * de negocio es del servidor (`INV-010`) y llega antes que los `CHECK` del
 * motor.
 */
final class AcademicYearAdministration
{
    private const PLANNING_INDEX = 'academic_years_tenant_status_unique';

    private const CODE_INDEX = 'academic_years_tenant_code_unique';

    /**
     * RN-CURSO-03: se crea siempre en `planificacion`.
     *
     * @param  array{code: mixed, starts_on: string, ends_on: string}  $input
     */
    public function create(array $input): AcademicYear
    {
        $errors = AcademicYearInput::errors($input, null);

        if ($errors !== []) {
            throw CursoErrors::validation($errors);
        }

        $this->assertNoPlanningYear();

        try {
            return DB::transaction(fn (): AcademicYear => AcademicYear::create([
                'code' => AcademicYearInput::normalizeCode($input['code']),
                'starts_on' => $input['starts_on'],
                'ends_on' => $input['ends_on'],
                'status' => AcademicYearStatus::Planificacion,
            ]))->refresh();
        } catch (UniqueConstraintViolationException $e) {
            // La carrera entre dos peticiones simultáneas (RN-CURSO-04): el
            // índice único del motor es la garantía última; aquí se
            // traduce, nunca un 500.
            throw $this->translateUniqueViolation($e);
        }
    }

    /**
     * RN-CURSO-06: solo en `planificacion`.
     *
     * @param  array{code?: mixed, starts_on?: string, ends_on?: string}  $input  solo las claves a cambiar
     */
    public function update(AcademicYear $year, array $input): AcademicYear
    {
        if ($year->status !== AcademicYearStatus::Planificacion) {
            throw CursoErrors::conflict('curso.conflict.not_editable', ['status' => $year->status->value]);
        }

        $errors = AcademicYearInput::errors($input, $year);

        if ($errors !== []) {
            throw CursoErrors::validation($errors);
        }

        if (array_key_exists('code', $input)) {
            $year->code = AcademicYearInput::normalizeCode($input['code']);
        }

        if (array_key_exists('starts_on', $input)) {
            $year->starts_on = $input['starts_on'];
        }

        if (array_key_exists('ends_on', $input)) {
            $year->ends_on = $input['ends_on'];
        }

        try {
            DB::transaction(fn () => $year->save());
        } catch (UniqueConstraintViolationException $e) {
            throw $this->translateUniqueViolation($e);
        }

        return $year->refresh();
    }

    /** RN-CURSO-04: como mucho un curso en `planificacion` por centro. */
    private function assertNoPlanningYear(): void
    {
        $planning = AcademicYear::query()->where('status', AcademicYearStatus::Planificacion)->first();

        if ($planning !== null) {
            throw CursoErrors::conflict('curso.conflict.planning_exists', [
                'public_id' => $planning->public_id,
                'code' => $planning->code,
            ]);
        }
    }

    private function translateUniqueViolation(UniqueConstraintViolationException $e): \Throwable
    {
        if (str_contains($e->getMessage(), self::CODE_INDEX)) {
            return CursoErrors::validation(['code' => [CursoErrors::item('curso.validation.code_taken')]]);
        }

        if (str_contains($e->getMessage(), self::PLANNING_INDEX)) {
            $planning = AcademicYear::query()->where('status', AcademicYearStatus::Planificacion)->first();

            return CursoErrors::conflict('curso.conflict.planning_exists', [
                'public_id' => $planning->public_id ?? '',
                'code' => $planning->code ?? '',
            ]);
        }

        return $e;
    }
}
