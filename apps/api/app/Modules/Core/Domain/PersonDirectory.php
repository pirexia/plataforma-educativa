<?php

namespace App\Modules\Core\Domain;

/**
 * REQ-FAM-UNIT/funcional.md §1.5, §7.4 y `OPEN-FAM-UNIT-03` (ampliación aprobada de `Core\Domain`, 2026-10-26,
 * `1.14-a`; precedente: `UserDirectory::activeIdsAmong()` y `findActiveByPublicId()`): lectura de personas y
 * resolución usuario ⇄ persona, para que los módulos que cuelgan facetas de `people` (`ADR-034 §1`) no lean
 * `people` ni `users` directamente (`INV-007`).
 *
 * Todas las consultas son del centro en curso (`TenantScope` + RLS: lo de otro centro no existe, `INV-001`), una
 * sola consulta por llamada y **sin memoización entre llamadas** (el estado cuenta en el momento de la llamada,
 * `RN-FAM-UNIT-34`). No devuelven nunca el modelo Eloquent.
 *
 * No incluye la fecha de nacimiento que preveía `OPEN-FAM-UNIT-03` para las reglas de edad de `OPEN-FAM-UNIT-18`:
 * esa regla no existe todavía y `ADR-048` pide no construir sin consumidor (`funcional.md §15`, D-4).
 */
interface PersonDirectory
{
    /**
     * La persona **viva** del centro con ese `public_id`; `null` si no existe, está borrada o es de otro centro.
     */
    public function findByPublicId(string $publicId): ?PersonRef;

    /**
     * Referencias por id interno, vivas o borradas (una borrada con `removed = true` y nombre vacío). Los ids que
     * no existen o son de otro centro no aparecen.
     *
     * @param  list<int>  $ids
     * @return array<int, PersonRef>
     */
    public function refsByIds(array $ids): array;

    /**
     * El id de la persona del usuario `$userId`; `null` si el usuario no existe, está borrado o es de otro centro.
     */
    public function personIdForUser(int $userId): ?int;

    /**
     * El usuario **vivo** del centro con ese `public_id`, sea cual sea su estado de cuenta (`pendiente`, `activo` o
     * `inactivo`: lo decide el llamador); `null` si no existe, está borrado o es de otro centro. No se comprueba
     * ningún rol.
     */
    public function findUserByPublicId(string $userPublicId): ?UserPersonRef;

    /**
     * El usuario de cada persona, vivo o borrado (uno borrado con `removed = true` y nombre vacío). Una persona sin
     * usuario no aparece; la clave es el id de persona (`users.person_id` es único entre los vivos, `ADR-034 §1`).
     *
     * @param  list<int>  $personIds
     * @return array<int, UserPersonRef>
     */
    public function usersByPersonIds(array $personIds): array;
}
