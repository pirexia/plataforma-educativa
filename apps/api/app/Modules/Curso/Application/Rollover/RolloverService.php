<?php

namespace App\Modules\Curso\Application\Rollover;

use App\Models\User;
use App\Modules\Curso\Application\CursoErrors;
use App\Modules\Curso\Domain\AcademicYearReadAccess;
use App\Modules\Curso\Domain\AcademicYearSharedLock;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Modules\Curso\Domain\Models\AcademicYear;
use App\Modules\Curso\Domain\Models\YearRollover;
use App\Modules\Curso\Domain\YearRolloverStatus;
use App\Modules\Curso\Infrastructure\Jobs\RevertAcademicYearRollover;
use App\Modules\Curso\Infrastructure\Jobs\RunAcademicYearRollover;
use App\Support\Api\ApiException;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * REQ-CURSO/funcional.md `RN-CURSO-67`, `-68`, `-74` (1.12b): las dos solicitudes síncronas de la apertura
 * del curso siguiente —la copia y la reversión—, que validan y bloquean, registran el estado y **después** de
 * confirmar la transacción envían el trabajo a la cola (nunca dentro de ella, issue #189).
 *
 * Orden de bloqueos (`RN-CURSO-70`): fila del curso destino (`FOR SHARE`, `AcademicYearSharedLock`, antes que
 * ningún otro bloqueo, `RN-CURSO-55`, `-58`) → fila de la copia (`FOR UPDATE`) → escrituras.
 */
final class RolloverService
{
    public function __construct(
        private readonly RolloverResolver $resolver,
        private readonly RolloverPlanner $planner,
        private readonly RolloverExecutor $executor,
        private readonly AcademicYearSharedLock $sharedLock,
        private readonly AcademicYearReadAccess $readAccess,
    ) {}

    /**
     * `POST /academic-year-rollovers` (`RN-CURSO-68`): en una transacción y en este orden, (1) `FOR SHARE` del
     * destino y comprobación de `planificacion`; (2) `RN-CURSO-63`, `-64`, `-78`; (3) ¿hay copia viva?;
     * (4) simulación de los participantes e incidencias; (5) alta en `pendiente`; (6) confirmación y envío del trabajo.
     */
    public function request(User $actor, RolloverRequest $request): YearRollover
    {
        $rollover = DB::transaction(function () use ($actor, $request): YearRollover {
            $resolved = $this->resolver->resolve($actor, $request, lockTarget: true);

            $live = $this->planner->liveRollover($resolved->target);

            if ($live !== null) {
                throw $this->exists($live);
            }

            $preview = $this->planner->preview($resolved);

            if (! $preview->executable()) {
                throw CursoErrors::rolloverConflict('curso.conflict.rollover_blocked', issues: $preview->issues());
            }

            try {
                // Punto de guardado propio: una violación del único (`23505`) no deja abortada la transacción.
                return DB::transaction(fn (): YearRollover => YearRollover::create([
                    'academic_year_id' => $resolved->target->id,
                    'source_academic_year_id' => $resolved->source->id,
                    'status' => YearRolloverStatus::Pendiente,
                    'elements' => $resolved->effective,
                    'copy_teaching_assignments' => $resolved->copyTeachingAssignments,
                ]));
            } catch (UniqueConstraintViolationException $e) {
                // La carrera entre dos solicitudes la resuelve el índice único parcial (RN-CURSO-67).
                $live = $this->planner->liveRollover($resolved->target);

                throw $live !== null ? $this->exists($live) : $e;
            }
        });

        $this->dispatch(fn () => RunAcademicYearRollover::dispatch($rollover->id), $rollover);

        return $rollover->refresh();
    }

    /**
     * `POST /academic-year-rollovers/{id}/revert` (`RN-CURSO-74`): (1) `FOR SHARE` del destino; (2) `FOR UPDATE` de
     * la copia; (3) está en `completado`; (4) el destino sigue en `planificacion`; (5) los módulos con items
     * siguen utilizables; (6) `checkRevert()`; (7) pasa a `revirtiendo` y se limpia `revert_failure`; (8) tras
     * confirmar, se encola el trabajo.
     */
    public function requestRevert(User $actor, string $publicId): YearRollover
    {
        $rollover = $this->findReadable($publicId, $actor);

        $rollover = DB::transaction(function () use ($actor, $rollover): YearRollover {
            $target = $this->sharedLock->lockForShareById($rollover->academic_year_id);
            $locked = YearRollover::query()->whereKey($rollover->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== YearRolloverStatus::Completado) {
                throw CursoErrors::rolloverConflict('curso.conflict.rollover_not_revertible', ['status' => $locked->status->value]);
            }

            if ($target === null || $target->status !== AcademicYearStatus::Planificacion) {
                throw CursoErrors::rolloverConflict('curso.conflict.rollover_target_not_planning');
            }

            try {
                $this->executor->reversals($locked, $target);
            } catch (RolloverFailure $e) {
                throw match ($e->failureCode) {
                    'target_not_planning' => CursoErrors::rolloverConflict('curso.conflict.rollover_target_not_planning'),
                    'revert_module_unavailable' => CursoErrors::rolloverConflict(
                        'curso.conflict.rollover_revert_module_unavailable',
                        ['module' => (string) ($e->issues[0]->params['module'] ?? '')],
                    ),
                    default => CursoErrors::rolloverConflict('curso.conflict.rollover_revert_blocked', issues: $e->issues, errorsKey: 'rollover_revert'),
                };
            }

            $locked->status = YearRolloverStatus::Revirtiendo;
            $locked->reverted_by = $actor->id;
            $locked->revert_failure = null;
            $locked->save();

            return $locked;
        });

        $this->dispatch(fn () => RevertAcademicYearRollover::dispatch($rollover->id), $rollover);

        return $rollover->refresh();
    }

    /**
     * Una copia del centro cuyo destino puede leer el usuario (`RN-CURSO-80`); si no, `404` (`INV-001`, `RN-CURSO-25`).
     */
    public function findReadable(string $publicId, User $actor): YearRollover
    {
        $rollover = YearRollover::query()->where('public_id', $publicId)->firstOrFail();
        $target = AcademicYear::query()->find($rollover->academic_year_id)?->toSummary();

        if ($target === null) {
            throw (new ModelNotFoundException)->setModel(YearRollover::class);
        }

        // `404` con el mismo cuerpo que un recurso inexistente (`AcademicYearReadDeniedException`).
        $this->readAccess->assertCanRead($target, $actor);

        return $rollover;
    }

    /**
     * El envío del trabajo ocurre tras confirmar. Si falla, la copia queda registrada sin trabajo (`RN-CURSO-68`
     * paso 6): se avisa en el registro y la salida es la cancelación pasados 30 minutos (`RN-CURSO-85`).
     *
     * @param  Closure(): mixed  $send
     */
    private function dispatch(Closure $send, YearRollover $rollover): void
    {
        try {
            $send();
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function exists(YearRollover $live): ApiException
    {
        return CursoErrors::rolloverConflict('curso.conflict.rollover_exists', [
            'public_id' => $live->public_id,
            'status' => $live->status->value,
        ]);
    }
}
