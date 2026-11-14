<?php

namespace App\Modules\Curso\Domain;

/**
 * REQ-CURSO/funcional.md `RN-CURSO-61`, `-73` (1.12b): una fila creada por una
 * copia. `entityType` es el alias del *morph map* de la tabla copiada (el
 * mismo vocabulario que `audit_logs.auditable_type`); `sourceId` y `targetId`,
 * los identificadores internos de la fila de origen y de la creada. `curso` los
 * guarda sin FK (`datos.md §1.7`) y se los devuelve al participante al revertir;
 * nunca lee ni escribe las tablas a las que apuntan (`INV-007`).
 */
final readonly class AcademicYearRolloverItem
{
    public function __construct(
        public string $entityType,
        public int $sourceId,
        public int $targetId,
    ) {}
}
