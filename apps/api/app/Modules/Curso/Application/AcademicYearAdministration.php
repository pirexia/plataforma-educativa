<?php

namespace App\Modules\Curso\Application;

use App\Modules\Curso\Domain\AcademicYearDateChange;
use App\Modules\Curso\Domain\AcademicYearDateChangeRegistry;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Modules\Curso\Domain\Models\AcademicYear;
use App\Support\Api\ApiException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
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
    public function __construct(
        private readonly AcademicYearDateChangeRegistry $dateChangeChecks,
        private readonly ModuleScopedChecks $scopedChecks,
    ) {}

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
     * RN-CURSO-06 y RN-CURSO-49 a -54 (1.11d-a). Todo en una transacción:
     * (1) `FOR UPDATE` sobre la fila del curso antes que ningún otro bloqueo;
     * (2) RN-CURSO-06 bajo ese bloqueo; (3) validaciones propias (`422`; si
     * fallan no se ejecutan las de los módulos); (4) TODAS las validaciones
     * registradas de módulos contratados, sin detenerse en la primera;
     * (5) si alguna falla, `409 date_change_checks_failed` y nada cambia.
     *
     * @param  array{code?: mixed, starts_on?: string, ends_on?: string}  $input  solo las claves a cambiar
     */
    public function update(AcademicYear $year, array $input): AcademicYear
    {
        try {
            $saved = DB::transaction(function () use ($year, $input): AcademicYear {
                $locked = AcademicYear::query()->whereKey($year->getKey())->lockForUpdate()->firstOrFail();

                if ($locked->status !== AcademicYearStatus::Planificacion) {
                    throw CursoErrors::conflict('curso.conflict.not_editable', ['status' => $locked->status->value]);
                }

                $errors = AcademicYearInput::errors($input, $locked);

                if ($errors !== []) {
                    throw CursoErrors::validation($errors);
                }

                $startsOn = array_key_exists('starts_on', $input) ? Carbon::parse($input['starts_on']) : $locked->starts_on;
                $endsOn = array_key_exists('ends_on', $input) ? Carbon::parse($input['ends_on']) : $locked->ends_on;

                // RN-CURSO-50: solo si alguna fecha resultante difiere de la actual.
                if ($startsOn->toDateString() !== $locked->starts_on->toDateString() || $endsOn->toDateString() !== $locked->ends_on->toDateString()) {
                    $this->runDateChangeChecks($locked, $startsOn, $endsOn);
                }

                if (array_key_exists('code', $input)) {
                    $locked->code = AcademicYearInput::normalizeCode($input['code']);
                }

                $locked->starts_on = $startsOn;
                $locked->ends_on = $endsOn;
                $locked->save();

                return $locked;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw $this->translateUniqueViolation($e);
        }

        return $saved->refresh();
    }

    /**
     * RN-CURSO-51 a -53: todas las validaciones de módulos contratados
     * (RN-CURSO-52), sin parar en la primera. Una entrada por validación
     * fallida en `errors.date_change[]`.
     */
    private function runDateChangeChecks(AcademicYear $year, Carbon $startsOn, Carbon $endsOn): void
    {
        $change = new AcademicYearDateChange($year->toSummary(), $startsOn->toImmutable()->startOfDay(), $endsOn->toImmutable()->startOfDay());
        $failures = [];

        foreach ($this->scopedChecks->enabled($this->dateChangeChecks->all()) as $check) {
            $failure = $check->check($change);

            if ($failure !== null) {
                $failures[] = ['code' => $failure->code, 'message' => $failure->message, 'params' => $failure->params];
            }
        }

        if ($failures !== []) {
            throw ApiException::conflict('curso.conflict.date_change_checks_failed', [], ['date_change' => $failures]);
        }
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
