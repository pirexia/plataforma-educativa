<?php

namespace App\Modules\FamUnit\Domain;

/**
 * REQ-FAM-UNIT/funcional.md §7.3 (1.14-b, `INV-014`, `RN-FAM-UNIT-46`, `ADR-066 §5.3`): la única puerta de los demás
 * módulos para saber si la imagen de unos alumnos se puede publicar o servir. `fam_unit` es la fuente única del
 * consentimiento de imagen (`ADR-066 §5.1`): ningún consumidor guarda estados, marcas ni copias.
 *
 * Contrato:
 * - **Entrada**: el nivel es el enumerado `ImageConsentLevel`; los alumnos, ids internos de persona (`people.id`, igual
 *   que `FamilyMembership`). El centro es el del contexto de tenant, nunca un parámetro.
 * - **Lista vacía ⇒ excepción** (`InvalidArgumentException`, error de programación): jamás «publicable».
 * - **Denegación por defecto**: «no publicable», con el alumno entre los que bloquean, si no tiene ninguna decisión que
 *   cuente, si no tiene a nadie que decida, si no existe o es de otro centro, o si el módulo `fam_unit` no está contratado
 *   (`funcional.md §9`).
 * - **Una consulta por llamada**, sin N+1, sea cual sea el número de alumnos. **Sin memoización entre llamadas ni entre
 *   peticiones** (`RN-FAM-UNIT-34`, `ADR-044 §4.7`): una revocación del consentimiento surte efecto en la llamada siguiente.
 * - **Sin efectos**: no escribe, no audita lecturas (no es categoría especial) y no emite eventos.
 * - No recibe edad, curso ni tipo de publicación: una regla jurídica futura cambia el cálculo **dentro** de `fam_unit`.
 *
 * Reglas para el consumidor (`funcional.md §7.3`): consultar **al publicar y al servir** (un evento no basta), fallar en
 * cerrado, publicar una foto de grupo solo si todos los alumnos identificables lo son, y no guardar nunca el estado.
 */
interface ImageConsentPolicy
{
    /**
     * `true` solo si **todos** los alumnos tienen estado `autorizo` en ese nivel.
     *
     * @param  list<int>  $studentPersonIds  ids de persona de los alumnos; no vacía
     *
     * @throws \InvalidArgumentException si la lista está vacía
     */
    public function isPublishable(ImageConsentLevel $level, array $studentPersonIds): bool;

    /**
     * Ids de persona de los alumnos que **no** están en `autorizo` en ese nivel (sin repetidos, ascendentes). Vacía si
     * todos lo están.
     *
     * @param  list<int>  $studentPersonIds  ids de persona de los alumnos; no vacía
     * @return list<int>
     *
     * @throws \InvalidArgumentException si la lista está vacía
     */
    public function blockingAmong(ImageConsentLevel $level, array $studentPersonIds): array;
}
