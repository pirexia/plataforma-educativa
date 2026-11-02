<?php

namespace App\Modules\FamUnit\Domain;

/**
 * REQ-FAM-UNIT/funcional.md §21.8, `ADR-068 §5.5` (1.14b-a, `REQ-FAM-UNIT-005`, `ADR-032`): la **lista maestra** de quién
 * puede recoger a un menor, calculada por `fam_unit` en cada consulta. Es la única puerta de los demás módulos hacia ella
 * (`INV-007`): `prl` (primer consumidor) y, en fases posteriores, transporte, comedor, extraescolares, acogida e informes.
 *
 * **Contrato** (vinculante para todos los consumidores; se copia en su especificación):
 *
 * 1. Personas y alumnos por **id interno de persona** (`people.id`); el centro es el del contexto, nunca un parámetro.
 * 2. **Consulta en el momento de entregar**, no al planificar. Una restricción del servicio («recoge en la parada 4 solo la
 *    abuela») se guarda en el consumidor y **se interseca** con la lista en el momento de la entrega: si la persona deja
 *    de figurar, la restricción no la mantiene (`ADR-032`: restringir, nunca ampliar). Por eso **ninguna consulta recibe
 *    el instante**: siempre es el del servidor (`OPEN-068-08`), de modo que nadie «planifica» consultando una fecha futura.
 * 3. **Prohibido** guardar la lista, una copia de una autorización o su estado como base para entregar, y esperar un
 *    evento para retirar a alguien. Sin caché entre peticiones ni eventos (`ADR-048`): retirar una autorización, revocar o
 *    levantar una revocación surten efecto en la petición siguiente.
 * 4. **Fallo en cerrado**: alumno o persona desconocidos, borrados, de otro centro, o módulo `fam_unit` no contratado ⇒ lista
 *    vacía y `isAuthorized = false`.
 * 5. Sin efectos salvo la auditoría de lectura de `entriesFor()` (`ADR-068 §5.4`). No emite eventos.
 * 6. Ningún módulo (tampoco `REQ-AUT`) ofrece plantillas, tipos ni respuestas de «autorización de recogida» (`H8`).
 *
 * La exclusión por revocación (`RN-FAM-UNIT-80`) va en la misma consulta que compone la lista, con el predicado de
 * `FamilyMembership::isAccessRevoked()`: quien tiene el vínculo con ese alumno revocado no figura por **ninguna** base. La
 * puerta responde igual ante «no figura» y «excluido» (`OPEN-FAM-UNIT-34`).
 */
interface AuthorizedPickupList
{
    /** Finalidad de una lectura de gestión (ficha de familia, secretaría). */
    public const PURPOSE_MANAGEMENT = 'gestion';

    /** Finalidad de una consulta en la puerta (conserjería). */
    public const PURPOSE_DOOR = 'puerta';

    /**
     * ¿Figura la persona en la lista del alumno en este instante? Una consulta. **Sin auditoría de lectura** (no devuelve
     * datos personales).
     */
    public function isAuthorized(int $personId, int $studentPersonId): bool;

    /**
     * Cómo figura la persona en la lista del alumno **ahora** —sus bases con la referencia de evidencia y si tiene
     * fotografía—, o `null` si no figura (desconocida, borrada, de otro centro, excluida por revocación, `fam_unit` no
     * contratado): el mismo predicado que `isAuthorized()` (`isAuthorized = grantFor !== null`). Una consulta; **sin
     * auditoría de lectura** (no devuelve datos personales). Añadida en `1.14b-b` para que `prl` registre la base de una
     * salida (`RN-PRL-05`) y exija el documento al tutor sin fotografía (`RN-PRL-06`) sin leer tablas de `fam_unit`.
     */
    public function grantFor(int $personId, int $studentPersonId): ?PickupGrant;

    /**
     * Las entradas de la lista del alumno, tutores primero y después terceros y puntuales, por nombre. **Auditada**
     * (`read`, `ADR-068 §5.4`) y con límite (`RN-FAM-UNIT-93`; `429`): la fila de auditoría se escribe **antes** de
     * devolver nada, y si falla no sale ninguna URL firmada. La URL de la fotografía la firma `fam_unit`: el consumidor no
     * ve nunca la clave del objeto ni el número de documento completo.
     *
     * @param  self::PURPOSE_*  $purpose
     * @return list<PickupEntry>
     */
    public function entriesFor(int $studentPersonId, string $purpose): array;

    /**
     * De las personas dadas, las que figuran en la lista del alumno, para los consumidores que **restringen** sobre la
     * lista (elegir quién recoge en una parada). Una consulta. Sin auditoría de lectura.
     *
     * @param  list<int>  $personIds
     * @return list<int>
     */
    public function authorizedAmong(int $studentPersonId, array $personIds): array;

    /**
     * Alumnos cuyo nombre empieza por `$term`, para el buscador de conserjería (`GET /door/students`). `fam_unit` acota el
     * universo (`OPEN-068-06`: toda persona con pertenencia viva a una unidad familiar **o** con algún vínculo vivo como
     * alumno, activo o revocado; con `1.15`, alumnado matriculado) y busca con `PersonDirectory::searchByNameAmong()`. Sin
     * auditoría de lectura (no devuelve fotografía ni documento).
     *
     * @return list<StudentMatch>
     */
    public function studentsMatching(string $term, int $limit): array;
}
