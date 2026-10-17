<?php

namespace App\Modules\Curso\Domain\Events;

use Carbon\CarbonImmutable;

/**
 * REQ-CURSO/funcional.md §7.2, RN-CURSO-44 (1.11c, `ADR-059 §5.4`): hecho
 * consumado «el curso pasó de `cerrado` a `activo`». Se emite tras el *commit*
 * de la transición (nunca dentro de la transacción, issue #189), con
 * identificadores públicos y **sin el motivo** ni datos personales. Un
 * consumidor que falla no revierte la reapertura (`ADR-048 §4.9`).
 *
 * Consumidores previstos: los mismos que `AcademicYearClosed` — todo proceso
 * que trate el cierre como hecho consumado debe tolerar que el curso vuelva a
 * `activo` (`ADR-059 §7`). El aviso a otras personas es `OPEN-059-05` (`1.19`).
 */
final class AcademicYearReopened
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $academicYearPublicId,
        public readonly string $code,
        public readonly CarbonImmutable $occurredAt,
    ) {}
}
