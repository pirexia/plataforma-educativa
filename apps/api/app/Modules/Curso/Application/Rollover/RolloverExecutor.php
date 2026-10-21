<?php

namespace App\Modules\Curso\Application\Rollover;

use App\Models\User;
use App\Modules\Core\Domain\UserDirectory;
use App\Modules\Curso\Domain\AcademicYearRolloverIssue;
use App\Modules\Curso\Domain\AcademicYearRolloverItem;
use App\Modules\Curso\Domain\AcademicYearRolloverParticipant;
use App\Modules\Curso\Domain\AcademicYearRolloverPlan;
use App\Modules\Curso\Domain\AcademicYearRolloverReversal;
use App\Modules\Curso\Domain\AcademicYearSharedLock;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Modules\Curso\Domain\AcademicYearSummary;
use App\Modules\Curso\Domain\Models\AcademicYear;
use App\Modules\Curso\Domain\Models\YearRollover;
use App\Modules\Curso\Domain\Models\YearRolloverItem;
use App\Modules\Curso\Domain\YearRolloverStatus;
use App\Modules\Curso\Infrastructure\RepeatableReadTransaction;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * REQ-CURSO/funcional.md `RN-CURSO-69`, `-70`, `-75`, `-83`, `-84` (1.12b): lo que hacen los dos
 * trabajos en cola (copia y reversión), fuera de la cola para poder ejecutarlos de forma directa
 * en los tests.
 *
 * **Actor** (`RN-CURSO-83`): el usuario de `created_by` (`reverted_by` en la reversión), cargado por
 * `Auth::setUser()` antes de abrir ninguna transacción y retirado al terminar (también si falla);
 * si no está activo, no se ejecuta nada y la copia queda `fallido` / `revert_failure` con
 * `actor_unavailable`.
 *
 * **Ciclo de vida** (`RN-CURSO-84`): toda transición de estado es condicional, con `FOR UPDATE` sobre la
 * fila de la copia y comprobación del estado de partida; si no coincide, no cambia nada.
 *
 * **Orden de bloqueos** (`RN-CURSO-70`): fila del curso destino (`FOR SHARE`) → fila de la copia
 * (`FOR UPDATE`) → bloqueos del participante → escrituras. Nunca `FOR UPDATE` sobre `academic_years`.
 */
final class RolloverExecutor
{
    public function __construct(
        private readonly RolloverCatalog $catalog,
        private readonly RolloverPlanner $planner,
        private readonly AcademicYearSharedLock $sharedLock,
        private readonly RepeatableReadTransaction $repeatableRead,
        private readonly UserDirectory $users,
    ) {}

    // ───────────────────────────── copia ─────────────────────────────

    public function run(int $rolloverId): void
    {
        $rollover = YearRollover::query()->find($rolloverId);

        // Un trabajo entregado de nuevo, o que llega tras una cancelación, no hace nada (RN-CURSO-84).
        if ($rollover === null || $rollover->status !== YearRolloverStatus::Pendiente) {
            return;
        }

        $actor = $this->activeUser($rollover->created_by);

        if ($actor === null) {
            $this->failCopy($rolloverId, 'actor_unavailable', [], [YearRolloverStatus::Pendiente]);

            return;
        }

        $this->actingAs($actor, fn () => $this->execute($rolloverId, $rollover->academic_year_id));
    }

    private function execute(int $rolloverId, int $targetYearId): void
    {
        if (! $this->start($rolloverId)) {
            return;
        }

        try {
            $this->repeatableRead->run(fn () => $this->copyInTransaction($rolloverId, $targetYearId));
        } catch (RolloverFailure $e) {
            $this->failCopy($rolloverId, $e->failureCode, $e->issues, [YearRolloverStatus::EnCurso]);
        } catch (QueryException $e) {
            // Serialización agotados los reintentos (40001) o choque de unicidad con algo creado
            // en el destino a la vez (23505): nada copiado, se puede repetir (RN-CURSO-69).
            if (in_array($e->errorInfo[0] ?? null, ['40001', '23505'], true)) {
                $this->failCopy($rolloverId, 'target_changed', [], [YearRolloverStatus::EnCurso]);

                return;
            }

            report($e);
            $this->failCopy($rolloverId, 'internal_error', [], [YearRolloverStatus::EnCurso]);
        } catch (Throwable $e) {
            report($e);
            $this->failCopy($rolloverId, 'internal_error', [], [YearRolloverStatus::EnCurso]);
        }
    }

