<?php

namespace App\Modules\Curso\Domain;

/**
 * REQ-CURSO/datos.md §1.6 (1.12b): estados de una copia (`year_rollovers.status`).
 * `pendiente → en_curso → completado | fallido`; `completado → revirtiendo →
 * revertido | completado` (`RN-CURSO-84`). Viajan sin traducir (`ADR-038 §3.2`).
 */
enum YearRolloverStatus: string
{
    case Pendiente = 'pendiente';
    case EnCurso = 'en_curso';
    case Completado = 'completado';
    case Fallido = 'fallido';
    case Revirtiendo = 'revirtiendo';
    case Revertido = 'revertido';

    /**
     * RN-CURSO-67: las que cuentan como «copia viva» hacia un destino (el índice
     * único parcial `year_rollovers_tenant_year_live_unique`).
     *
     * @return list<self>
     */
    public static function live(): array
    {
        return [self::Pendiente, self::EnCurso, self::Completado, self::Revirtiendo];
    }

    /**
     * RN-CURSO-71, RN-CURSO-85: en vuelo (hay, o debería haber, un trabajo en
     * cola): bloquean la activación del destino y son cancelables si caducan.
     *
     * @return list<self>
     */
    public static function inFlight(): array
    {
        return [self::Pendiente, self::EnCurso, self::Revirtiendo];
    }

    public function isInFlight(): bool
    {
        return in_array($this, self::inFlight(), true);
    }
}
