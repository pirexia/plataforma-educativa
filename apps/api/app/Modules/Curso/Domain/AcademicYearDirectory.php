<?php

namespace App\Modules\Curso\Domain;

/**
 * REQ-CURSO/funcional.md §7.1, `CA-CURSO-045` (1.10): traduce el parámetro
 * `academic_year` (`public_id`) a la vista del curso. `null` si no existe o
 * es de otro centro (`INV-001`): el consumidor responde `404`, igual que
 * para un curso inexistente.
 */
interface AcademicYearDirectory
{
    public function findByPublicId(string $publicId): ?AcademicYearSummary;
}
