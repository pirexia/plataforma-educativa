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
 * La fecha de nacimiento que preveía `OPEN-FAM-UNIT-03` no se incluyó en `1.14-a` (sin consumidor, `ADR-048`, `funcional.md
 * §15`, D-4); la lee `birthDatesByIds()` desde `1.15-a`, cuando la ficha de alumno la consume (`REQ-ALUM` `OPEN-ALUM-19` = A).
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

    /**
     * ADR-068 §5.2 (`L4`, `OPEN-068-02`): personas **vivas** del centro cuyo nombre o apellidos empiezan por `$term`
     * (también el nombre visible, «Nombre Apellido»), sin distinguir mayúsculas y con los comodines del texto escapados,
     * **acotadas** a `$personIds`, en una consulta y ordenadas por nombre visible. Sin plegado de acentos (no hay
     * `unaccent` en la base). `$limit` máximo de resultados. Lista vacía o término vacío ⇒ vacío.
     *
     * @param  list<int>  $personIds
     * @return list<PersonRef>
     */
    public function searchByNameAmong(string $term, array $personIds, int $limit): array;

    /**
     * ADR-068 §5.2 (`L5`, `OPEN-068-05`): la persona **viva** del centro con ese documento; `null` si no hay, el tipo
     * no es del catálogo o el par no es válido. El número se compara en su forma canónica (`RN-CORE-92`).
     */
    public function findByDocument(string $documentType, string $documentNumber): ?PersonRef;

    /**
     * ADR-068 §5.2 (`L5`, `OPEN-068-05`): teléfono y documento (completo) de esas personas vivas, por id interno. Las
     * personas que no existen, están borradas o son de otro centro no aparecen. Una consulta.
     *
     * @param  list<int>  $personIds
     * @return array<int, PersonContact>
     */
    public function contactDetailsByIds(array $personIds): array;

    /**
     * `REQ-PRL/funcional.md RN-PRL-14` (`1.14b-d1`, `OPEN-PRL-02` A, ampliación aditiva aprobada por el usuario el 2026-11-04): las
     * personas **vivas** del centro con esos `public_id`, dejando sus filas bloqueadas con `FOR SHARE` hasta el final de la
     * transacción en curso, en orden de id ascendente y en **una** consulta. Un escritor concurrente (borrado lógico, cambio de
     * la persona) espera a que la transacción del llamador termine; uno confirmado antes se ve. Las personas que no existen,
     * están borradas o son de otro centro no aparecen. Es el único método de esta interfaz con un efecto (el bloqueo).
     *
     * @param  list<string>  $publicIds
     * @return array<string, PersonRef> por `public_id`
     *
     * @throws \LogicException si no hay una transacción abierta: un bloqueo sin transacción no protege nada (falla en cerrado)
     */
    public function lockLiveByPublicIds(array $publicIds): array;

    /**
     * REQ-ALUM/funcional.md §7.1 (`OPEN-ALUM-19` = A, ampliación aditiva, 1.15-a): la fecha de nacimiento de esas personas
     * **vivas**, por id interno, en una consulta. Las personas que no existen, están borradas o son de otro centro no aparecen;
     * una viva sin fecha informada aparece con `birthDate = null`.
     *
     * @param  list<int>  $personIds
     * @return array<int, PersonBirth>
     */
    public function birthDatesByIds(array $personIds): array;
}
