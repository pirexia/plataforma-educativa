<?php

namespace App\Modules\Acad\Domain;

/**
 * REQ-ALUM/funcional.md §4.2, RN-ALUM-20, RN-ALUM-24 (1.15-b T2a, primer consumidor: `ALUM`): resuelve un curso de
 * etapa a su resumen. Solo filas vivas del centro actual (INV-001): un identificador de otro centro o de un curso
 * borrado es indistinguible de uno inexistente (`null`). Misma forma que `GroupDirectory`.
 */
interface CourseDirectory
{
    public function findByPublicId(string $publicId): ?CourseSummary;

    public function findById(int $id): ?CourseSummary;
}
