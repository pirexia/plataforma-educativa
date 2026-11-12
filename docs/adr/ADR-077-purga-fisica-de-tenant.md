# ADR-077 · Purga física de un tenant

**Estado**: **PROPUESTA** (2026-11-12). La acepta el usuario; ninguna parte está implementada. Las preguntas de `§10` marcadas como bloqueantes deben contestarse antes de especificar `1.23b`.
**Fecha**: 2026-11-12
**Origen**: issue [#371](https://github.com/pirexia/plataforma-educativa/issues/371) (obligatorio antes de datos reales y de `1.24`), issue [#534](https://github.com/pirexia/plataforma-educativa/issues/534) (obligación heredada), paso `1.23b` de `PLAN-IMPLEMENTACION.md`, `OPEN-BO-05` (riesgo aceptado el 2026-09-08).
**Concreta sin sustituir**: `ADR-004` (nivel 3, purga física) aplicado al tenant completo; `ADR-034 §3` y `ADR-035 §1` (la purga de tablas de solo anexar la ejecuta el rol propietario desde una tarea de mantenimiento); `REQ-BO-001` (ciclo de vida), `REQ-BO-007` (doble autorización para purgar datos), `REQ-OPS-004` (certificado de destrucción), `REQ-BKP-005` (copias y supresión), `REQ-PRIV-006` (purgas auditadas e informe).
**Se apoya en**: `ADR-033` (roles de PostgreSQL, RLS con `FORCE`, prefijos de Redis y S3), `ADR-036` (el ciclo de vida de `Tenant` se audita en `admin_action_logs`), `ADR-047` (tablas de plataforma con `affected_tenant_id`), `ADR-048 §4.9` (contrato síncrono entre módulos), `ADR-076` (prefijo `tenants/{public_id}/` impuesto por el almacén).
**No sustituye a ningún ADR.** Amplía dos vocabularios cerrados (`dual_authorizations.action` y `admin_action_logs.action`) y el registro de `config/tenancy.php`; lo hace la implementación con migraciones de expansión.

---

## 1 · Contexto

### 1.1 El problema

Dar de baja un centro lo deja inaccesible y conserva todos sus datos. `1.6b` implementó solo el nivel 1 de `ADR-004`: `status = 'eliminado'` más `deleted_at` en `tenants`. `docs/modulos/REQ-BO/funcional.md §5.5.3` lo dice sin rodeos: un centro eliminado conserva indefinidamente los datos de sus menores, sin plazo de purga. Es deuda declarada contra `REQ-PRIV-006` y bloquea la entrada de datos reales (`1.24`, hito H0).

### 1.2 Estado real, comprobado el 2026-11-12

Consultas de solo lectura al catálogo de la base de desarrollo (PostgreSQL 18.6), sobre `develop` en `b7995bc`. Las cifras del issue #371 (31 tablas, 85 claves foráneas) son de octubre y ya no valen.

| Hecho | Valor | Consecuencia |
|---|---|---|
| Tablas con `tenant_id` | **66** de 91 | Todas son propiedad de `plataforma_owner` |
| Claves foráneas en `public` | **261**: 253 `NO ACTION`, 6 `CASCADE` (todas entre tablas de plataforma), 2 `RESTRICT` (hacia `terms_versions`) | Ninguna tabla de tenant borra en cascada |
| Claves foráneas hacia `tenants` | **3**, las tres por `affected_tenant_id` (`admin_action_logs`, `tenant_lifecycle_events`, `feature_flag_rules`) | **Ninguna tabla de tenant tiene clave foránea a `tenants`**: borrar la fila de `tenants` no arrastra nada ni lo impide ninguna de las 66 |
| Tablas de tenant sin `DELETE` para `plataforma_platform` | 9: `academic_year_reopenings`, `audit_logs`, `image_consent_decisions`, `login_attempts`, `mfa_resets`, `pickup_alerts`, `student_movements`, `terms_acceptances`, `year_rollover_items` | El rol del backoffice **no puede** purgar. `plataforma_owner` sí tiene `DELETE` en las 66 |
| Disparadores de usuario que actúan en `DELETE` | 22: `academic_year_write_guard` en 21 tablas (`ADR-057`) y `terms_versions_published_not_deleted` | Bloquean el borrado de un curso cerrado y de una versión publicada **también al propietario** |
| Tablas particionadas | **0** | `ADR-029` y `ADR-034 §3` prevén particionar por curso o por fecha, nunca por tenant |
| Roles | `plataforma_owner` (sin `SUPERUSER`, sin `BYPASSRLS`), `plataforma_app` (ídem), `plataforma_platform` (`BYPASSRLS`) | El propietario está sujeto a RLS por `FORCE` (`ADR-033 §5`) |
| Datos de tenant en tablas **sin** `tenant_id` | `jobs` y `failed_jobs` (el tenant viaja en `payload`), `sessions` (enlazada por `user_sessions.session_id`), `feature_flag_rules`, `admin_action_logs`, `tenant_lifecycle_events`, `dual_authorizations`, `platform_idempotency_keys` | El barrido por `tenant_id` no las alcanza |
| Redis | Caché con prefijo `t{tenant_id}:` (`TenantContext`); la caché de resolución de tenant va bajo el prefijo base (`TenantResolutionCache`) | Sesiones en base de datos (`SESSION_DRIVER=database`), colas en base de datos (`CLAUDE.md §1`) |
| S3 | Prefijo `tenants/{public_id}/` (`ADR-076`). Quedan 16 llamadas directas al disco sin pasar por el contrato (`ADR-076 §3.2`) | **Sin verificar** que las 16 escriban siempre bajo el prefijo del centro |
| Purga existente | `apps/web/scripts/lib/e2e-purge.sh`: superusuario del contenedor de desarrollo, `session_replication_role = replica`, borrado por catálogo | Solo desarrollo. No toca S3, Redis, colas ni sesiones |
| Copias de seguridad | `REQ-BKP` no existe (`1.26`, `RUNBOOK.md §4`). Hay volcados manuales (`RUNBOOK.md`, actualización de PostgreSQL) | La purga llega al plan **antes** que las copias |

### 1.3 Por qué merece ADR

Es la operación más destructiva del producto, decide qué rol de base de datos puede saltarse disparadores y claves foráneas, fija qué sobrevive a un centro y condiciona `REQ-BKP`, el proveedor S3 de producción (`ADR-083`, en curso) y el contrato de encargado.

---

## 2 · Qué NO decide este ADR

1. **El derecho de supresión de un interesado** (art. 17 RGPD, `REQ-PRIV-003`): se ejerce dentro del centro, por anonimización (`ADR-004` nivel 2). Aquí se trata el fin del servicio a un centro entero.
2. **El catálogo de retención por entidad** de `REQ-PRIV-006` ni los tres mínimos de `ADR-035 §10` (purga de `audit_logs` por plazo, anonimización de `people`, registro de supresiones individuales). El texto de `1.23b` los incluye; la purga de tenant **no los necesita**, porque su regla es uniforme (ver `OPEN-PURGA-08`).
3. **La exportación de salida** (`REQ-OPS-004`, `REQ-BKP-002`): no existe. Este ADR la exige como condición previa y no la diseña.
4. **La retención de `admin_action_logs`** (`OPEN-BO-06`, abierta) ni la política de retención de copias de `REQ-BKP`: se les imponen condiciones (`§5.9`, `§5.10`), no valores.
5. **El proveedor S3 de producción** (`OPEN-STO-01`, `ADR-083`): se le imponen requisitos (`§5.6`).
6. **Recursos de terceros** (pasarela de pago, correo, SSO externo): hoy ningún módulo guarda datos del centro fuera de PostgreSQL, Redis y S3. `§5.3` fija la regla para cuando ocurra.

---

## 3 · Marco legal y plazos

No soy asesor jurídico: lo que sigue ordena las fuentes para que **legal decida** (`OPEN-PURGA-01`, `-02`, `-03`). Consulta del 2026-11-12.

| Norma | Qué dice (resumen; el literal solo donde se indica) | Efecto en el diseño |
|---|---|---|
| RGPD art. 28.3.g | El encargado, «a elección del responsable», suprime o devuelve todos los datos al terminar la prestación y suprime las copias existentes, salvo que el Derecho exija conservarlos. Literal parcial verificado en gdpr-info.eu; EUR-Lex no devolvió contenido | **Es la base de la purga de tenant.** La plataforma es encargado; el centro, responsable. No hay plazo legal cerrado: lo fija el contrato de encargado |
| LOPDGDD art. 33.3 | El responsable decide si los datos se destruyen, se devuelven o se entregan a otro encargado. «No procederá la destrucción de los datos cuando exista una previsión legal que obligue a su conservación»: se devuelven al responsable, que garantiza la conservación (BOE, texto consolidado) | **La devolución va antes que la destrucción.** El expediente académico y la contabilidad los conserva el centro, no la plataforma. Purga condicionada a devolución acreditada (`§5.2`) |
| LOPDGDD art. 33.4 | El encargado «podrá conservar, debidamente bloqueados, los datos en tanto pudieran derivarse responsabilidades» de su relación con el responsable (BOE) | Ampara el periodo de gracia y el estado `eliminado` como bloqueo, y la conservación de la auditoría de plataforma. No ampara conservar sin plazo |
| LOPDGDD art. 32 | Bloqueo: datos reservados, sin tratamiento salvo puesta a disposición de jueces, fiscalía y autoridades durante la prescripción; después, destrucción (BOE, apartados 1 a 4 leídos; el 5, incompleto) | Obligación del responsable. Se cita por la definición de bloqueo |
| RGPD art. 5.1.e | Limitación del plazo de conservación. **Literal sin verificar hoy** (EUR-Lex no cargó) | Un centro eliminado no puede quedarse sin fecha de purga: `§5.1` pone plazo máximo y alarma |
| RGPD art. 17 | Derecho de supresión del interesado. **Literal sin verificar hoy** | Fuera de este ADR (`§2.1`). Durante la gracia, las solicitudes las resuelve el centro |
| Copias de seguridad | La autoridad británica (ICO) acepta dejar el dato de las copias «fuera de uso» hasta que la copia se sobrescriba según un calendario (**fuente secundaria**; la página oficial devolvió 404). La CNIL, en su balance de controles del 18-02-2026, señala dificultades para fijar plazos y suprimir datos de las copias (resumen de búsqueda). **No se encontró criterio publicado de la AEPD** | Coincide con `REQ-BKP-005`: el dato caduca con la copia y una restauración no lo reintroduce. La AEPD es la autoridad competente: confirmación pendiente (`OPEN-PURGA-03`) |

**Plazos propuestos** (los 90 días son requisito, `REQ-BO-001`; el resto es propuesta):

| Momento | Plazo | Respaldo |
|---|---|---|
| Baja → fin de la gracia | 90 días, reversible (rescate) | `REQ-BO-001`, `RN-BO-12` |
| Fin de la gracia → eliminación | Manual, con doble autorización | `REQ-BO-001` |
| Eliminación → purga ejecutada | **Máximo 30 días** | Propuesta, `OPEN-PURGA-01` |
| Purga → caducidad de la última copia que contiene los datos | **Máximo el tope de retención de copias con datos personales**; la fecha concreta consta en el certificado | Propuesta, `OPEN-PURGA-03` |

---

## 4 · Opciones reales

Cinco decisiones. Cada una con al menos tres opciones, evaluadas contra: coste de implementación en solitario, mantenimiento a tres años, impacto en invariantes y reversibilidad.

### 4.1 D1 · Cuándo se purga y con qué autorización

| Opción | Ventajas | Riesgos | Coste de revertir |
|---|---|---|---|
| **A. La purga es parte de la eliminación** (una doble autorización hace las dos cosas) | Un solo trámite; no queda nunca un centro eliminado con datos | Cambia el significado de una operación ya entregada (`1.6b`: «los datos se conservan íntegros»). Junta en la misma transacción lógica el paso reversible por base de datos y el irreversible | Alto: reescribe `REQ-BO §5.5.2` y sus criterios de aceptación |
| **B. Operación aparte, posterior a `eliminado`, con su propia doble autorización y plazo máximo con alarma** | Respeta `REQ-BO-007` («purgar datos» exige doble autorización propia). El aprobador ve el inventario de lo que se destruye. No toca lo entregado | Depende de que alguien la pida: sin alarma, el centro se queda sin purgar | Bajo: es aditiva |
| **C. Automática N días después de `eliminado`** | Nadie puede olvidarla | Destrucción irreversible sin persona delante. Incumple `REQ-BO-007` salvo que se reinterprete que la autorización de eliminar cubre la purga. Un fallo en la condición de devolución destruye datos que el centro debía conservar | Medio |

**Recomendada: B.**

### 4.2 D2 · Quién ejecuta el borrado en PostgreSQL y cómo supera disparadores y claves foráneas

| Opción | Ventajas | Riesgos | Coste de revertir |
|---|---|---|---|
| **A. `plataforma_owner`, con `app.tenant_id` fijado y `session_replication_role = replica`; borrado por catálogo, tabla a tabla** | El propietario ya tiene `DELETE` en las 66 tablas y `ADR-034 §3` ya le asigna las purgas. **Sigue sujeto a RLS**: un `DELETE` mal escrito solo alcanza filas del centro en curso. Sin bloqueos sobre otros centros. Los disparadores futuros no rompen la purga. Mecanismo ya ejercido por el parche de desarrollo | `replica` desactiva también las comprobaciones de clave foránea (documentación de PostgreSQL 18): la integridad depende de que el borrado sea completo, y lo garantiza la verificación (`§5.7`), no el motor. Requiere `GRANT SET ON PARAMETER session_replication_role` al propietario, concedido por un superusuario al aprovisionar roles | Bajo: `REVOKE SET` y se cambia de opción |
| **B. `plataforma_owner` con `ALTER TABLE … DISABLE TRIGGER USER` y borrado en orden de dependencias** | No necesita privilegio nuevo. Las claves foráneas siguen comprobándose | `ALTER TABLE` bloquea 22 tablas compartidas a **todos** los centros durante la purga. El orden topológico tiene ciclos (`created_by`/`updated_by` hacia `users` en casi todas las tablas, `users` ↔ `people`): exige anular columnas antes de borrar, es decir, `UPDATE` sobre tablas de solo anexar y sobre cursos cerrados. Cada módulo nuevo puede añadir un ciclo | Medio |
| **C. Disparadores conscientes de la purga** (cada función de guarda cede si el usuario es el propietario y hay una marca de purga) más claves foráneas diferibles | Explícito; falla en cerrado si un disparador nuevo no la contempla | Toca tres funciones hoy y todas las futuras. Hacer diferibles 253 claves foráneas es una migración sobre todo el esquema. La marca la puede fijar cualquier rol; la defensa real sería solo la comprobación de usuario | Alto |
| **D. `ON DELETE CASCADE` desde `tenants`** | «Borrar el tenant lo borra todo» | Exige añadir 66 claves foráneas a `tenants` y reescribir 253. Un `DELETE FROM tenants` de un rol con `BYPASSRLS` borra un centro entero sin más cerrojo. Los disparadores siguen bloqueando | Muy alto |

**Recomendada: A.**

### 4.3 D3 · Cómo sabe la purga qué hay que borrar

| Opción | Ventajas | Riesgos | Coste de revertir |
|---|---|---|---|
| **A. Por convención y catálogo**: toda tabla con `tenant_id` (descubierta en `pg_catalog`), el prefijo S3 del centro y el prefijo Redis del centro; más un registro explícito y corto para lo que no sigue la convención | Cero código por módulo: 53 módulos no pueden olvidarse de registrarse. Las tres convenciones ya están forzadas por tests (`ADR-033 §10`, `ADR-076 §3.3`) | Lo que quede fuera de las convenciones es invisible si no hay un test que lo delate | Bajo |
| **B. Cada módulo implementa un contrato `PurgesTenantData`** (patrón de `ADR-048`) | Cada módulo decide su orden y sus particularidades | Un módulo que no lo implementa deja datos y nadie se entera. 53 implementaciones casi idénticas que mantener | Medio |
| **C. Lista central escrita a mano** de tablas en orden | Legible, revisable | Se queda obsoleta con cada migración: ya pasó con las cifras del issue #371 | Bajo |

**Recomendada: A**, con la puerta de B solo para recursos de terceros (`§5.3`).

### 4.4 D4 · Qué queda del centro tras la purga

| Opción | Ventajas | Riesgos | Coste de revertir |
|---|---|---|---|
| **A. Se borra también la fila de `tenants`** | No queda nada | Lo impiden las claves foráneas de `admin_action_logs` y `tenant_lifecycle_events`, que son de solo anexar: habría que borrar o desligar la auditoría de plataforma, contra `REQ-BO-007` («independiente e inmutable»). Se pierde la referencia para reaplicar la purga tras una restauración | Irreversible |
| **B. La fila de `tenants` queda como lápida**: sigue en `eliminado`, gana `purged_at`, se vacían sus columnas de texto libre; la auditoría de plataforma se conserva | No añade un estado a `TenantStatus` (los cinco de `REQ-BO-001` no cambian, ni sus `CHECK`, ni las métricas por estado, ni el `503` de `§5.4.1`). Sostiene la auditoría, el certificado y la reaplicación | Queda constancia de que el centro existió (nombre y `slug`): es una persona jurídica, no un dato personal, salvo titular persona física (`OPEN-PURGA-07`) | Bajo |
| **C. Estado nuevo `purgado`** | Visible en el inventario como estado propio | Amplía un enumerado que fija un requisito (sería inventar un estado), más todos los `CHECK`, filtros y traducciones | Medio |

**Recomendada: B.**

### 4.5 D5 · Qué se hace con las copias de seguridad

| Opción | Ventajas | Riesgos | Coste de revertir |
|---|---|---|---|
| **A. Caducidad por rotación, con tope máximo de retención, registro de purgas fuera de la base y reaplicación tras toda restauración** | Es lo que ya pide `REQ-BKP-005`. Compatible con la copia inmutable (`REQ-BKP-001`) | El dato sobrevive en copias hasta el tope. Obliga a que `REQ-BKP` no tenga copias anuales de plataforma con datos personales sin tope, o a justificarlas | Bajo |
| **B. Cifrado por centro y destrucción de la clave** (*crypto-shredding*) | La copia queda ilegible para ese centro al instante | Base compartida (`ADR-001`): exige cifrar por columna con clave por tenant, rompe índices, búsquedas y RLS tal como están. `ADR-035` ya lo descartó para `audit_logs` por coste de mantenimiento en solitario | Muy alto |
| **C. Reescribir las copias para extraer al centro** | Borrado real en copias | Imposible con WORM por definición; con PITR exige restaurar, purgar y volver a copiar cada punto. Invalida los checksums del catálogo | Alto |

**Recomendada: A.**

## 5 · Decisión

_Pendiente._

## 6 · Motivo

_Pendiente._

## 7 · Consecuencias

_Pendiente._

## 8 · Alternativas descartadas y por qué

_Pendiente._

## 9 · Tests de regresión que exige esta decisión

_Pendiente._

## 10 · Preguntas abiertas

_Pendiente._

## 11 · Cambios propuestos en ficheros compartidos

_Pendiente._

## 12 · Fuentes y lo no verificado

_Pendiente._
