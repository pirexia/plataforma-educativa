<?php

namespace App\Models;

/**
 * ADR-034 §4. Como mucho un curso `Activo` y uno `Planificacion` por
 * tenant (índice único parcial en la migración, no solo aquí).
 */
enum AcademicYearStatus: string
{
    case Planificacion = 'planificacion';
    case Activo = 'activo';
    case Cerrado = 'cerrado';
    case Archivado = 'archivado';
}
