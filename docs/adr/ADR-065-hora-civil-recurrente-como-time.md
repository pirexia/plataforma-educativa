# ADR-065 · Hora civil recurrente como columna `time` sin zona

**Estado**: **ACEPTADA** (2026-10-17; aprobada por el usuario con la especificación de `1.12`, `OPEN-ACAD-40` opción A).
**Fecha**: 2026-10-17
**Paso**: `1.12 · Horarios y asignación docente` (`REQ-ACAD-002`, `docs/modulos/REQ-ACAD/funcional.md §18`).
**Excepción acotada a `ADR-029`** («`TIMESTAMPTZ` siempre») y a **`ADR-038 §3.2`** («nunca una hora local sin zona»). No sustituye ninguno de los dos.

## Contexto

Una franja «lunes 08:30-09:25» se repite cada semana del curso: no es un instante, es una hora de reloj del centro. `ADR-029` regula **marcas de tiempo**; no prevé horas sin fecha. Guardarla como `TIMESTAMPTZ` obliga a inventar una fecha ficticia y a convertir a UTC algo que debe seguir siendo «08:30» antes y después del cambio de hora: con UTC fijo, la franja se desplaza una hora cada octubre y marzo. El precedente es `date` para fechas civiles (`birth_date`, `ADR-038 §3.2`).

## Opciones consideradas

- **A** · `time` (sin zona), interpretada en la zona `timezone` del centro.
- **B** · `smallint` de minutos desde medianoche.
- **C** · `TIMESTAMPTZ` con fecha ficticia (descartada de entrada por lo dicho arriba).

## Decisión

1. Las **horas civiles recurrentes o de calendario local** se guardan como `time` (PostgreSQL `time without time zone`, precisión de minuto) y se interpretan siempre en la zona del centro (`tenant_settings.timezone`). Nunca se convierten a UTC.
2. **Alcance cerrado**: `starts_at`/`ends_at` de franjas de marcos horarios (`time_slots`), las horas que de ellas copien o deriven las sesiones, y la hora de inicio y fin de las reservas (que van con su `date` civil, `1.12-b`).
3. **Fuera de alcance**: todo **instante** (auditoría, `created_at`, envíos, plazos, inicios de sesión, fichajes, registros de asistencia) sigue en `TIMESTAMPTZ`. Un módulo futuro (asistencia, agenda) que quiera usar `time` cita este ADR y justifica que su dato es hora civil, no instante; si no encaja, ADR nuevo.
4. **API**: estas horas viajan como `HH:MM` (24 h) sin zona ni sufijo `Z`, como ya fija `REQ-ACAD/api.md`. Es la única excepción a «nunca una hora local sin zona» de `ADR-038 §3.2`.
5. Intervalos semiabiertos `[inicio, fin)` y `fin > inicio` por `CHECK` en el motor; no se admiten franjas que crucen medianoche.

## Motivo

- **A frente a B**: mismo coste de almacenamiento práctico, pero `time` es legible en `psql` y en volcados, valida el rango (00:00-23:59) sin `CHECK` propio, y se compara y ordena igual. B obliga a convertir en cada lectura y en cada informe.
- **Reversible**: pasar de `time` a minutos o a otra representación es una migración expand/contract local a tres o cuatro tablas.
- **Sin impacto en invariantes**: no toca tenant, auditoría ni borrado lógico.

## Consecuencias

- **`AR-04` ya la admite, sin cambio**: `adr029ColumnViolations()` (`apps/api/tests/Feature/Architecture/SchemaConventionsTest.php`) solo prohíbe `timestamp% without time zone`, y excluye `time without time zone` a propósito (`ADR-056 §3.4`, fila «Prohibir `time without time zone`»); el control negativo lo fija, con una columna `hora time without time zone` en la tabla de violaciones deliberadas que no debe aparecer en el resultado. No hace falta ampliar ninguna excepción.
- Lo que `AR-04` **no** comprueba es el alcance del punto 2: que nadie use `time` para un instante. Eso queda a `db-reviewer`. Si el uso se extiende más allá de este ADR sin justificación, se plantea una regla que limite `time` a una lista de columnas.
- Un centro que cambie de `timezone` cambia el significado absoluto de sus horarios, aunque no su lectura («08:30» sigue siendo 08:30 local). Es lo correcto para una hora civil.
- Combinar fecha + hora civil en un instante (por ejemplo, para un aviso) se hace en servidor con la zona del centro, en un único punto.

## Alternativas descartadas y por qué

- **B · minutos desde medianoche**: funciona, pero es menos legible y no aporta nada que `time` no dé.
- **C · `TIMESTAMPTZ` con fecha ficticia**: falsea el dato y rompe con los cambios de hora.
- **`timetz`**: la propia documentación de PostgreSQL desaconseja su uso; una zona fija sin fecha no resuelve el horario de verano.
