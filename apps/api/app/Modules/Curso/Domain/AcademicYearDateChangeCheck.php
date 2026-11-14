<?php

namespace App\Modules\Curso\Domain;

/**
 * REQ-CURSO/funcional.md §5.6, RN-CURSO-49 (1.11d-a): validación bloqueante
 * de un cambio de fechas de un curso que registra un módulo (en `acad`, «el
 * cambio no deja fuera ningún período lectivo vivo», RN-ACAD-62), vía
 * `AcademicYearDateChangeRegistry`. Contrato síncrono en el módulo
 * propietario, no evento: el `PATCH` necesita el resultado (`ADR-048 §4.9`).
 *
 * Se ejecuta con el `FOR UPDATE` del curso retenido (RN-CURSO-51): **debe ser
 * acotada, de solo lectura y no tomar ningún bloqueo de fila** (ni
 * `FOR SHARE` ni `FOR UPDATE`; riesgo de interbloqueo, RN-CURSO-58).
 */
interface AcademicYearDateChangeCheck extends ModuleScopedContribution
{
    /** Código de módulo del descriptor (`ADR-045`) del módulo dueño (RN-CURSO-52). */
    public function moduleCode(): string;

    /** @return AcademicYearDateChangeFailure|null `null` si el cambio se admite */
    public function check(AcademicYearDateChange $change): ?AcademicYearDateChangeFailure;
}
