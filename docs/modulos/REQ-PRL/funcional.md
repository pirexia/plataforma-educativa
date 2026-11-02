# REQ-PRL · Puerta y entrega de menores · Funcional

| Campo | Valor |
|-------|-------|
| Código | `REQ-PRL` (en `1.14b-b` solo `REQ-PRL-004`; el resto de `REQ-PRL` es de fase 2 y llegará al mismo módulo) |
| Prioridad | MUST (`REQ-PRL-004` adelantado a fase 1, `ADR-032`) |
| Fase | 1 (`1.14b-b`) |
| Depende de | `fam_unit` (`depends_on ['fam_unit']`, `ADR-068 §5.1`, `RMOD-006`) |
| Esencial | No |
| Estado | **Implementado en `1.14b-b`** (sin pantallas: `1.14b-c`; salida autónoma, alerta de no recogido y avisos automáticos: `1.14b-d`, diferidos) |

> **Origen del contenido.** Traslado **sin cambios de contenido** de lo marcado `prl` en `docs/modulos/REQ-FAM-UNIT/` (especificación del paso `1.14b`, aprobada el 2026-10-29), tal como exige `ADR-068 §5.1`. La numeración de IDs (`RN-PRL-*`, `CA-PRL-*`, `OPEN-*`, secciones `§21.x`/`§8.x`) se conserva tal cual para que las referencias cruzadas existentes sigan siendo válidas. Las referencias a `§N` sin más indican la sección de `REQ-FAM-UNIT/funcional.md`, `datos.md`, `api.md`, `permisos.md` u `operacion.md` del fichero homónimo de ese módulo.

## Alcance

Entra en `1.14b-b`: búsqueda de alumno y lista de puerta (lectura por `AuthorizedPickupList`), salidas verificadas, movimientos fuera de horario, correcciones, alertas por persona no autorizada y su resolución. No entra: pantallas (`1.14b-c`), salida autónoma, alerta de alumno no recogido y avisos automáticos (`1.14b-d`, `OPEN-FAM-UNIT-31` a `-33`).

## Flujos principales

**21.5.5 Entrega en la puerta (`prl`).** (1) El conserje busca al alumno por nombre (o código de unidad familiar; por grupo tras `1.15`). (2) Abre su lista: nombre, relación, base, fotografía (URL firmada), teléfono y documento enmascarado. La consulta queda auditada. (3) Compara a la persona presente con la foto y, si procede, con su documento. (4) Selecciona a la persona y el método de verificación y registra la salida. El servidor **vuelve a comprobar** en la misma transacción que la persona figura en la lista en ese instante; si no, rechaza sin escribir (§21.7). (5) Si quien se presenta no figura: **no entrega**, abre una alerta, sigue el protocolo del centro y llama al tutor principal (teléfono mostrado en la alerta), dejando constancia de la llamada.

**21.5.6 Movimiento fuera de horario (`prl`).** El personal registra la entrada o salida con motivo; si es una salida con persona que recoge, aplica 21.5.5 entero.

## Reglas de negocio

### 21.7 Reglas de negocio: proceso de puerta (`prl`)

| ID | Regla |
|----|-------|
| `RN-PRL-01` | **Solo consume**: `prl` lee la lista por `AuthorizedPickupList` (§21.8); no guarda personas autorizadas ni copias. Guarda **hechos** (salidas, movimientos, alertas) que referencian personas por su id. |
| `RN-PRL-02` | **Recomprobación al escribir** (TOCTOU): registrar una salida con persona que recoge exige `isAuthorized(persona, alumno, ahora)` **dentro de la transacción de escritura**; si es falso ⇒ `422 prl.movement.person_not_authorized` y no se escribe nada, aunque la persona figurara al abrir la lista. Mismo cuerpo para «no figura» y «excluido por revocación» (`OPEN-FAM-UNIT-34`). |
| `RN-PRL-03` | **No hay entrega a quien no figura**: la API no ofrece forma de registrar una salida con una persona fuera de la lista. El cauce para una recogida no prevista es una autorización puntual registrada por quien tenga permiso (`OPEN-FAM-UNIT-35`), tras la cual la salida se registra normalmente. |
| `RN-PRL-04` | **Inmutabilidad**: los movimientos son de solo anexar (`§16.3` regla 6). Un error se corrige con un movimiento de corrección que referencia al corregido y lleva motivo [DERIVADA]; el original nunca se edita ni se borra. |
| `RN-PRL-05` | **Contenido de una salida**: alumno; persona que recoge; base con la que figuraba (`tutor`, `tercero`, `puntual`) y referencia a la autorización o vínculo; método de verificación (`foto`, `documento`, `foto_y_documento`); usuario que entrega (por defecto quien registra; puede indicarse otro usuario del centro); usuario que registra; instante (`TIMESTAMPTZ`, servidor). |
| `RN-PRL-06` | **Tutor sin fotografía** (`RN-FAM-UNIT-84`): la salida exige método `documento` (sin foto no hay `foto` ni `foto_y_documento`); con cualquiera de esos dos ⇒ `422 prl.movement.verification_requires_document`. |
| `RN-PRL-07` | **Fuera de horario**: entrada o salida con `off_schedule = true` exige motivo (vocabulario cerrado [DERIVADA]: `medico`, `familiar`, `actividad`, `retraso`, `otro`, con texto opcional si `otro`) y responsable (usuario). En `1.14b` el carácter «fuera de horario» lo decide el personal (H2). |
| `RN-PRL-08` | **Alerta de persona no autorizada**: se abre con el alumno, una descripción de quien se presenta (nombre declarado y, si lo exhibe, documento; texto acotado), el usuario y el instante. Muestra el **protocolo del centro** (`OPEN-FAM-UNIT-40`) y los teléfonos del tutor principal y del contacto de emergencia (de `Core`, por `fam_unit`). Se cierra con una resolución de vocabulario cerrado (`no_entregado`, `recogido_por_autorizado`, `autorizacion_puntual_registrada`, `otro`) y, si se llamó al principal, el instante de la llamada. **No envía nada automáticamente** hasta `1.19` (`OPEN-FAM-UNIT-33`). |
| `RN-PRL-09` | **Idempotencia** (`INV-011`) [DERIVADA: operación crítica sobre un menor; el doble toque en una tableta es el caso real]: `POST` de movimientos y alertas admite `Idempotency-Key`; la misma clave con el mismo cuerpo devuelve el resultado original sin duplicar. |
| `RN-PRL-10` | **Auditoría y consulta**: abrir la lista en la puerta pasa por la lectura auditada de `fam_unit` (`RN-FAM-UNIT-92`, finalidad `puerta`) y su límite (`RN-FAM-UNIT-93`). Movimientos y alertas se auditan al crearse y al resolverse (`INV-003`). |
| `RN-PRL-11` | **Sin lista no hay entrega**: con `fam_unit` no contratado, la interfaz responde lista vacía e `isAuthorized = false`, y ninguna salida con persona que recoge puede registrarse (fallo en cerrado). `prl` no se puede contratar sin `fam_unit` (`depends_on`, `RMOD-006`). |

