# ADR-032 · Fuente única de autorizaciones de recogida de menores

**Estado**: ACEPTADA (2026-08-12 — decisión de alcance para `REQ-FAM-UNIT-005`, módulo todavía sin construir)
**Fecha**: 2026-08-12
**Afecta a**: `REQ-FAM-UNIT`, `REQ-PRL-004`, `REQ-TRAN-005`, `REQ-COMED`, `REQ-EXTRA`, `REQ-ACOG`, `REQ-INF`

## Contexto

Al revisar si la plataforma contempla quién puede recoger a un menor aparecen **dos definiciones separadas** del mismo concepto:

- `REQ-PRL-004` · Control de acceso y salida de menores, dentro del módulo de **Prevención de Riesgos Laborales**, fase 3.
- `REQ-TRAN-005` · Autorizaciones de recogida en la parada, dentro de **Transporte escolar**, fase 2.

Tres problemas.

**Ubicación incorrecta.** La prevención de riesgos laborales regula la seguridad **del personal trabajador**. Quién puede llevarse a un niño del colegio no es un riesgo laboral: es un control de acceso sobre menores. Está ahí por acumulación, no por diseño.

**Duplicación.** Dos listas de personas autorizadas para el mismo alumno, mantenidas por separado, divergen. El día que una familia revoca una autorización y solo se actualiza una de las dos, el sistema entrega un menor a quien no debía y el registro dirá que estaba autorizado.

**Fase tardía.** La recogida ordinaria en la puerta del centro es una operación **diaria** que afecta a todo el alumnado de Infantil y Primaria. Dejarla en fase 3 significa que el piloto opera un curso entero sin ella, mientras que el transporte —que afecta a una quinta parte del alumnado— sí la tendría en fase 2. Es al revés de como debería ser.

## Decisión

### Una sola lista maestra, en la unidad familiar

Las personas autorizadas a recoger a un alumno son **dato maestro de la unidad familiar**, no de un servicio. Se define un requisito nuevo, `REQ-FAM-UNIT-005`, en el módulo de Unidad Familiar, que ya es **MUST de fase 1**.

Todos los servicios consumen esa lista: recogida ordinaria en el centro, transporte, comedor, actividades extraescolares, aula matinal y primer ciclo de Infantil. **Ninguno mantiene lista propia.**

### Los servicios aportan contexto, no una lista paralela

Cada servicio puede añadir restricciones **sobre** la lista maestra, nunca sustituirla:

- Transporte (`REQ-TRAN-005`): en qué parada, y si el alumno tiene autorización para bajar solo.
- Extraescolares y comedor: quién recoge en ese horario concreto, elegido de entre los autorizados.
- Infantil 0-3 (`REQ-INF`): verificación reforzada, sin excepción de bajada sola.

### La restricción judicial se aplica en la fuente

Si un tutor tiene el acceso revocado por resolución judicial (`REQ-FAM-UNIT-002`), queda excluido de la lista maestra y por tanto de **todos** los servicios simultáneamente. No hay que acordarse de revocarlo en cada uno: es la razón principal de que la lista sea única.

### `REQ-PRL-004` se reduce a lo que le corresponde

Mantiene el **proceso operativo** en la puerta —registro de la salida efectiva, alerta a conserjería ante una recogida no coincidente, salidas fuera de horario— y consume la lista maestra. Deja de definir el dato.

## Consecuencias

- `REQ-FAM-UNIT` crece con un requisito nuevo y sube su peso en fase 1.
- El proceso de recogida ordinaria se adelanta de fase 3 a **fase 1**, coherente con que sea una operación diaria de todo el centro.
- `REQ-TRAN-005` se reescribe para consumir la lista maestra en lugar de definir una propia.
- Modelo de datos: la entidad de persona autorizada cuelga de la unidad familiar, no del servicio. Las suscripciones a servicios referencian, no copian.
- El generador de datos sintéticos (`REQ-SEED`) traslada las autorizaciones de la suscripción de transporte a la familia.

## Alternativas descartadas

- **Mantener listas por servicio**: es la situación actual, y su fallo característico —revocar en un sitio y no en otro— tiene consecuencias graves sobre un menor.
- **Módulo propio de control de acceso**: añade un módulo al catálogo para un concepto que es, en esencia, un atributo de la unidad familiar. El proceso operativo sí puede vivir en `REQ-PRL`.
