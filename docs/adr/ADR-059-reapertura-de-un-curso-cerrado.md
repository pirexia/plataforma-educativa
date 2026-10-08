# ADR-059 · Reapertura de un curso académico cerrado

**Estado**: **PROPUESTA** (2026-10-08). Pendiente de decisión del usuario sobre `OPEN-059-01` a `-06` (`§10`). Mientras no se acepte, rige `OPEN-CURSO-08` opción **A** (no hay reapertura), aprobada para `1.10`.
**Fecha**: 2026-10-08
**Paso**: decisión previa a `1.11` de `PLAN-IMPLEMENTACION.md`. Resuelve, si se acepta, `OPEN-CURSO-08` de `docs/modulos/REQ-CURSO/funcional.md §15`.
**Se apoya en**: `INV-002`, `INV-003`, `INV-007`, `INV-012`; `ADR-034 §4` (un activo por centro, índice único parcial); `ADR-035 §8`; `ADR-039 §4.5`, `§5.3`; `ADR-044`; `ADR-046 §6`; `ADR-048 §4.9`; `ADR-057 §5.2`, `§5.4`, `§5.7`; `ADR-058`.
**No sustituye** ningún ADR. **No cambia** ninguna regla de `ADR-057` ni de `ADR-058`: aplica la previsión de `ADR-057 §5.7` («si se decide la reapertura, es una transición de estado de `academic_years` […] y no necesita excepción»).
**Afecta a** (si se acepta): `REQ-CURSO-001`, `REQ-CURSO-005`; `RN-CURSO-10`, `-12`, `-13`; `CA-CURSO-023`, `-084`; `permisos.md §1`, `§2`, `§4`; y como restricción, a los pasos diferidos `1.12b` (rollover) y `1.17b` (promoción y paquete de cierre).

---

## 1 · Contexto

`REQ-CURSO-001` define cuatro estados y **ningún requisito habla de reabrir** (`funcional.md §1.2`). La especificación aprobada de `1.10` dejó la reapertura fuera (`OPEN-CURSO-08`, opción A) y pidió decidirla **antes de `1.11`**, porque a partir de `1.11` existen datos por curso y un cierre por error congela un curso entero: el disparador de `ADR-057` rechaza toda escritura de cualquier módulo sobre ese curso (`RN-CURSO-20`), y la única vía que hoy lo salta —escribir como propietario de la tabla— está prohibida desde un proceso de aplicación (`ADR-057 §5.7` punto 6, `AR-14`). `operacion.md` (fila «Un curso se cerró por error») y `SYSADMIN.md` dicen literalmente que hoy **no hay procedimiento** y que no se reabre con SQL a mano.

Es decir: sin decisión, el primer cierre por error en producción se resolverá con un `UPDATE academic_years SET status = 'activo'` a mano, sin auditoría (`INV-003`), que es exactamente lo que el proyecto prohíbe. Esa es la razón de decidir ahora, no la elegancia.

### 1.1 Hechos verificados contra el código (`develop`, `45780cc`)

