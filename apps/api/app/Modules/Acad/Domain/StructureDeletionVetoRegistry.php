<?php

namespace App\Modules\Acad\Domain;

/**
 * RN-ACAD-21: registro de vetos de borrado de grupos y cursos de etapa. Vacío
 * hasta que un módulo registra el suyo. Precedente: `AcademicYearClosureRegistry`.
 */
interface StructureDeletionVetoRegistry
{
    public function register(StructureDeletionVeto $veto): void;

    /** @return list<StructureDeletionVeto> */
    public function all(): array;
}
