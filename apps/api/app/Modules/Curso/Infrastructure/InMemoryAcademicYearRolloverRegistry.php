<?php

namespace App\Modules\Curso\Infrastructure;

use App\Modules\Curso\Domain\AcademicYearRolloverParticipant;
use App\Modules\Curso\Domain\AcademicYearRolloverRegistry;

/**
 * RN-CURSO-61 (1.12b): registro de participantes de la apertura del curso
 * siguiente. Singleton del contenedor; cada módulo registra el suyo desde su
 * `ServiceProvider`.
 */
final class InMemoryAcademicYearRolloverRegistry implements AcademicYearRolloverRegistry
{
    /** @var list<AcademicYearRolloverParticipant> */
    private array $participants = [];

    public function register(AcademicYearRolloverParticipant $participant): void
    {
        $this->participants[] = $participant;
    }

    public function all(): array
    {
        return $this->participants;
    }
}
