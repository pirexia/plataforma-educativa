<?php

namespace App\Modules\Curso\Domain;

/**
 * REQ-CURSO/funcional.md §7.1, RN-CURSO-45 (1.11c, `ADR-059 §5.5`): validación
 * bloqueante de la reapertura de un curso cerrado que registra un módulo (p. ej.
 * `1.17b`: «promoción aplicada» o «paquete de cierre generado» impiden reabrir),
 * vía `AcademicYearReopenRegistry`. Simétrica a `AcademicYearClosureCheck`.
 * Contrato síncrono en el módulo propietario, no evento: la reapertura necesita
 * el resultado (`ADR-048 §4.9`).
 *
 * Se ejecuta con el `FOR UPDATE` del curso retenido (RN-CURSO-46): **debe ser
 * acotada** y **no puede tomar bloqueos de filas de otros módulos** antes que el
 * del curso (RN-CURSO-32; riesgo de interbloqueo, ADR-057 §5.4).
 */
interface AcademicYearReopenCheck
{
    /** Código de módulo del descriptor (`ADR-045`) del módulo dueño (RN-CURSO-60). */
    public function moduleCode(): string;

    /** @return AcademicYearReopenFailure|null `null` si el curso puede reabrirse */
    public function check(AcademicYearSummary $year): ?AcademicYearReopenFailure;
}
