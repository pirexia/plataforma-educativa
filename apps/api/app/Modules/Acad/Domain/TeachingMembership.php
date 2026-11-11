<?php

namespace App\Modules\Acad\Domain;

/**
 * REQ-ACAD/funcional.md §18.7, `RN-ACAD-98` (`ADR-063 §5.3`): pertenencia
 * docente de un usuario. Solo filas vivas del centro actual; memoizada por
 * petición; sin bloqueos. (`classPairsFor` sigue «escrita, no construida».)
 */
interface TeachingMembership
{
    /**
     * `grupo(u, curso)`: ids internos de los grupos de sus tutorías vivas ∪ los
     * de sus asignaciones vivas (una asignación a un subgrupo se proyecta sobre
     * su grupo), listos para un `whereIn`.
     *
     * @return list<int>
     */
    public function groupIdsFor(int $userId, int $academicYearId): array;
}
