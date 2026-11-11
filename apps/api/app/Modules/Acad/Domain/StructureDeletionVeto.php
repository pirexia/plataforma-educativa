<?php

namespace App\Modules\Acad\Domain;

use App\Modules\Curso\Domain\ModuleScopedContribution;

/**
 * RN-ACAD-21 (`OPEN-ACAD-13` = A), forma de `RN-CURSO-30`: veto de borrado que
 * registra un módulo con referencias a grupos o cursos de etapa (p. ej. `ALUM`,
 * `RN-ALUM-26`). `acad` solo lo ejecuta si el módulo dueño está contratado
 * (`RN-CURSO-60`, vía `moduleCode()`).
 *
 * Se ejecuta dentro de la transacción del borrado, con la fila de `acad`
 * bloqueada: **debe ser acotado** y **no puede bloquear filas de otros
 * módulos** (riesgo de interbloqueo, `RN-CURSO-32`).
 */
interface StructureDeletionVeto extends ModuleScopedContribution
{
    /** Código de módulo del descriptor (`ADR-045`) del módulo dueño. */
    public function moduleCode(): string;

    /** Entidad cuyo borrado puede vetar. */
    public function appliesTo(): StructureEntity;

    /**
     * @param  int  $internalId  id interno del grupo o del curso de etapa, según `appliesTo()`
     * @return VetoFailure|null `null` si el borrado no está vetado
     */
    public function check(int $internalId): ?VetoFailure;
}
