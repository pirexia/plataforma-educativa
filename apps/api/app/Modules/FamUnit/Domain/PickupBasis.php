<?php

namespace App\Modules\FamUnit\Domain;

/**
 * `ADR-068 §5.5`, `funcional.md §21.8`: una de las bases por las que una persona figura en la lista de un alumno. Una
 * persona aparece **una sola vez** en la lista, con todas sus bases (`RN-FAM-UNIT-79`).
 *
 * - `tutor`: vínculo activo; `reference` es el `public_id` del vínculo y `relationship` la del vínculo.
 * - `tercero`: autorización permanente vigente; `reference` es el `public_id` de la autorización.
 * - `puntual`: autorización de una fecha; `reference` es el `public_id` de la autorización.
 *
 * `validFrom` y `validUntil` (fechas civiles del centro, `Y-m-d`) solo en `tercero` y `puntual`; `validUntil` nulo =
 * sin caducidad. Nunca se incluye el motivo de una puntual (texto libre).
 *
 * `referenceId` (1.14b-b, `REQ-PRL-004`): el id interno del vínculo o de la autorización. Es la clave con la que un consumidor
 * guarda la **evidencia** de la base con la que se entregó a un menor (`student_movements.guardian_student_link_id` o
 * `.pickup_authorization_id`, `datos.md §8.4` de `REQ-PRL`): una clave foránea de evidencia, nunca una lectura de las tablas de
 * `fam_unit` (`INV-007`, `ADR-068 §5.1`). Es un entero interno, como `PickupEntry::$personId`.
 */
final class PickupBasis
{
    public const TUTOR = 'tutor';

    public const THIRD_PARTY = 'tercero';

    public const PUNCTUAL = 'puntual';

    public function __construct(
        public readonly string $basis,
        public readonly string $reference,
        public readonly string $relationship,
        public readonly ?string $validFrom = null,
        public readonly ?string $validUntil = null,
        public readonly ?int $referenceId = null,
    ) {}
}
