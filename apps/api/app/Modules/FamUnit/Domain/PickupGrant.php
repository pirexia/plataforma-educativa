<?php

namespace App\Modules\FamUnit\Domain;

/**
 * `ADR-068 §5.5` ampliado en 1.14b-b (`REQ-PRL-004`, `RN-PRL-02`, `RN-PRL-05`, `RN-PRL-06`): por qué y cómo figura **una**
 * persona en la lista de **un** alumno en el instante de la consulta, sin ningún dato personal: las bases (con la
 * referencia de evidencia de cada una) y si tiene fotografía. Es lo que `prl` necesita para registrar una salida
 * (la base con la que figuraba, la referencia y la exigencia de documento si el tutor no tiene fotografía) sin pedir la
 * lista completa, que es una lectura auditada, limitada y con URLs firmadas (`AuthorizedPickupList::entriesFor()`).
 *
 * Como `isAuthorized()`, `AuthorizedPickupList::grantFor()` no se audita: no devuelve nombre, documento, teléfono ni
 * fotografía.
 */
final class PickupGrant
{
    /**
     * @param  list<PickupBasis>  $bases  todas las bases por las que figura, tutores antes que terceros y puntuales (nunca vacía)
     */
    public function __construct(
        public readonly int $personId,
        public readonly array $bases,
        public readonly bool $hasPhoto,
    ) {}

    /** La base con la que se registra la entrega: la primera (un tutor antes que un tercero). */
    public function primaryBasis(): PickupBasis
    {
        return $this->bases[0];
    }
}
