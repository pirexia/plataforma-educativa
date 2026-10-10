<?php

namespace App\Modules\Curso\Domain;

/**
 * ADR-034 §4, REQ-CURSO/funcional.md §3.1, §7.1 (1.10, OPEN-CURSO-03). Como
 * mucho un curso `Activo` y uno `Planificacion` por tenant (índice único
 * parcial en la migración, no solo aquí).
 *
 * Vive en `Curso\Domain` (fuera de `Domain\Models`) para que los demás
 * módulos puedan usar el vocabulario sin tocar el modelo (`AR-01`).
 *
 * `isReadOnly()` declara qué estados son de solo lectura (RN-CURSO-20). El
 * mismo conjunto está duplicado a propósito en
 * `app.assert_academic_year_writable()` (ADR-057 §5.2); el test de paridad
 * `CA-057-07` los une.
 */
enum AcademicYearStatus: string
{
    case Planificacion = 'planificacion';
    case Activo = 'activo';
    case Cerrado = 'cerrado';
    case Archivado = 'archivado';

    /** RN-CURSO-20: `cerrado` y `archivado` no admiten escritura de datos del curso. */
    public function isReadOnly(): bool
    {
        return match ($this) {
            self::Cerrado, self::Archivado => true,
            self::Planificacion, self::Activo => false,
        };
    }

    /**
     * Valores (los que viajan por API y por SQL) de los estados de solo lectura.
     *
     * @return list<string>
     */
    public static function readOnlyValues(): array
    {
        return array_values(array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => $status->isReadOnly()),
        ));
    }
}
