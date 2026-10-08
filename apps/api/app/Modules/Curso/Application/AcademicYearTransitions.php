<?php

namespace App\Modules\Curso\Application;

use App\Modules\Curso\Domain\AcademicYearClosureRegistry;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Modules\Curso\Domain\Models\AcademicYear;
use App\Modules\Curso\Infrastructure\EloquentAcademicYearContext;
use App\Support\Api\ApiException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * RN-CURSO-10: único servicio que escribe `academic_years.status`.
 * Transiciones válidas: `planificacion → activo` y `activo → cerrado`
 * (RN-CURSO-12: cualquier otra, `409 invalid_transition`).
 *
 * RN-CURSO-32, ADR-057 §5.4: abre transacción y toma `SELECT … FOR UPDATE`
 * sobre la fila del curso **antes que ningún otro bloqueo**; ejecuta las
 * validaciones de cierre; cambia `status`; confirma. Frente a eso, toda
 * escritura sobre una tabla de curso toma `FOR SHARE` en el disparador: una
 * escritura concurrente con el cierre o confirma antes, o espera y recibe
 * `YC001`. Lo que el propio cierre tenga que escribir en tablas del curso
 * se escribe ANTES de cambiar `status`, en esta misma transacción.
 *
 * RN-CURSO-13: la auditoría la registra el observer (`updated`,
 * `changes.status = {from, to}`).
 */
final class AcademicYearTransitions
{
    private const STATUS_INDEX = 'academic_years_tenant_status_unique';

    public function __construct(
        private readonly AcademicYearClosureRegistry $closureChecks,
        private readonly EloquentAcademicYearContext $context,
    ) {}

    public function transition(string $publicId, AcademicYearStatus $to): AcademicYear
    {
        try {
            $year = DB::transaction(function () use ($publicId, $to): AcademicYear {
                $year = AcademicYear::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();

                $this->assertValidTransition($year->status, $to);

                if ($to === AcademicYearStatus::Activo) {
                    $this->assertNoOtherActiveYear($year);
                }

                if ($to === AcademicYearStatus::Cerrado) {
                    $this->runClosureChecks($year);
                }

                $year->status = $to;
                $year->save();

                return $year;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Carrera entre dos activaciones (RN-CURSO-11): el índice único
            // parcial del motor es la garantía última.
            if (str_contains($e->getMessage(), self::STATUS_INDEX)) {
                throw $this->activeExists(null);
            }

            throw $e;
        } finally {
            $this->context->forget();
        }

        return $year->refresh();
    }

    private function assertValidTransition(AcademicYearStatus $from, AcademicYearStatus $to): void
    {
        $allowed = ($from === AcademicYearStatus::Planificacion && $to === AcademicYearStatus::Activo)
            || ($from === AcademicYearStatus::Activo && $to === AcademicYearStatus::Cerrado);

        if (! $allowed) {
            throw CursoErrors::conflict('curso.conflict.invalid_transition', ['from' => $from->value, 'to' => $to->value]);
        }
    }

    /** RN-CURSO-11, `OPEN-CURSO-06`: el activo hay que cerrarlo antes. */
    private function assertNoOtherActiveYear(AcademicYear $year): void
    {
        $active = AcademicYear::query()
            ->where('status', AcademicYearStatus::Activo)
            ->whereKeyNot($year->getKey())
            ->first();

        if ($active !== null) {
            throw $this->activeExists($active);
        }
    }

    private function activeExists(?AcademicYear $active): ApiException
    {
        $active ??= AcademicYear::query()->where('status', AcademicYearStatus::Activo)->first();

        return CursoErrors::conflict('curso.conflict.active_exists', [
            'public_id' => $active->public_id ?? '',
            'code' => $active->code ?? '',
        ]);
    }

    /**
     * RN-CURSO-30: el registro está vacío en 1.10. Si alguna validación
     * falla, no cambia nada: una entrada por validación en `errors.closure[]`.
     */
    private function runClosureChecks(AcademicYear $year): void
    {
        $summary = $year->toSummary();
        $failures = [];

        foreach ($this->closureChecks->all() as $check) {
            $failure = $check->check($summary);

            if ($failure !== null) {
                $failures[] = ['code' => $failure->code, 'message' => $failure->message, 'params' => $failure->params];
            }
        }

        if ($failures !== []) {
            throw ApiException::conflict('curso.conflict.closure_checks_failed', [], ['closure' => $failures]);
        }
    }
}
