<?php

namespace App\Modules\Curso\Domain;

use App\Models\User;

/**
 * REQ-CURSO/funcional.md §7.1, RN-CURSO-25 (1.10): leer datos de un curso
 * `cerrado`/`archivado` exige, además del permiso del módulo dueño del
 * dato, `curso_historico.leer`. Para `planificacion` y `activo` permite sin
 * pedir ese permiso.
 *
 * Es un contrato **invocado** por cada endpoint de lectura de los demás
 * módulos (a diferencia de la escritura, que impone el motor): `OPEN-057-03`
 * obliga a la especificación de cada módulo con datos por curso a exigir un
 * criterio de aceptación de denegación de lectura de curso cerrado en
 * listado y en detalle.
 */
interface AcademicYearReadAccess
{
    public function canRead(AcademicYearSummary $year, User $actor): bool;

    /**
     * @throws AcademicYearReadDeniedException el módulo `curso` la traduce a `404`
     */
    public function assertCanRead(AcademicYearSummary $year, User $actor): void;
}
