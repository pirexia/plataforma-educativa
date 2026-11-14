<?php

namespace App\Modules\Curso\Domain;

/**
 * REQ-CURSO/funcional.md §7.1, `CA-CURSO-045` (1.10): traduce el parámetro
 * `academic_year` (`public_id`) a la vista del curso. `null` si no existe o
 * es de otro centro (`INV-001`): el consumidor responde `404`, igual que
 * para un curso inexistente.
 *
 * Desde 1.11 (`OPEN-ACAD-12`, `CA-ACAD-042`) también por identificador
 * interno: un módulo con datos por curso guarda `academic_year_id` y debe
 * resolver el estado del curso de una fila (detalle, `AcademicYearReadAccess`,
 * `RN-ACAD-08`) sin consultar `academic_years` (`AR-01`). `AcademicYearSummary::
 * $id` es el identificador interno y no sale de la API (`ADR-029`).
 */
interface AcademicYearDirectory
{
    public function findByPublicId(string $publicId): ?AcademicYearSummary;

    /**
     * `null` si no existe o es de otro centro (`INV-001`). Memoizado por
     * petición: la base de datos se consulta una vez por identificador.
     */
    public function findById(int $id): ?AcademicYearSummary;

    /**
     * Varios a la vez (listados): una sola consulta para los identificadores
     * aún no resueltos en la petición. Los inexistentes o de otro centro no
     * aparecen en el resultado.
     *
     * @param  list<int>  $ids
     * @return array<int, AcademicYearSummary> identificador interno => resumen
     */
    public function findManyById(array $ids): array;
}
