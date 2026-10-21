<?php

namespace App\Modules\Curso\Domain;

/**
 * REQ-CURSO/funcional.md `RN-CURSO-61` (1.12b): registro de participantes de
 * la apertura del curso siguiente. Cada módulo registra el suyo desde el
 * `boot()` de su `ServiceProvider`. Precedente: `AcademicYearClosureRegistry`
 * (`RN-CURSO-30`).
 */
interface AcademicYearRolloverRegistry
{
    public function register(AcademicYearRolloverParticipant $participant): void;

    /** @return list<AcademicYearRolloverParticipant> en orden de registro */
    public function all(): array;
}