    /** (a) `pendiente → en_curso` en una transacción corta propia. `false` si ya no estaba pendiente. */
    private function start(int $rolloverId): bool
    {
        return DB::transaction(function () use ($rolloverId): bool {
            $rollover = YearRollover::query()->whereKey($rolloverId)->lockForUpdate()->first();

            if ($rollover === null || $rollover->status !== YearRolloverStatus::Pendiente) {
                return false;
            }

            $rollover->status = YearRolloverStatus::EnCurso;
            $rollover->started_at = Carbon::now();
            $rollover->save();

            return true;
        });
    }

    /** (b) a (g): la transacción de la copia. */
    private function copyInTransaction(int $rolloverId, int $targetYearId): void
    {
        // (b) primera sentencia de la transacción (tras fijar el aislamiento): el FOR SHARE del
        // destino (RN-CURSO-70, RN-CURSO-86). El identificador del destino llega ya leído.
        $target = $this->sharedLock->lockForShareById($targetYearId);

        // (c) la fila de la copia, y que siga en curso (si no —p. ej. cancelada—, no hace nada).
        $rollover = YearRollover::query()->whereKey($rolloverId)->lockForUpdate()->first();

        if ($rollover === null || $target === null || $rollover->status !== YearRolloverStatus::EnCurso) {
            return;
        }

        // (d) el destino sigue en planificación; los módulos siguen utilizables; el desplazamiento.
        if ($target->status !== AcademicYearStatus::Planificacion) {
            throw new RolloverFailure('target_not_planning');
        }

        $source = AcademicYear::query()->find($rollover->source_academic_year_id)?->toSummary();

        if ($source === null) {
            throw new RolloverFailure('internal_error');
        }

        $catalog = $this->catalog->elements();
        $unavailable = [];

        foreach ($rollover->elements as $code) {
            if (! isset($catalog[$code])) {
                $unavailable[] = new AcademicYearRolloverIssue('curso.rollover.element_unavailable', (string) __('curso.rollover.element_unavailable', ['element' => $code]), ['element' => $code], $code);
            }
        }

        if ($unavailable !== []) {
            throw new RolloverFailure('rollover_blocked', $unavailable);
        }

        $offset = RolloverDates::offsetDays($source, $target);
        $elementResults = [];
        $items = [];

        // (e) por cada participante, en el orden del catálogo: preview() y, si hay incidencias, falla
        // toda la copia; si no, copy().
        foreach ($this->catalog->participants() as $participant) {
            $codes = $this->planner->selectedCodes($participant, $rollover->elements);

            if ($codes === []) {
                continue;
            }

            $plan = new AcademicYearRolloverPlan($source, $target, $codes, $offset, $rollover->id);
            $issues = $participant->preview($plan)->issues();

            if ($issues !== []) {
                throw new RolloverFailure('rollover_blocked', $issues);
            }

            $result = $participant->copy($plan);

            foreach ($result->elements as $element) {
                $elementResults[] = [
                    'code' => $element->element,
                    'copied' => $element->copied,
                    'skipped' => $element->skipped,
                    'adjusted' => $element->adjusted,
                ];
            }

            foreach ($result->items as $item) {
                $items[] = [$participant->moduleCode(), $item];
            }
        }

        // (f) los items, por instancia a través del modelo (cada uno deja su `created`, OPEN-CURSO-37 = b).
        foreach ($items as [$moduleCode, $item]) {
            /** @var AcademicYearRolloverItem $item */
            YearRolloverItem::create([
                'academic_year_id' => $target->id,
                'year_rollover_id' => $rollover->id,
                'module_code' => $moduleCode,
                'entity_type' => $item->entityType,
                'source_id' => $item->sourceId,
                'target_id' => $item->targetId,
            ]);
        }

        $rollover->status = YearRolloverStatus::Completado;
        $rollover->date_offset_days = $offset;
        $rollover->result = ['elements' => $elementResults];
        $rollover->finished_at = Carbon::now();
        $rollover->save();
    }

