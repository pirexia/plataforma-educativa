<?php

namespace App\Modules\FamUnit\Domain;

/**
 * REQ-FAM-UNIT/funcional.md §7.2 (1.14-a, `REQ-FAM-UNIT-002`): los usuarios a los que notificar eventos relevantes de
 * un conjunto de alumnos. Consumidor previsto: `REQ-COM` (`1.19`). Mismo contrato que `FamilyMembership`: una
 * consulta, sin memoización, solo vínculos activos y vivos, vacío con el módulo descontratado.
 */
interface GuardianRecipients
{
    /**
     * Usuarios **vivos** con vínculo activo con alguno de los alumnos, sin los vínculos que restringen la categoría
     * pedida (si se pide). Un usuario aparece una sola vez, con los alumnos por los que es destinatario. Orden por
     * id de usuario.
     *
     * @param  list<int>  $studentPersonIds  ids de persona de los alumnos
     * @return list<GuardianRecipient>
     */
    public function recipientsFor(array $studentPersonIds, ?string $category = null): array;
}
