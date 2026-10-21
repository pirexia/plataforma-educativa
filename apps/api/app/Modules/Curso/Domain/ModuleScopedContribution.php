<?php

namespace App\Modules\Curso\Domain;

/**
 * REQ-CURSO/funcional.md `RN-CURSO-61` (1.12b): lo que tienen en común las
 * contribuciones que un módulo registra en `curso` —validaciones de cierre,
 * de reapertura y del cambio de fechas, y participantes de la apertura del
 * curso siguiente—: el código del módulo dueño, con el que `curso` decide si
 * se ejecutan (`RN-CURSO-52`, `-60`, `-62`). Generaliza el tipo de unión que
 * antes tenía `ModuleScopedChecks`. Cambio interno de `curso`, sin efecto en
 * los consumidores existentes.
 */
interface ModuleScopedContribution
{
    /** Código de módulo del descriptor (`ADR-045`) del módulo dueño. */
    public function moduleCode(): string;
}