    /**
     * `en_curso → fallido` (o `pendiente → fallido` para `actor_unavailable`), en otra transacción y
     * solo si el estado sigue siendo el de partida (RN-CURSO-84).
     *
     * @param  list<AcademicYearRolloverIssue>  $issues
     * @param  list<YearRolloverStatus>  $from
     */
    private function failCopy(int $rolloverId, string $code, array $issues, array $from): void
    {
        DB::transaction(function () use ($rolloverId, $code, $issues, $from): void {
            $rollover = YearRollover::query()->whereKey($rolloverId)->lockForUpdate()->first();

            if ($rollover === null || ! in_array($rollover->status, $from, true)) {
                return;
            }

            $rollover->status = YearRolloverStatus::Fallido;
            $rollover->failure = RolloverFailure::payload($code, $issues);
            $rollover->finished_at = Carbon::now();
            $rollover->save();
        });
    }

    // ───────────────────────────── reversión ─────────────────────────────

    public function runRevert(int $rolloverId): void
    {
        $rollover = YearRollover::query()->find($rolloverId);

        if ($rollover === null || $rollover->status !== YearRolloverStatus::Revirtiendo) {
            return;
        }

        $actor = $this->activeUser($rollover->reverted_by);

        if ($actor === null) {
            $this->failRevert($rolloverId, 'actor_unavailable', []);

            return;
        }

        $this->actingAs($actor, function () use ($rolloverId, $rollover): void {
            try {
                DB::transaction(fn () => $this->revertInTransaction($rolloverId, $rollover->academic_year_id));
            } catch (RolloverFailure $e) {
                // «módulo no utilizable» es un 409 propio en la solicitud; como fallo persistido es un bloqueo.
                $code = $e->failureCode === 'revert_module_unavailable' ? 'rollover_blocked' : $e->failureCode;
                $this->failRevert($rolloverId, $code, $e->issues);
            } catch (Throwable $e) {
                report($e);
                $this->failRevert($rolloverId, 'internal_error', []);
            }
        });
    }

    private function revertInTransaction(int $rolloverId, int $targetYearId): void
    {
        $target = $this->sharedLock->lockForShareById($targetYearId);
        $rollover = YearRollover::query()->whereKey($rolloverId)->lockForUpdate()->first();

        // Bajo el FOR UPDATE de la copia: si ya no está revirtiendo (p. ej. cancelada), no hace nada.
        if ($rollover === null || $target === null || $rollover->status !== YearRolloverStatus::Revirtiendo) {
            return;
        }

        // Las comprobaciones (4) a (6) de RN-CURSO-74, repetidas.
        $reversals = $this->reversals($rollover, $target);

        // RN-CURSO-75: en orden inverso al de la copia, cada participante borra lógicamente sus filas.
        foreach (array_reverse($reversals) as [$participant, $reversal]) {
            $participant->revert($reversal);
        }

        $rollover->status = YearRolloverStatus::Revertido;
        $rollover->reverted_at = Carbon::now();
        $rollover->save();
    }

