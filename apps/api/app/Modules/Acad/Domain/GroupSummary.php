<?php

namespace App\Modules\Acad\Domain;

/**
 * REQ-ACAD/funcional.md §7.2: resumen inmutable de un grupo vivo, sin exponer
 * el modelo Eloquent (INV-007). `regime` es el de su etapa (`education_levels.regime`).
 * `code` y `name` (texto simple, no traducible) los añadió `REQ-ALUM` 1.15-b T2b para listar el grupo de una matrícula.
 */
final readonly class GroupSummary
{
    public function __construct(
        public int $id,
        public string $publicId,
        public int $academicYearId,
        public int $courseId,
        public int $educationLevelId,
        public string $regime,
        public string $code = '',
        public string $name = '',
    ) {}
}
