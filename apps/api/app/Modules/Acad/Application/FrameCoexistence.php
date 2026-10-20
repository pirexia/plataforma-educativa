<?php

namespace App\Modules\Acad\Application;

use Illuminate\Database\Eloquent\Builder;

/**
 * REQ-ACAD/funcional.md §18.4.5 (`RN-ACAD-124`), 1.12-b: qué marcos horarios
 * **coexisten** con uno dado, es decir, cuáles pueden dar dos sesiones
 * coincidentes (y por tanto en conflicto):
 *
 * - dos `ordinaria` coexisten siempre, también de etapas distintas;
 * - un `especial` **no** coexiste con el `ordinaria` de su misma etapa (lo
 *   sustituye) y sí con el `ordinaria` de otra etapa;
 * - dos `especial` coexisten solo si sus fechas se cortan (intervalos
 *   cerrados). Dos `especial` vivos de la misma etapa nunca se solapan
 *   (`RN-ACAD-111`), así que solo coexiste con él el propio marco.
 *
 * Una sola implementación para la detección de conflictos de sesiones, la
 * comprobación del cambio de fechas de un marco especial y la de reservas.
 */
final class FrameCoexistence
{
    /**
     * Restringe `$query` —que ya une `timetable_frames` con el alias `$alias`—
     * a los marcos que coexisten con el descrito.
     *
     * @param  Builder<*>  $query
     */
    public static function constrain(Builder $query, string $type, int $levelId, ?string $validFrom, ?string $validTo, string $alias = 'f'): void
    {
        $query->where(function (Builder $outer) use ($type, $levelId, $validFrom, $validTo, $alias): void {
            if ($type === 'ordinaria') {
                $outer->where("{$alias}.frame_type", 'ordinaria')
                    ->orWhere(fn (Builder $q) => $q->where("{$alias}.frame_type", 'especial')->where("{$alias}.education_level_id", '<>', $levelId));

                return;
            }

            // Un especial: el ordinaria de otra etapa, o un especial con fechas que se cortan.
            $outer->where(fn (Builder $q) => $q->where("{$alias}.frame_type", 'ordinaria')->where("{$alias}.education_level_id", '<>', $levelId))
                ->orWhere(fn (Builder $q) => $q->where("{$alias}.frame_type", 'especial')
                    ->where("{$alias}.valid_from", '<=', (string) $validTo)
                    ->where("{$alias}.valid_to", '>=', (string) $validFrom));
        });
    }

    /**
     * `RN-ACAD-141`: restringe a los marcos **vigentes** en `$date`: el
     * `especial` cuya vigencia la contiene, o el `ordinaria` de una etapa que no
     * tiene ningún `especial` vivo vigente ese día.
     *
     * @param  Builder<*>  $query
     */
    public static function constrainInForceOn(Builder $query, string $date, string $alias = 'f'): void
    {
        $query->where(function (Builder $outer) use ($date, $alias): void {
            $outer->where(fn (Builder $q) => $q->where("{$alias}.frame_type", 'especial')
                ->where("{$alias}.valid_from", '<=', $date)
                ->where("{$alias}.valid_to", '>=', $date))
                ->orWhere(fn (Builder $q) => $q->where("{$alias}.frame_type", 'ordinaria')
                    ->whereNotExists(function ($sub) use ($date, $alias): void {
                        $sub->selectRaw('1')->from('timetable_frames as sp')
                            ->whereColumn('sp.tenant_id', "{$alias}.tenant_id")
                            ->whereColumn('sp.education_level_id', "{$alias}.education_level_id")
                            ->where('sp.frame_type', 'especial')
                            ->whereNull('sp.deleted_at')
                            ->where('sp.valid_from', '<=', $date)
                            ->where('sp.valid_to', '>=', $date);
                    }));
        });
    }
}
