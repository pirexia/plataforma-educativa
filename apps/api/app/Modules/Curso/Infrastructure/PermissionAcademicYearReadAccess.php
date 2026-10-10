<?php

namespace App\Modules\Curso\Infrastructure;

use App\Models\User;
use App\Modules\Curso\Domain\AcademicYearReadAccess;
use App\Modules\Curso\Domain\AcademicYearReadDeniedException;
use App\Modules\Curso\Domain\AcademicYearSummary;
use App\Support\Authorization\PermissionResolver;

/**
 * RN-CURSO-25, `OPEN-CURSO-16`: `cerrado`/`archivado` exigen
 * `curso_historico.leer`; `planificacion` y `activo` no piden ese permiso
 * (el del módulo dueño del dato lo exige su propio endpoint). Comprueba el
 * permiso, nunca un código de rol (`RN-PERM-46`).
 */
final class PermissionAcademicYearReadAccess implements AcademicYearReadAccess
{
    public const HISTORY_PERMISSION = 'curso_historico.leer';

    public function __construct(private readonly PermissionResolver $permissions) {}

    public function canRead(AcademicYearSummary $year, User $actor): bool
    {
        if (! $year->isReadOnly()) {
            return true;
        }

        return $this->permissions->can($actor, self::HISTORY_PERMISSION);
    }

    public function assertCanRead(AcademicYearSummary $year, User $actor): void
    {
        if (! $this->canRead($year, $actor)) {
            throw new AcademicYearReadDeniedException($year);
        }
    }
}
