<?php

namespace App\Modules\Curso\Application;

use App\Modules\Curso\Domain\AcademicYearClosureRegistry;
use App\Modules\Curso\Domain\AcademicYearReopenRegistry;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Modules\Curso\Domain\Events\AcademicYearReopened;
use App\Modules\Curso\Domain\Models\AcademicYear;
use App\Modules\Curso\Domain\Models\AcademicYearReopening;
use App\Modules\Curso\Domain\Models\YearRollover;
use App\Modules\Curso\Domain\YearRolloverStatus;
use App\Modules\Curso\Infrastructure\EloquentAcademicYearContext;
use App\Support\Api\ApiException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

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
 *
 * 1.11c (`ADR-059`, RN-CURSO-40 a -48): `reopen()` es la transición
 * `cerrado → activo`, por un *endpoint* propio con permiso propio. No es una
 * cuarta transición de `transition()` (`POST …/status` no la admite, `CA-CURSO-023`).
 */
final class AcademicYearTransitions
{
    private const STATUS_INDEX = 'academic_years_tenant_status_unique';

    public function __construct(
        private readonly AcademicYearClosureRegistry $closureChecks,
        private readonly AcademicYearReopenRegistry $reopenChecks,
        private readonly ModuleScopedChecks $scopedChecks,
        private readonly EloquentAcademicYearContext $context,
    ) {}

