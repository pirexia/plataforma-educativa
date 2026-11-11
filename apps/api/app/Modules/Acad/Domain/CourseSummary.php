<?php

namespace App\Modules\Acad\Domain;

/**
 * REQ-ALUM/funcional.md §4.2, RN-ALUM-20 (1.15-b T2a): resumen inmutable de un curso de etapa vivo, sin exponer el
 * modelo Eloquent (INV-007). `nameTranslations` es el mapa idioma => nombre tal como se guarda (la resolución del
 * idioma, ADR-062, es de quien lo presenta).
 */
final readonly class CourseSummary
{
    /**
     * @param  array<string, string>  $nameTranslations
     */
    public function __construct(
        public int $id,
        public string $publicId,
        public int $academicYearId,
        public int $educationLevelId,
        public string $code,
        public array $nameTranslations,
    ) {}
}