| Premisa | Dato |
|---|---|
| Quién escribe `status` | Solo `App\Modules\Curso\Application\AcademicYearTransitions` (`RN-CURSO-10`). `assertValidTransition()` admite exactamente `planificacion → activo` y `activo → cerrado`; `cerrado → activo` responde hoy `409 curso.conflict.invalid_transition` (`CA-CURSO-023`) |
| Serialización | La transición abre transacción y toma `lockForUpdate()` sobre la fila del curso antes de nada; traduce la violación de `academic_years_tenant_status_unique` a `409 active_exists` |
| Un activo por centro | Índice único parcial `academic_years_tenant_status_unique ON (tenant_id, status) WHERE status IN ('activo','planificacion') AND deleted_at IS NULL` (migración `2026_08_18_100100`). **Consecuencia directa**: un curso cerrado solo puede volver a `activo` si no hay otro activo |
| Disparador | `app.assert_academic_year_writable()` (migración `2026_10_07_100200`) bloquea si el curso de la fila está en `cerrado`/`archivado`, con `FOR SHARE` sobre la fila de `academic_years`. **`academic_years` no tiene `academic_year_id`** y por tanto no lleva el disparador: cambiar su `status` no lo atraviesa |
| Cascadas que saltan el disparador (#383, #371) | Medido en #383: las acciones referenciales `ON DELETE/UPDATE CASCADE/SET NULL/SET DEFAULT` se ejecutan como propietario de la tabla hija y quedan exentas. **`AR-13` ya prohíbe esas acciones** en toda tabla con `academic_year_id` (`referentialActionViolations()` en `tests/Feature/Architecture/AcademicYearWriteGuardTest.php`, lista de excepciones vacía). No hay `DELETE` de cursos (`RN-CURSO-07`) |
| Auditoría | `AcademicYear` es `Auditable` con política `Full`: la transición queda como `updated` con `changes.status = {from, to}` sin código adicional (`RN-CURSO-13`). `AuditRecorder::record()` **no escribe la columna `context`** de `audit_logs` hoy |
| Precedentes de «motivo obligatorio» | `mfa_resets` (tabla *append-only* con `reason text NOT NULL`, `tenantTableAppendOnly`) y `user_mfa_exemptions.reason` (política `Full`, criterio de `ADR-035 §8`: contenido del centro, no dato personal en sí) |
| Vocabulario de `audit_logs.event` | Cerrado por `CHECK`; ampliarlo exige ADR y demostrar que el hecho no es CRUD sobre ninguna entidad (`ADR-039 §5.3`) |
| Permisos del módulo | `estado_curso_academico.actualizar` (activar y cerrar), solo en `administrador_centro`, que ya tiene `mfa_required = true` (`permisos.md §4`, `§7`) |
| Reautenticación reforzada (*step-up*) | No existe en la aplicación de centros; `REQ-AUTH/funcional.md` la deja expresamente fuera por no estar en requisitos |

### 1.2 Lo que cambia según el tiempo transcurrido desde el cierre

La reapertura no es un problema único. Depende de qué ha pasado después del cierre:

| Momento | Qué existe | Riesgo de reabrir |
|---|---|---|
| **T1**: cerrado X, ningún curso activo (el siguiente Y sigue en `planificacion`) | Con `OPEN-CURSO-06` = A, es el intervalo normal entre cerrar y activar | Bajo: nadie trabaja aún en Y como curso de referencia |
| **T2**: Y ya está `activo` | Imposible volver X a `activo` sin cerrar Y (índice único), y cerrar Y es a su vez irreversible | Reabrir exigiría tocar dos cursos; desordena el curso de referencia de todo el centro |
| **T3** (desde `1.17b`): promoción aplicada (alumnos asignados a Y) y paquete de cierre generado | Datos en Y derivados de X; actas y boletines finales generados a partir de X | Reabrir X permite cambiar notas ya volcadas en promociones y documentos |
| **T4** (fase 2): actas firmadas (`REQ-DOC-002`) y exportadas a Raíces (`REQ-SEC`, `ADR-016`) | Documentos con valor jurídico entregados a la Administración | Implicaciones legales que **este ADR no conoce** (`funcional.md §15.3`, `OPEN-CURSO-08`) |

Hoy (`1.10`/`1.11`) solo existen T1 y T2. T3 y T4 no existen y su especificación no está escrita.

---

## 2 · Qué NO decide este ADR

- **La rectificación puntual de datos de un curso cerrado** (reclamación de una nota tras el cierre). Es `OPEN-CURSO-19`, de `REQ-CALIF`, y tiene su forma en `ADR-057 §5.7` (excepción declarada por módulo, tabla y operación). Reapertura y rectificación son cosas distintas: la reapertura devuelve **todo** el curso a escritura para **todos** los módulos; la rectificación permite **una** operación concreta con el curso cerrado. Este ADR no usa la reapertura como sustituto de la rectificación, y recomienda que no se use así (`§7`).
- **El archivado** (`OPEN-CURSO-09`). Un curso `archivado` no se reabre por ninguna de las opciones de aquí.
- **Las implicaciones legales de T3/T4.** Las fija la especificación de `1.17b` y de fase 2; este ADR solo les reserva el sitio (`§5.5`).

---

## 3 · Opciones reales

| | Opción | Qué es |
|---|---|---|
| **A** | Sin reapertura (estado actual) | `cerrado` es definitivo. Los errores se corrigen con las excepciones de `ADR-057 §5.7` cuando existan |
| **B** | Reapertura por la API del centro, acotada | Transición `cerrado → activo` en el mismo servicio de transiciones, con permiso propio, motivo obligatorio guardado en una entidad *append-only*, y **solo** para el curso cerrado más reciente y mientras no haya otro activo (ventana T1) |
| **C** | Reapertura por el *backoffice* de plataforma | El centro la pide por soporte; un administrador de plataforma la ejecuta con `runAsPlatform(BackofficeEscritura)` (`ADR-046 §6`), rastro en `admin_action_logs`, con o sin doble autorización |

Variantes descartadas de entrada, por los hechos de `§1.1` y no por gusto:

- **B sin ventana** (reabrir cualquier curso cerrado en cualquier momento): en T2 choca con el índice de un solo activo, así que obligaría a cerrar el curso en curso para reabrir el anterior —un segundo cierre irreversible para deshacer el primero—, o a un estado nuevo («reabierto», coexistiendo con un activo), que es una migración del `CHECK`, un cambio en la función del disparador y en el enumerado (paridad `CA-057-07`), y redefinir qué significa «el curso activo» para cincuenta módulos. Coste desproporcionado para un caso que tiene mejor respuesta en la rectificación de `§5.7`.
- **Reapertura «como propietario» o con SQL de mantenimiento documentado**: prohibido por `ADR-057 §5.7` punto 6 y `AR-14`, y sin auditoría en `audit_logs` (`INV-003`). Es lo que pasará en la práctica si se elige A y ocurre un cierre por error: por eso A no es tan conservadora como parece.

---

## 4 · Evaluación

Criterios del rol: coste en solitario, mantenimiento a 3 años, impacto en invariantes, reversibilidad.

| Criterio | A · sin reapertura | B · API del centro, ventana T1 | C · *backoffice* |
|---|---|---|---|
| **Resuelve el cierre por error** | **No**. Sin vía legítima, el cierre por error empuja a un `UPDATE` manual sin auditoría | Sí, en la ventana en que realmente ocurre (justo después de cerrar, antes de activar el siguiente) | Sí, con la latencia del soporte |
| **Coste de implementación** | Cero | Bajo: una rama más en `assertValidTransition()` con dos comprobaciones (ningún activo, es el cerrado más reciente), una tabla *append-only* `academic_year_reopenings`, un permiso, un *endpoint* o un valor admitido más en el existente, una acción en la ficha. Sin cambios en el disparador ni en su función | Medio: acción nueva de `REQ-BO` (especificación de `REQ-BO` a modificar), clase nueva en la lista blanca de `runAsPlatform()` (`ADR-046 §6.7`), entrada nueva en el vocabulario de `admin_action_logs`, posible doble autorización, pantalla de *backoffice*. **Más caro que B**, no más barato |
| **Mantenimiento a 3 años** | Ninguno de código; coste operativo por cada incidente | Estable. Crece solo si `1.17b` registra condiciones que impidan reabrir (`§5.5`) | Acoplado a `REQ-BO`, que evoluciona por su cuenta |
| **`INV-003` (auditoría)** | Incumplida en la práctica (el arreglo será manual) | `updated` automático de `AcademicYear` + `created` de la entidad de reapertura con el motivo. Sin evento nuevo en `audit_logs` | El rastro va a `admin_action_logs`, **no** a `audit_logs` del centro (`AuditRecorder` retorna en silencio en modo *backoffice*, `ADR-046 §6.5`); el centro lo ve solo en lo que `REQ-BO-007` le exponga |
| **`INV-002` (autorización)** | — | Permiso del centro, denegado por defecto | Capacidad de plataforma; el centro no autoriza nada en el sistema |
| **Quién decide sobre el dato** | — | El centro, responsable del tratamiento | El proveedor actúa sobre datos del centro: solo admisible por instrucción documentada del centro (encargado del tratamiento). Hay que registrar esa instrucción, que el sistema no modela |
| **`INV-007`** | — | Todo dentro de `Curso`; nada de otro módulo cambia | `Backoffice` tendría que escribir `academic_years`, que es `Curso\Domain\Models`: violaría `AR-01` salvo que `Curso` exponga una interfaz pública de reapertura, que entonces sirve también a B |
| **Reversibilidad** | Alta | **Alta**: quitar la rama de transición y el permiso; la tabla de reaperturas queda como histórico. Ampliar la ventana después es compatible (relajar una validación, `ADR-038 §7.2`); estrecharla no | Media: una acción de plataforma con su vocabulario no se retira sin migración de `admin_action_logs` |

**Por qué no A.** No es la opción prudente: es trasladar el riesgo a una operación manual fuera de toda regla. La especificación ya lo calificó de riesgo **Alto** «en cuanto haya datos (desde `1.11`)» (`funcional.md §14`).

**Por qué no C.** Su supuesta ventaja —que un tercero frena el abuso— no compensa: es más cara que B, saca el rastro de `audit_logs` del centro, obliga a `Backoffice` a pasar por un contrato de `Curso` que B ya necesitaría, y pone al proveedor a decidir sobre datos académicos del centro. El freno que C busca se obtiene en B con un permiso que solo tiene `administrador_centro` (con MFA obligatorio), motivo obligatorio y ventana estrecha. Si en el futuro hiciera falta reabrir fuera de la ventana, C es la vía natural para ese caso excepcional y es **aditiva** sobre B.

---

## 5 · Decisión propuesta: opción **B**

### 5.1 Transición y ventana

- Nueva transición válida **`cerrado → activo`**, ejecutada por el **mismo** servicio `AcademicYearTransitions` (`RN-CURSO-10`: ningún otro código escribe `status`). Ninguna otra transición nueva: `archivado → *` sigue siendo `409`.
- Condiciones, comprobadas **dentro** de la transacción, tras el `FOR UPDATE` sobre la fila del curso:
  1. **No hay otro curso `activo`** en el centro. Garantía última: el índice único parcial; la carrera con una activación simultánea se traduce al `409 curso.conflict.active_exists` existente.
  2. **El curso es el cerrado más reciente** del centro: no existe otro curso `cerrado` o `archivado` con `starts_on` posterior. Como las fechas no se solapan (`RN-CURSO-05`), «más reciente» está bien definido. Esto impide convertir un curso de hace tres años en el curso de referencia del centro. Violación: `409` con código nuevo (p. ej. `curso.conflict.reopen_not_latest`).
  3. **Validaciones de reapertura registradas por otros módulos** (`§5.5`): registro vacío en `1.11`.
- **No hay límite de fecha**: la ventana la acotan hechos (activación del siguiente curso, y lo que registren otros módulos), no un plazo inventado. Ningún requisito da un número y el plazo no protege de nada que las condiciones 1-3 no cubran (`OPEN-059-03`).
- **Cuerpo**: la forma exacta (`POST /academic-years/{id}/status` con `{"status":"activo","reason":"…"}` o un *endpoint* propio `POST /academic-years/{id}/reopen`) es de la especificación. Recomendación: **endpoint propio**, porque exige un campo obligatorio (`reason`) que las otras dos transiciones no tienen y un permiso distinto (`§5.2`); mezclarlo en el mismo *endpoint* obligaría a autorizar según el cuerpo, que `AR-07` no ve.

### 5.2 Permiso

- **Recurso nuevo `reapertura_curso_academico`**, acción `actualizar` (ninguna acción inventada, `RPERM-003`), `applicable_scopes = ['todos']`, `is_special_category = false`. Siembra: **solo `administrador_centro`**.
- Motivo: mismo patrón que separó `estado_curso_academico` de `curso_academico` (`OPEN-CURSO-13`). Reabrir deshace la garantía de solo lectura de **todo** el centro para un curso entero; un centro que delega el cierre en dirección no tiene por qué delegar también deshacerlo. Empezar separado es la opción de denegar por defecto: fusionarlos después es aditivo, separarlos después dejaría sin capacidad a los roles personalizados que ya la tuvieran.
- **Sin *step-up***: no existe el mecanismo en la aplicación de centros y no hay requisito (`REQ-AUTH/funcional.md`). El permiso solo lo tiene un rol con `mfa_required = true`. Si el usuario quiere más, es `OPEN-059-02`.

### 5.3 Mecanismo frente al bloqueo de escritura (`ADR-057`, `ADR-058`)

- **No hay excepción al bloqueo ni cambio en `app.assert_academic_year_writable()`.** La reapertura actualiza una fila de `academic_years`, que no tiene `academic_year_id` ni disparador. Al confirmar, el curso deja de estar en `cerrado` y el disparador deja de rechazar sus filas (`ADR-057 §5.7`, último párrafo). El `SQLSTATE` `YC001` no cambia.
- **Concurrencia**: la reapertura toma `FOR UPDATE` sobre la fila del curso (como el cierre). Una escritura concurrente en una tabla de ese curso pide `FOR SHARE` en el disparador: en `READ COMMITTED` espera, relee la versión confirmada y ve `activo` (se permite) o, si la reapertura se revierte, `cerrado` (`YC001`). Ninguna escritura se confirma con el curso cerrado. No hace falta pieza nueva.
- **La escritura del motivo**: la fila de `academic_year_reopenings` lleva `academic_year_id` (es dato del curso) y por tanto el disparador (`AR-13`, sin excepción). Se inserta **después** de cambiar `status`, en la misma transacción: el disparador lee el estado ya actualizado por la propia transacción y lo admite. Si el curso se vuelve a cerrar, la fila queda inmutable, que es lo deseado para un registro *append-only*.
- **Cascadas (#383/#371)**: la reapertura no borra ni cambia claves; no crea camino nuevo. `AR-13` ya prohíbe acciones referenciales en tablas con `academic_year_id`, así que mientras el curso está cerrado ninguna cascada salta el bloqueo, y nada de esto cambia con la reapertura.
- **Prohibido**, se reafirma: reabrir por SQL, por el propietario o desactivando el disparador (`ADR-057 §5.7` punto 6).
- **Volver a cerrar** es la transición `activo → cerrado` de siempre, con sus validaciones de cierre (`RN-CURSO-30`). No hay atajo.

### 5.4 Auditoría y aviso

- **Sin evento nuevo en `audit_logs`.** El hecho es CRUD sobre entidades (`ADR-039 §4.5` regla 1, `§5.3`): queda como
  - `updated` de `academic_year` con `changes.status = {from: "cerrado", to: "activo"}` (automático, `RN-CURSO-13`);
  - `created` de `academic_year_reopening` con el motivo, el actor y el curso (política `Full`, criterio de `ADR-035 §8` aplicado a `user_mfa_exemptions.reason`: contenido del centro; el manual advierte de no escribir datos personales en el motivo).
- **Entidad *append-only* `academic_year_reopenings`** (precedente `mfa_resets`, creada con `tenantTableAppendOnly`): `public_id`, `academic_year_id`, `reason text NOT NULL` (no vacío tras recortar), `created_by`, `created_at`. Responde «quién reabrió, cuándo y por qué» sin leer `audit_logs` y sin depender de que `AuditRecorder` escriba `context` (no lo hace hoy). No se añade columna a `academic_years`: un motivo en la fila del curso se sobrescribiría en la segunda reapertura.
- **Evento de dominio `AcademicYearReopened`**: se especifica junto a `AcademicYearClosed` (`funcional.md §7.2`) y **se emite con su primer consumidor**, igual que los demás (`OPEN-CURSO-18`, `ADR-048 §4.9`). Tras el *commit*, sin datos personales.
- **Notificación a otras personas** (dirección, otros administradores): no hay canal hasta `REQ-COM` (`1.19`). Se decide ahí (`OPEN-059-05`).

### 5.5 Sitio reservado para los pasos diferidos (T3/T4)

`curso` expone en `Curso\Domain` un registro de **validaciones bloqueantes de reapertura**, simétrico al de cierre (`RN-CURSO-30`, mismo patrón y mismas reglas de bloqueo: ninguna validación toma bloqueos de otros módulos antes que el del curso). Se entrega **vacío** y probado con una validación falsa en test, por el mismo argumento con que se aprobó el de cierre (`OPEN-CURSO-07`): es la costura que evita tocar `curso` cuando llegue el consumidor.

Queda escrito como **obligación para las especificaciones futuras**, no como decisión de este ADR:

- `1.17b` (`REQ-CURSO-003`/`-005`) decide si «promoción aplicada» o «paquete de cierre generado» impiden reabrir, y en su caso registra la validación.
- Fase 2 (`REQ-DOC-002`, `REQ-SEC`) decide lo mismo para actas firmadas o exportadas a Raíces.
- `1.12b` (rollover) **no se ve afectado**: copia estructura hacia un curso en `planificacion` y no escribe en el curso origen (`ADR-057 §7`). Reabrir el origen después de un rollover no deshace la copia; se advierte en la interfaz, sin bloquear.

Alternativa más simple, si el usuario prefiere no construir el registro ahora: no construirlo y que `1.17b` lo añada (`OPEN-059-04`). Es aditivo en ambos sentidos.

---

## 6 · Motivo

1. **La alternativa real a reabrir no es «no reabrir»; es reabrir a mano sin auditoría.** Toda la arquitectura de `ADR-057` existe para que nadie escriba en un curso cerrado fuera de las reglas; dejar sin vía legítima el error más previsible (cerrar antes de tiempo) es invitar a saltarse esas reglas la primera vez que ocurra.
2. **La ventana T1 cubre el caso real y deja fuera los casos peligrosos.** Un cierre por error se descubre cuando alguien intenta seguir trabajando, es decir, antes de activar el curso siguiente. Los casos con implicaciones jurídicas (T3/T4) no existen todavía y cuando existan tendrán su validación.
3. **No toca el bloqueo.** Ni función, ni disparador, ni `AR-13`, ni `SQLSTATE`: es una transición de estado, como `ADR-057 §5.7` ya había previsto.
4. **Se apoya en garantías de motor que ya existen**: el índice de un solo activo convierte la condición 1 en invariante de datos, y el `FOR UPDATE`/`FOR SHARE` de `RN-CURSO-32` serializa con las escrituras sin código nuevo.
5. **Es la opción más estricta que resuelve el problema**, y relajarla después (ventana más amplia, permiso fusionado, vía de *backoffice* adicional) es compatible; endurecerla no.

---

## 7 · Consecuencias

**Buenas**

- Un cierre por error tiene una salida auditada y autorizada, sin SQL manual.
- Nada cambia para los cincuenta módulos consumidores: siguen viendo `activo`/`cerrado`, y `AcademicYearContext` devuelve el curso reabierto como activo.
- El motivo queda en una entidad consultable, sin ampliar el vocabulario de `audit_logs`.

**Malas, y hay que asumirlas**

- **«Cerrado» deja de ser definitivo durante la ventana T1.** Cualquier proceso que trate el cierre como hecho consumado (un trabajo programado que reaccione a `AcademicYearClosed`, el futuro paquete de cierre) debe tolerar que el curso vuelva a `activo`. Por eso `AcademicYearReopened` se especifica junto a `AcademicYearClosed`.
- **Riesgo de usar la reapertura como rectificación.** Reabrir para corregir una nota abre el curso entero a todos los módulos y usuarios con permiso. La rectificación correcta es la excepción de `ADR-057 §5.7` (`OPEN-CURSO-19`, `REQ-CALIF`). La ventana T1 lo limita (en cuanto se activa el curso siguiente, deja de ser posible), pero no lo impide dentro de ella. El manual lo debe decir.
- **Un error detectado en T2** (con el siguiente curso ya activo) **sigue sin vía** salvo la rectificación por módulo cuando exista. Es deliberado (`§3`).
- Una tabla y un permiso más en `curso`.

**Ajustes en la especificación de `REQ-CURSO` si se acepta (no los edita `architect`)**

1. `funcional.md §3.1` (diagrama y párrafo), `RN-CURSO-10`, `RN-CURSO-12` y `CA-CURSO-023`: `cerrado → activo` deja de ser par inválido y pasa a transición válida con las condiciones de `§5.1`.
2. `funcional.md §4.4` paso 1 y `CA-CURSO-084`: el diálogo de cierre deja de decir «no se puede deshacer» y explica la ventana («solo mientras no se active otro curso»).
3. `funcional.md §4`: flujo nuevo «Reapertura»; `§7.2`: evento `AcademicYearReopened`; `§7.1`: registro de validaciones de reapertura; `§13`: criterios de aceptación de `§9`; `§14`: el riesgo «cierre irreversible» baja de Alta y se sustituye por «reapertura usada como rectificación».
4. `datos.md`: tabla `academic_year_reopenings`.
5. `api.md`: *endpoint* de reapertura y códigos de error nuevos; quitar la fila «Reapertura … `OPEN-CURSO-08`».
6. `permisos.md §1`, `§2`, `§3`, `§4`: recurso `reapertura_curso_academico`.
7. `operacion.md` (fila «Un curso se cerró por error») y `SYSADMIN.md`: el procedimiento ante un cierre por error pasa a ser la reapertura por la aplicación en la ventana T1; fuera de ella, sigue sin procedimiento y sigue prohibido el SQL manual.
8. `docs/manual-usuario/admin.md`: reapertura, ventana, motivo, y advertencia de no usarla como rectificación.

---

## 8 · Alternativas descartadas

| Alternativa | Por qué no |
|---|---|
| **A · sin reapertura** | Deja el cierre por error sin vía legítima y empuja a un `UPDATE` manual sin auditoría (`INV-003`, `ADR-057 §5.7` punto 6) |
| **C · reapertura por *backoffice*** | Más cara que B, saca el rastro de `audit_logs` del centro, exige que `Backoffice` atraviese un contrato de `Curso` que B ya construye, y pone al proveedor a decidir sobre datos del centro. Queda como ampliación aditiva si algún día hace falta reabrir fuera de la ventana |
| **B sin ventana / estado `reabierto`** | Choca con el índice de un solo activo o exige un quinto estado: migración del `CHECK`, cambio de la función del disparador y de la paridad `CA-057-07`, y redefinir «curso activo» para todos los módulos |
| **Motivo en `audit_logs.context` o evento `reopened`** | `AuditRecorder` no escribe `context` hoy; un evento nuevo exige demostrar que el hecho no es CRUD (`ADR-039 §5.3`), y lo es |
| **Motivo en una columna de `academic_years`** | Se sobrescribe en la segunda reapertura; mezcla el histórico con el estado |
| **Reutilizar `estado_curso_academico.actualizar`** | Válida y más simple, pero quien puede cerrar podría deshacer el cierre sin que el centro lo haya decidido por separado; separarlo después rompería roles personalizados. Es `OPEN-059-01` |
| **Plazo fijo tras el cierre (p. ej. N días)** | Ningún requisito da el número y no protege nada que la condición «no hay otro activo» no proteja ya |

---

## 9 · Criterios de aceptación propuestos (para la especificación)

- **CA-059-01** — Con un curso `cerrado`, el más reciente, sin curso activo, y un usuario con `reapertura_curso_academico.actualizar`: la reapertura con motivo responde `200`, el curso queda `activo`, hay un `updated` con `changes.status = {cerrado, activo}` y una fila en `academic_year_reopenings` con su `created` en `audit_logs`.
- **CA-059-02** — Sin el permiso: `403`. Con `estado_curso_academico.actualizar` pero sin el de reapertura: `403`.
- **CA-059-03** — Sin motivo, o motivo vacío tras recortar: `422`, nada cambia.
- **CA-059-04** — Con otro curso `activo`: `409 curso.conflict.active_exists`, nada cambia. Reapertura y activación simultáneas (dos procesos reales, `tests/Concurrency/`): exactamente una gana, la otra `409`, nunca `500`.
- **CA-059-05** — Con un curso cerrado posterior: `409` (`reopen_not_latest`). Un curso `archivado`: `409 invalid_transition`.
- **CA-059-06** — Tras la reapertura, las escrituras de la tabla sonda en ese curso se admiten; tras volver a cerrarlo, fallan con `YC001`. La función del disparador no ha cambiado (`pg_get_functiondef` igual al de `1.10`).
- **CA-059-07** — Una validación de reapertura registrada en test que falla impide la reapertura con `409` y una entrada por validación; con el registro vacío, se permite.
- **CA-059-08** — Aislamiento: `public_id` de otro centro, `404`.

---

## 10 · Preguntas abiertas (solo las decide el usuario)

| ID | Pregunta | Opciones | Recomendación | Bloquea |
|---|---|---|---|---|
| **`OPEN-059-01`** | ¿Se acepta la reapertura (opción B) frente a A o C? | A / B / C (`§3`) | **B** (`§4`, `§6`) | `1.11` |
| **`OPEN-059-02`** | ¿Permiso propio o el de cierre? ¿Algún freno adicional? | (a) recurso nuevo `reapertura_curso_academico`; (b) reutilizar `estado_curso_academico.actualizar`; además, opcional: doble confirmación por otra persona del centro | **(a)**, sin doble confirmación (no hay mecanismo ni requisito; la ventana estrecha y el motivo bastan) | Especificación |
| **`OPEN-059-03`** | ¿Ventana acotada por hechos (sin otro activo + cerrado más reciente) o también por plazo? | (a) solo hechos; (b) además un plazo en días desde el cierre, configurable o fijo | **(a)**. Si el usuario conoce un plazo normativo (p. ej. de reclamaciones o de entrega de actas), es él quien lo da: no se inventa | Especificación |
| **`OPEN-059-04`** | ¿Se construye ya el registro de validaciones de reapertura (`§5.5`)? | (a) sí, vacío, como el de cierre; (b) no, lo añade `1.17b` | **(a)**, por coherencia con `OPEN-CURSO-07`; (b) es igual de reversible | No |
| **`OPEN-059-05`** | ¿Debe avisarse a alguien de una reapertura? | (a) no; (b) sí, a quienes tengan `estado_curso_academico.actualizar` o `curso_historico.leer`, cuando exista `REQ-COM` (`1.19`) | Decidirlo en `1.19`; hasta entonces basta la auditoría y la tabla de reaperturas | No |
| **`OPEN-059-06`** | **Implicaciones legales de reabrir tras promoción, actas o exportación a Raíces (T3/T4).** ¿Hay norma (Comunidad de Madrid, Raíces) que fije cuándo un curso es definitivo? | La conoce el usuario o el centro, no este ADR | Que la especificación de `1.17b` y la de `REQ-SEC` (fase 2) la respondan y registren la validación correspondiente. **Este ADR no la presupone** | `1.17b` |