    public function transition(string $publicId, AcademicYearStatus $to): AcademicYear
    {
        try {
            $year = DB::transaction(function () use ($publicId, $to): AcademicYear {
                $year = AcademicYear::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();

                $this->assertValidTransition($year->status, $to);

                if ($to === AcademicYearStatus::Activo) {
                    // RN-CURSO-71 (1.12b): antes que `active_exists`, porque el origen de una copia suele ser el curso activo.
                    $this->assertNoRolloverInFlight($year);
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

    /**
     * RN-CURSO-40 a -48 (`ADR-059`), `funcional.md §4.6` paso 3. Dentro de una
     * transacción y tras el `FOR UPDATE` sobre la fila del curso (antes que
     * ningún otro bloqueo, RN-CURSO-46), en este orden [DERIVADA]: curso en
     * `cerrado`; ningún otro `activo`; es el cerrado más reciente; ninguna
     * validación de reapertura falla. Si algo falla, nada cambia. En éxito
     * cambia `status` y **después**, en la misma transacción, inserta la fila
     * de `academic_year_reopenings` con el motivo (RN-CURSO-43): el disparador
     * de ADR-057 lee el estado ya actualizado por la propia transacción y la
     * admite. `$reason` llega ya recortado y no vacío (validado por la petición).
     *
     * El evento `AcademicYearReopened` se emite tras el *commit*.
     */
    public function reopen(string $publicId, string $reason): AcademicYear
    {
        try {
            $year = DB::transaction(function () use ($publicId, $reason): AcademicYear {
                $year = AcademicYear::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();

                if ($year->status !== AcademicYearStatus::Cerrado) {
                    throw CursoErrors::conflict('curso.conflict.invalid_transition', [
                        'from' => $year->status->value,
                        'to' => AcademicYearStatus::Activo->value,
                    ]);
                }

                $this->assertNoOtherActiveYear($year);
                $this->assertLatestClosedYear($year);
                $this->runReopenChecks($year);

                $year->status = AcademicYearStatus::Activo;
                $year->save();

                AcademicYearReopening::create([
                    'academic_year_id' => $year->getKey(),
                    'reason' => $reason,
                ]);

                return $year;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Carrera con una activación simultánea (RN-CURSO-41 (1)): el índice
            // único parcial del motor es la garantía última.
            if (str_contains($e->getMessage(), self::STATUS_INDEX)) {
                throw $this->activeExists(null);
            }

            throw $e;
        } finally {
            $this->context->forget();
        }

        $year->refresh();

        // Tras el commit: un consumidor que falla no revierte un hecho
        // consumado (ADR-048 §4.9, `funcional.md §7.2`).
        try {
            event(new AcademicYearReopened(
                tenantId: $year->tenant_id,
                academicYearPublicId: $year->public_id,
                code: $year->code,
                occurredAt: Carbon::now()->toImmutable(),
            ));
        } catch (Throwable $e) {
            report($e);
        }

        return $year;
    }

    private function assertValidTransition(AcademicYearStatus $from, AcademicYearStatus $to): void
    {
        $allowed = ($from === AcademicYearStatus::Planificacion && $to === AcademicYearStatus::Activo)
            || ($from === AcademicYearStatus::Activo && $to === AcademicYearStatus::Cerrado);

        if (! $allowed) {
            throw CursoErrors::conflict('curso.conflict.invalid_transition', ['from' => $from->value, 'to' => $to->value]);
        }
    }

    /**
     * RN-CURSO-71 (1.12b, `OPEN-CURSO-36` = A): activar un curso que es destino de una copia o una reversión en vuelo
     * (`pendiente`, `en_curso` o `revirtiendo`) responde `409 rollover_in_progress` y no cambia nada. Se lee bajo el
     * `FOR UPDATE` de la transición, sin bloquear `year_rollovers`: como la solicitud y el trabajo retienen `FOR SHARE`
     * sobre el curso destino, o se ve la copia registrada o la copia ve el curso ya activo (nunca se copia en un activo).
     */
    private function assertNoRolloverInFlight(AcademicYear $year): void
    {
        $inFlight = YearRollover::query()
            ->where('academic_year_id', $year->getKey())
            ->whereIn('status', array_map(static fn (YearRolloverStatus $s): string => $s->value, YearRolloverStatus::inFlight()))
            ->first();

        if ($inFlight !== null) {
            throw CursoErrors::rolloverConflict('curso.conflict.rollover_in_progress', [
                'public_id' => $inFlight->public_id,
                'status' => $inFlight->status->value,
            ]);
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

    /**
     * RN-CURSO-41 (2): no existe otro curso `cerrado` o `archivado` con
     * `starts_on` posterior (bien definido porque las fechas no se solapan,
     * RN-CURSO-05). Impide convertir un curso antiguo en el de referencia.
     */
    private function assertLatestClosedYear(AcademicYear $year): void
    {
        $later = AcademicYear::query()
            ->whereIn('status', [AcademicYearStatus::Cerrado, AcademicYearStatus::Archivado])
            ->where('starts_on', '>', $year->starts_on)
            ->whereKeyNot($year->getKey())
            ->orderBy('starts_on')
            ->first();

        if ($later !== null) {
            throw CursoErrors::conflict('curso.conflict.reopen_not_latest', [
                'public_id' => $later->public_id,
                'code' => $later->code,
            ]);
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
     * RN-CURSO-30: el registro está vacío en 1.10. Desde `1.11d-a` solo se
     * ejecutan las de módulos utilizables en el centro (RN-CURSO-60). Si alguna validación
     * falla, no cambia nada: una entrada por validación en `errors.closure[]`.
     */
    private function runClosureChecks(AcademicYear $year): void
    {
        $summary = $year->toSummary();
        $failures = [];

        foreach ($this->scopedChecks->enabled($this->closureChecks->all()) as $check) {
            $failure = $check->check($summary);

            if ($failure !== null) {
                $failures[] = ['code' => $failure->code, 'message' => $failure->message, 'params' => $failure->params];
            }
        }

        if ($failures !== []) {
            throw ApiException::conflict('curso.conflict.closure_checks_failed', [], ['closure' => $failures]);
        }
    }

    /**
     * RN-CURSO-45, RN-CURSO-60: igual que `runClosureChecks()`, con el registro
     * de reapertura (vacío al entregarse). Una entrada por validación fallida en
     * `errors.reopen[]`; si alguna falla, no cambia nada.
     */
    private function runReopenChecks(AcademicYear $year): void
    {
        $summary = $year->toSummary();
        $failures = [];

        foreach ($this->scopedChecks->enabled($this->reopenChecks->all()) as $check) {
            $failure = $check->check($summary);

            if ($failure !== null) {
                $failures[] = ['code' => $failure->code, 'message' => $failure->message, 'params' => $failure->params];
            }
        }

        if ($failures !== []) {
            throw ApiException::conflict('curso.conflict.reopen_checks_failed', [], ['reopen' => $failures]);
        }
    }
}
