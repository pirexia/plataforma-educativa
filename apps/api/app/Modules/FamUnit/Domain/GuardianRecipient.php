<?php

namespace App\Modules\FamUnit\Domain;

/**
 * REQ-FAM-UNIT/funcional.md §7.2. Un usuario al que notificar, devuelto por `GuardianRecipients`.
 *
 * `$studentPersonIds` son los alumnos de la consulta por los que este usuario es destinatario (vínculo activo, no
 * restringido para la categoría pedida): un tutor con dos hijos en la consulta es **un** destinatario con dos
 * alumnos. El idioma lo resuelve el consumidor con `UserDirectory` (`REQ-COM`, 1.19).
 */
final class GuardianRecipient
{
    /**
     * @param  list<int>  $studentPersonIds
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $userPublicId,
        public readonly int $personId,
        public readonly array $studentPersonIds,
    ) {}
}