## Criterios de aceptación

**Proceso de puerta (`prl`, `1.14b-b`)**

- **CA-PRL-001** — *Dado* un conserje con `entrega_menor.crear` y una persona que figura, *cuando* registra la salida con método `foto`, *entonces* `201` con alumno, persona, base, referencia, método, quién entrega, quién registra e instante.
- **CA-PRL-002** — *Dado* una persona que no figura, *cuando* se intenta registrar la salida, *entonces* `422 prl.movement.person_not_authorized`, no se escribe nada, y el cuerpo es idéntico si la persona está excluida por revocación (`RN-PRL-02`, `OPEN-FAM-UNIT-34`).
- **CA-PRL-003** — *Dado* que la lista se abrió con P vigente y P se revoca antes de registrar, *cuando* se registra la salida con P, *entonces* `422` (recomprobación en la transacción, test de concurrencia).
- **CA-PRL-004** — *Dado* un movimiento, *cuando* se intenta modificar o borrar por API, modelo o SQL con el rol de aplicación, *entonces* se rechaza; *y* una corrección es un movimiento nuevo que referencia al anterior (`RN-PRL-04`).
- **CA-PRL-005** — *Dado* dos `POST` con la misma `Idempotency-Key` y el mismo cuerpo, *entonces* una sola fila y la misma respuesta (`RN-PRL-09`).
- **CA-PRL-006** — *Dado* un tutor sin fotografía, *cuando* se registra con método `foto` o `foto_y_documento`, *entonces* `422 verification_requires_document` (`RN-PRL-06`).
- **CA-PRL-007** — *Dado* un movimiento fuera de horario sin motivo, *entonces* `422`; *y* con motivo se registra con responsable (`RN-PRL-07`).
- **CA-PRL-008** — *Dado* una alerta abierta, *cuando* se resuelve con `no_entregado` y la hora de la llamada al principal, *entonces* queda cerrada, auditada y no reabrible por `PATCH` (`409`).
- **CA-PRL-009** — *Dado* un usuario sin `entrega_menor.leer`/`.crear` (también un tutor con ámbito `unidad_familiar`), *entonces* `403` en lista de puerta y movimientos; *y* con `deny` en uno de sus roles, `403` (`RPERM-007`).
- **CA-PRL-010** — *Dado* la consulta de la lista en la puerta, *entonces* pasa por la lectura auditada de `fam_unit` con finalidad `puerta` (una fila `read`) y su límite.
- **CA-PRL-011** — *Dado* dos centros, *cuando* se ejercitan todos los *endpoints* de `prl` desde A, *entonces* nada de B (`INV-001`); *y* con `prl` descontratado, `403 module-disabled`; *y* `platform:sync-registry` registra `depends_on: ['fam_unit']`.
- **CA-PRL-012** *(E2E, `1.14b-c`)* — *Dado* un conserje en navegador real, *cuando* busca a un alumno, abre su lista, verifica por la foto y registra la salida, y después intenta registrar a alguien que no figura y abre la alerta, *entonces* cada paso se refleja, la foto tiene texto alternativo y los estados no dependen solo del color (WCAG 2.2 AA).
- **CA-PRL-013** — *Dado* las claves de `prl` en `es`, *entonces* existen en `en`, `de` y `fr` (`AR-12`), y ninguna regla compara códigos de rol ni importa internos de `fam_unit` (`AR-01`, `AR-02`, `AR-08`).

## Interacción con otros módulos

`prl` consume `FamUnit\Domain\AuthorizedPickupList` (`funcional.md §21.8` de `REQ-FAM-UNIT`) y no guarda personas autorizadas ni copias (`RN-PRL-01`). Sin eventos (`ADR-048`).
