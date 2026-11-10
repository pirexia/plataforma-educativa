<?php

namespace App\Modules\Acad\Domain;

/**
 * REQ-ACAD/funcional.md §7.2: resumen inmutable de un grupo vivo, sin exponer
 * el modelo Eloquent (INV-007). `regime` es el de su etapa (`education_levels.regime`).
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
    ) {}
}