    /**
     * RN-CURSO-74 (4) a (6): el destino en planificación, los módulos de los items utilizables y sin incidencias
     * de `checkRevert()`. Devuelve los pares (participante, reversión) en el orden de la copia.
     *
     * @return list<array{0: AcademicYearRolloverParticipant, 1: AcademicYearRolloverReversal}>
     *
     * @throws RolloverFailure con el código y las incidencias del primer impedimento
     */
    public function reversals(YearRollover $rollover, AcademicYearSummary $target): array
    {
        if ($target->status !== AcademicYearStatus::Planificacion) {
            throw new RolloverFailure('target_not_planning');
        }

        $byModule = [];

        foreach (YearRolloverItem::query()->where('year_rollover_id', $rollover->id)->orderBy('id')->get() as $item) {
            $byModule[$item->module_code][] = new AcademicYearRolloverItem($item->entity_type, $item->source_id, $item->target_id);
        }

        $pairs = [];

        foreach ($this->catalog->participants() as $participant) {
            if (isset($byModule[$participant->moduleCode()])) {
                $pairs[] = [$participant, new AcademicYearRolloverReversal($target, $byModule[$participant->moduleCode()])];
                unset($byModule[$participant->moduleCode()]);
            }
        }

        if ($byModule !== []) {
            $module = (string) array_key_first($byModule);

            throw new RolloverFailure('revert_module_unavailable', [
                new AcademicYearRolloverIssue('curso.conflict.rollover_revert_module_unavailable', (string) __('curso.conflict.rollover_revert_module_unavailable', ['module' => $module]), ['module' => $module]),
            ]);
        }

        $issues = [];

        foreach ($pairs as [$participant, $reversal]) {
            array_push($issues, ...$participant->checkRevert($reversal));
        }

        if ($issues !== []) {
            throw new RolloverFailure('rollover_blocked', $issues);
        }

        return $pairs;
    }

    /**
     * `revirtiendo → completado` con `revert_failure`, en otra transacción y solo si sigue en `revirtiendo`.
     *
     * @param  list<AcademicYearRolloverIssue>  $issues
     */
    private function failRevert(int $rolloverId, string $code, array $issues): void
    {
        DB::transaction(function () use ($rolloverId, $code, $issues): void {
            $rollover = YearRollover::query()->whereKey($rolloverId)->lockForUpdate()->first();

            if ($rollover === null || $rollover->status !== YearRolloverStatus::Revirtiendo) {
                return;
            }

            $rollover->status = YearRolloverStatus::Completado;
            $rollover->revert_failure = RolloverFailure::payload($code, $issues);
            $rollover->save();
        });
    }

    // ───────────────────────────── failed() ─────────────────────────────

    /**
     * `failed()` de los trabajos (`RN-CURSO-84` (3)): `pendiente`/`en_curso` ⇒ `fallido` `internal_error`;
     * `revirtiendo` ⇒ `completado` con `revert_failure` `internal_error`; cualquier otro estado, nada.
     */
    public function failedByQueue(int $rolloverId): void
    {
        $rollover = YearRollover::query()->find($rolloverId);

        if ($rollover === null) {
            return;
        }

        if ($rollover->status === YearRolloverStatus::Revirtiendo) {
            $this->failRevert($rolloverId, 'internal_error', []);

            return;
        }

        $this->failCopy($rolloverId, 'internal_error', [], [YearRolloverStatus::Pendiente, YearRolloverStatus::EnCurso]);
    }

    // ───────────────────────────── actor ─────────────────────────────

    /** RN-CURSO-83: el usuario, solo si sigue activo (`UserDirectory::activeIdsAmong()`, I-19). */
    private function activeUser(?int $userId): ?User
    {
        if ($userId === null || $this->users->activeIdsAmong([$userId]) === []) {
            return null;
        }

        return User::query()->find($userId);
    }

    /**
     * Fija el actor antes de abrir ninguna transacción y lo retira al terminar, también si falla; si ya había
     * un usuario autenticado (ejecución en línea dentro de una petición), lo restaura.
     */
    private function actingAs(User $actor, Closure $callback): void
    {
        $previous = Auth::user();
        Auth::setUser($actor);

        try {
            $callback();
        } finally {
            if ($previous !== null) {
                Auth::setUser($previous);
            } else {
                Auth::forgetUser();
            }
        }
    }
}
