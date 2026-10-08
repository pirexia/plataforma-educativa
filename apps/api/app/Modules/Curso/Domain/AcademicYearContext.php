<?php

namespace App\Modules\Curso\Domain;

/**
 * REQ-CURSO/funcional.md §7.1, RN-CURSO-24 (1.10): el curso activo del
 * centro en curso (o ninguno). La pieza que `ADR-034 §4` encargó a 1.10.
 *
 * Se resuelve una vez por petición y se memoiza en ella; **sin caché entre
 * peticiones** (una caché obligaría a invalidarla en cada transición, y su
 * modo de fallo sería escribir en un curso ya cerrado). Nunca depende del
 * estado de la sesión del usuario (`RARQ-DEP-002`).
 */
interface AcademicYearContext
{
    public function active(): ?AcademicYearSummary;
}
