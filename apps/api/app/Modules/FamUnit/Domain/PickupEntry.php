<?php

namespace App\Modules\FamUnit\Domain;

/**
 * `ADR-068 §5.5`, `funcional.md §21.8`: una persona de la lista de un alumno, tal como la devuelve
 * `AuthorizedPickupList::entriesFor()`. Código técnico sin traducir (`ADR-038 §3.2`).
 *
 * - `personId`: id interno de la persona (la clave con la que el consumidor guarda sus hechos).
 * - `bases`: todas las bases por las que figura (`PickupBasis`); tutores antes que terceros y puntuales.
 * - `hasPhoto`/`photoUrl`/`photoUrlExpiresAt`: `photoUrl` es una URL **firmada de caducidad corta**, generada en esta
 *   respuesta y nunca cacheable; `null` si no hay fotografía o no se pudo firmar (el consumidor exige entonces el
 *   documento, `OPEN-FAM-UNIT-47`). El consumidor no ve nunca la clave del objeto.
 * - `contactPhone`: el teléfono de la persona en `Core`, o `null`.
 * - `documentType`/`documentMasked`: tipo y número **enmascarado** (últimos 4 caracteres visibles); nunca completo
 *   (`RN-FAM-UNIT-90`). `null` si la persona no tiene documento.
 */
final class PickupEntry
{
    /**
     * @param  list<PickupBasis>  $bases
     */
    public function __construct(
        public readonly int $personId,
        public readonly string $personPublicId,
        public readonly string $displayName,
        public readonly array $bases,
        public readonly bool $hasPhoto,
        public readonly ?string $photoUrl,
        public readonly ?string $photoUrlExpiresAt,
        public readonly ?string $contactPhone,
        public readonly ?string $documentType,
        public readonly ?string $documentMasked,
    ) {}

    /** La relación de la primera base (la de un tutor antes que la de un tercero). */
    public function relationship(): string
    {
        return $this->bases[0]->relationship ?? '';
    }

    /** La caducidad de la primera base con fecha de fin, o `null`. */
    public function validUntil(): ?string
    {
        foreach ($this->bases as $basis) {
            if ($basis->validUntil !== null) {
                return $basis->validUntil;
            }
        }

        return null;
    }
}
