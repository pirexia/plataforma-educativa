<?php

namespace App\Modules\Curso\Application\Rollover;

use App\Models\User;
use App\Modules\Curso\Application\CursoErrors;
use App\Modules\Curso\Domain\AcademicYearReadAccess;
use App\Modules\Curso\Domain\AcademicYearSharedLock;
use App\Modules\Curso\Domain\Models\AcademicYear;
use App\Modules\Curso\Domain\Models\YearRollover;
use App\Modules\Curso\Domain\YearRolloverStatus;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * REQ-CURSO/funcional.md `RN-CURSO-85` (1.12b, revisión de documentación A-3): cancelación de una copia
 * huérfana —en `pendiente`, `en_curso` o `revirtiendo` sin trabajo vivo (envío fallido tras confirmar,
 * `failed_jobs`, *worker* caído)—, que bloquea el destino (`RN-CURSO-67`, `-71`). La usan el *endpoint*
 * `POST /academic-year-rollovers/{id}/cancel` y el comando `curso:rollover-reset`.
 *
 * Solo si su `updated_at` tiene más de 30 minutos (caducidad aceptada por el usuario el 2026-10-21). Bajo
 * `FOR UPDATE` de la copia (si un trabajo la retiene, la cancelación espera y luego ve su estado final):
 * `pendiente`/`en_curso` ⇒ `fallido` con `failure.code = "cancelled"`; `revirtiendo` ⇒ `completado` con
 * `revert_failure.code = "cancelled"`. No toca datos de ningún módulo: una copia en `en_curso` no confirmó nada
 * (todo o nada). La entrada `updated` de la auditoría lleva a quien cancela (o ningún actor, desde consola).
 */
final class RolloverCancellation
{
    public function __construct(
        private readonly AcademicYearSharedLock $sharedLock,
        private readonly AcademicYearReadAccess $readAccess,
    ) {}

    /**
     * @param  User|null  $actor  `null` desde el comando de plataforma (sin comprobar lectura del destino)
     * @param  bool  $force  salta la caducidad (solo el comando, tras confirmación)
     */
    public function cancel(string $publicId, ?User $actor, bool $force = false): YearRollover
    {
        $rollover = YearRollover::query()->where('public_id', $publicId)->firstOrFail();

        if ($actor !== null) {
            $target = AcademicYear::query()->find($rollover->academic_year_id)?->toSummary();

            if ($target === null) {
                throw (new ModelNotFoundException)->setModel(YearRollover::class);
            }

            $this->readAccess->assertCanRead($target, $actor);
        }

        return DB::transaction(function () use ($rollover, $force): YearRollover {
            // RN-CURSO-70: primero el curso destino (FOR SHARE), después la copia (FOR UPDATE).
            $this->sharedLock->lockForShareById($rollover->academic_year_id);
            $locked = YearRollover::query()->whereKey($rollover->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isInFlight()) {
                throw CursoErrors::rolloverConflict('curso.conflict.rollover_not_cancellable', ['status' => $locked->status->value]);
            }

            $availableAt = self::availableAt($locked);

            if (! $force && Carbon::now()->lessThan($availableAt)) {
                throw CursoErrors::rolloverConflict('curso.conflict.rollover_cancel_too_early', ['available_at' => $availableAt->toIso8601String()]);
            }

            $payload = RolloverFailure::payload('cancelled');

            if ($locked->status === YearRolloverStatus::Revirtiendo) {
                $locked->status = YearRolloverStatus::Completado;
                $locked->revert_failure = $payload;
            } else {
                $locked->status = YearRolloverStatus::Fallido;
                $locked->failure = $payload;
                $locked->finished_at = Carbon::now();
            }

            $locked->save();

            return $locked;
        });
    }

    /** El momento a partir del cual se puede cancelar: `updated_at` + 30 minutos (`RN-CURSO-85`). */
    public static function availableAt(YearRollover $rollover): Carbon
    {
        return Carbon::instance($rollover->updated_at ?? Carbon::now())->addMinutes((int) config('curso.rollover.cancel_after_minutes', 30));
    }
}
