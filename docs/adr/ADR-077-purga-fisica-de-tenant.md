# ADR-077 · Purga física de un tenant

**Estado**: **PROPUESTA** (2026-11-12). La acepta el usuario; ninguna parte está implementada. Las preguntas de `§10` marcadas como bloqueantes deben contestarse antes de especificar `1.23b`.
**Fecha**: 2026-11-12
**Origen**: issue [#371](https://github.com/pirexia/plataforma-educativa/issues/371) (obligatorio antes de datos reales y de `1.24`), issue [#534](https://github.com/pirexia/plataforma-educativa/issues/534) (obligación heredada), paso `1.23b` de `PLAN-IMPLEMENTACION.md`, `OPEN-BO-05` (riesgo aceptado el 2026-09-08).
**Sustituye en parte** (decisiones del usuario del 2026-11-13, `§10.0`, `C1-01` y `C1-02`), solo para el caso de la purga de un tenant completo:
- **`ADR-004` nivel 3.** `ADR-004` admite la purga física «solo para datos sin obligación legal de conservación y una vez vencidos los plazos». En la purga de tenant la plataforma destruye también datos sujetos a obligación legal de conservación (expediente, actas, contabilidad), porque esa obligación es del responsable y este declara por escrito que los conserva por su cuenta (cerrojo 3 de `§5.2`). Para el derecho de supresión de un interesado dentro del centro, `ADR-004` sigue vigente sin cambios.
- **`ADR-035 §1` y `§10.1`** (retención de `audit_logs`). `ADR-035` purga la fila de `audit_logs` «al vencer su plazo de retención» (dos años por defecto, `REQ-CORE-005`). En la purga de tenant, `audit_logs` del centro se destruye tras devolverse al responsable, aunque su plazo no haya vencido. Fuera de la purga de tenant, `ADR-035` sigue vigente sin cambios.

**Concreta sin sustituir**: `ADR-034 §3` y `ADR-035 §1` en lo demás (la purga de tablas de solo anexar la ejecuta el rol propietario desde una tarea de mantenimiento); `REQ-BO-001` (ciclo de vida), `REQ-BO-007` (doble autorización para purgar datos), `REQ-OPS-004` (certificado de destrucción), `REQ-BKP-005` (copias y supresión), `REQ-PRIV-006` (purgas auditadas e informe).
**Se apoya en**: `ADR-033` (roles de PostgreSQL, RLS con `FORCE`, prefijos de Redis y S3), `ADR-036` (el ciclo de vida de `Tenant` se audita en `admin_action_logs`), `ADR-047` (tablas de plataforma con `affected_tenant_id`), `ADR-048 §4.9` (contrato síncrono entre módulos), `ADR-076` (prefijo `tenants/{public_id}/` impuesto por el almacén).
Amplía dos vocabularios cerrados (`dual_authorizations.action` y `admin_action_logs.action`) y el registro de `config/tenancy.php`; lo hace la implementación con migraciones de expansión.

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
| S3 | Prefijo `tenants/{public_id}/` (`ADR-076`). Quedan **13 llamadas directas** al disco en 12 ficheros sin pasar por el contrato: `ADR-076 §3.2` contaba 16, y `S-1b` migró tres (issue #625). Inventario en `docs/modulos/REQ-CORE/almacen-objetos.md §5` (especificación de `S-2`, rama del PR #643, a `b7995bc`); recuento repetido el 2026-11-13 sobre `develop` (`786c94e`): 13 llamadas en 12 ficheros | Las 13 componen bajo `tenants/<public_id>/`, pero cuatro escritoras toman el `public_id` de un argumento que puede llegar vacío (`almacen-objetos.md §10` y `§18`): riesgo `tenants//…` de `§5.6` |
| Purga existente | `apps/web/scripts/lib/e2e-purge.sh`: superusuario del contenedor de desarrollo, `session_replication_role = replica`, borrado por catálogo | Solo desarrollo. No toca S3, Redis, colas ni sesiones |
| Copias de seguridad | `REQ-BKP` no existe (`1.26`, `RUNBOOK.md §4`). Hay volcados manuales (`RUNBOOK.md`, actualización de PostgreSQL) | La purga llega al plan **antes** que las copias |

### 1.3 Por qué merece ADR

Es la operación más destructiva del producto, decide qué rol de base de datos puede saltarse disparadores y claves foráneas, fija qué sobrevive a un centro y condiciona `REQ-BKP`, el proveedor S3 de producción (`ADR-083`, en `PROPUESTA`) y el contrato de encargado.

---

## 2 · Qué NO decide este ADR

1. **El derecho de supresión de un interesado** (art. 17 RGPD, `REQ-PRIV-003`): se ejerce dentro del centro, por anonimización (`ADR-004` nivel 2). Aquí se trata el fin del servicio a un centro entero.
2. **El catálogo de retención por entidad** de `REQ-PRIV-006` ni los tres mínimos de `ADR-035 §10` (purga de `audit_logs` por plazo, anonimización de `people`, registro de supresiones individuales). El texto de `1.23b` los incluye; la purga de tenant **no los necesita**, porque su regla es uniforme (ver `OPEN-PURGA-08`).
3. **La exportación de salida** (`REQ-OPS-004`, `REQ-BKP-002`): no existe. Este ADR la exige como condición previa y no la diseña. Sí le impone su contenido mínimo conocido: cuando exista el cobro de `ADR-081`, la exportación incluye cobros, movimientos, mandatos con su documento, ficheros de remesa, devoluciones y conciliaciones en formato legible, porque son lo que el centro está obligado a conservar (`ADR-081 §10.4` punto 5, aceptado el 2026-11-13).
4. **La retención de `admin_action_logs`** (`OPEN-BO-06`, abierta) ni la política de retención de copias de `REQ-BKP`: se les imponen condiciones (`§5.9`, `§5.10`), no valores.
5. **El proveedor S3 de producción** (`OPEN-STO-01`, `ADR-083`): se le imponen requisitos (`§5.6`).
6. **Recursos de terceros** (pasarela de pago, correo, SSO externo): hoy ningún módulo guarda datos del centro fuera de PostgreSQL, Redis y S3. `§5.3` fija la regla para cuando ocurra.

---

## 3 · Marco legal y plazos

No soy asesor jurídico: lo que sigue ordena las fuentes para que **legal decida** (`OPEN-PURGA-01`, `-02`, `-03`). Consulta del 2026-11-12.

| Norma | Qué dice (resumen; el literal solo donde se indica) | Efecto en el diseño |
|---|---|---|
| RGPD art. 28.3.g | El encargado, «a elección del responsable», suprime o devuelve todos los datos al terminar la prestación y suprime las copias existentes, salvo que el Derecho exija conservarlos. En esta redacción: literal parcial leído en gdpr-info.eu (reproducción no oficial). **Fila N-03 de `docs/cumplimiento/matriz-normas.md`** (rama de `ADR-083`: el fichero no existe en `develop` y **este ADR se mezcla con `ADR-083`, PR #642**, o después): art. 28.3 a)-h) **«Verificada»** en el texto español de la Oficina de Publicaciones de la UE, consulta del 2026-11-12, con lectura jurídica pendiente de validación ([PVJ], `OPEN-STO-12`) | **Es la base de la purga de tenant.** La plataforma es encargado; el centro, responsable. No hay plazo legal cerrado: lo fija el contrato de encargado |
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

**La purga física de un tenant es una operación propia, posterior a `eliminado`, con doble autorización propia, ejecutada por `plataforma_owner` desde un comando de consola, que borra por convención y catálogo todo lo del centro en colas, Redis, S3 y PostgreSQL, verifica el resultado por una vía independiente, deja la fila de `tenants` como lápida y un registro de la purga dentro y fuera de la base, y trata las copias de seguridad por caducidad con reaplicación obligatoria tras cualquier restauración.**

### 5.1 Ciclo de vida y plazos

```
activo ──baja──▶ en_baja ──(90 días, rescatable)──▶ eliminado ──purga──▶ eliminado + purged_at
                                                    (datos íntegros,        (lápida; irreversible)
                                                     bloqueados)
```

- `TenantStatus` no cambia. Se añade a `tenants` la columna `purged_at TIMESTAMPTZ NULL` (migración de expansión).
- La purga solo se admite sobre un tenant en `eliminado` con `purged_at IS NULL`. Nunca desde `activo`, `suspendido` ni `en_baja`.
- **Plazo máximo propuesto: 30 días desde la eliminación** (`OPEN-PURGA-01`). La tarea diaria existente `bo:check-grace-periods` (o una hermana) escribe `tenant.purga_vencida` una sola vez en `admin_action_logs` cuando se supera, y el centro aparece en un filtro de `GET /tenants`. Es el «informe de datos pendientes de purga» de `REQ-PRIV-006` para este caso.
- No hay purga automática ni ventana de enfriamiento tras la aprobación. La gracia de 90 días, el nombre tecleado y dos personas ya son los cerrojos; un cuarto añade un estado y un planificador sin reducir riesgo.
- **Retención por litigio**: no se construye mecanismo. Si hay una reclamación viva con el centro (LOPDGDD art. 33.4), la purga simplemente no se solicita y el motivo se anota en `admin_action_logs` al atender la alarma (`OPEN-PURGA-06`).

### 5.2 Autorización: cinco cerrojos

Se comprueban en este orden. Los cuatro primeros son los de la eliminación (`REQ-BO §5.5.2`), reutilizados sin variación.

1. Solo `superadministrador`, con reautenticación viva.
2. El solicitante teclea el **nombre exacto** del tenant (comparación literal).
3. **Devolución acreditada y conservación declarada** (LOPDGDD art. 33.3; decisión del usuario `C1-01`, 2026-11-13). La solicitud lleva, obligatoriamente, **las dos** cosas siguientes:
   - **a)** una de estas dos: la referencia a la exportación final entregada al centro, o la referencia a la instrucción escrita del responsable de destruir sin devolución;
   - **b)** la referencia a una **declaración escrita del responsable** de que conserva por su cuenta todo lo que esté sujeto a obligación legal de conservación (expediente académico, actas, contabilidad).

   Si falta cualquiera de las dos, `422` y no se crea solicitud. Mientras `REQ-OPS-004` no exista, en a) solo cabe la instrucción escrita (`OPEN-PURGA-04`). **Sin la declaración b), no hay purga de un centro con datos reales hasta que exista la exportación de salida** (`REQ-OPS-004`), que permite al centro conservar lo que la ley le obliga a conservar.

   Los documentos a) y b) se guardan en el **almacén documental de plataforma** de `§5.9` (fuera del prefijo de cualquier centro), nunca en Git ni en la base; `tenant_purges` guarda su referencia y su huella SHA-256 (`C1-06`).
4. **Doble autorización** con acción nueva `tenant.purgar`: la solicitud queda pendiente y guarda el **inventario previo** (`§5.4`, fase 1). Un `superadministrador` distinto la aprueba viendo ese inventario. **La solicitud no es aprobable mientras el inventario no esté completo**: lo calcula un trabajo en cola (`INV-012`, `C1-05`), no la petición HTTP.
5. **Ejecución en el servidor**: la aprobación no borra nada. La ejecuta un operador con `php artisan` en un contenedor de un solo uso con las credenciales de `plataforma_owner`, igual que las migraciones. El comando exige una autorización `tenant.purgar` aprobada, vigente y no consumida para ese tenant, y la consume.

El quinto cerrojo es consecuencia de `§5.5`, no un adorno: las credenciales del propietario no están en la API ni en los *workers*, y no deben estarlo.

### 5.3 Qué se purga: tres convenciones y un registro

| Almacén | Regla de descubrimiento | Garantía de que no se escapa nada |
|---|---|---|
| PostgreSQL, tablas de tenant | Toda tabla de `public` con columna `tenant_id` (tablas ordinarias y raíces particionadas), leída de `pg_catalog` en cada ejecución. **Incluye las nueve de solo anexar** (`audit_logs`, `login_attempts`, `mfa_resets`, etc.) | Test de esquema #8 de `ADR-033 §10`: no existe tabla sin `tenant_id` que no esté registrada |
| S3 | Todo objeto, versión, marcador de borrado y subida multiparte incompleta bajo `tenants/{public_id}/` | `ADR-076 §3.3` regla 6 (prohibido `Storage` fuera del almacén). **Condición**: las 13 llamadas directas (`almacen-objetos.md §5`) migradas al contrato por `S-2`, y comprobado que no hay objetos bajo `tenants//` (`§5.6`, `OPEN-PURGA-05`) |
| Redis | Toda clave `<prefijo base>t{tenant_id}:*` y la entrada del centro en la caché de resolución | `ADR-033 §9` |
| Lo que no sigue la convención | **Registro explícito** `purge` en `config/tenancy.php` (tabla siguiente) | Test nuevo (`§9`, T3): toda tabla sin `tenant_id` que tenga clave foránea a `tenants` o a una tabla de tenant debe figurar en el registro |

Registro inicial de tablas sin `tenant_id`:

| Tabla | Tratamiento | Motivo |
|---|---|---|
| `jobs` | **Borrar** las filas cuyo `payload`, decodificado como JSON, tenga en la clave de primer nivel `tenant_id` un valor **igual** al `id` del centro. Comparación por igualdad exacta sobre el JSON decodificado, **nunca por subcadena** (con subcadena, el centro 1 arrastraría los trabajos del 11) | Trabajos pendientes de un centro que ya no existe. Comprobado en código el 2026-11-13: `TenancyServiceProvider::registerTenantAwareQueues()` estampa `['tenant_id' => …]` en el *payload* con `Queue::createPayloadUsing`; `jobs` es una sola tabla para `database` y `database_long` (`config/queue.php`, `'table' => env('DB_QUEUE_TABLE', 'jobs')` en las dos conexiones) |
| `failed_jobs` | **Borrar** con el mismo criterio | Los *payloads* pueden llevar datos personales (issue #73) |
| `sessions` | **Borrar** las enlazadas por `user_sessions.session_id` del centro, **antes** de borrar `user_sessions` | La eliminación ya las revoca en cola; la purga no se fía |
| `feature_flag_rules` | **Borrar** las de `affected_tenant_id` | Configuración sin valor probatorio |
| `platform_idempotency_keys` | **Borrar** las caducadas (`expires_at <= now()`), como ya hace `PurgePlatformIdempotencyKeys`, y las vigentes cuyo `response_body` contenga el `public_id` del centro como **valor JSON exacto** (búsqueda por igualdad en el `jsonb`, no por subcadena) | La tabla no tiene columna que la ligue a un centro: comprobado el 2026-11-13, sus columnas son `endpoint`, `idempotency_key`, `request_body_hash`, `status`, `response_status`, `response_body` y `expires_at`, y las filas caducan a las 24 h (`RequirePlatformIdempotencyKey`, `now()->addHours(24)`). Hoy solo la usa `bo.module-rollouts.store`. **Regla**: un *endpoint* nuevo con `idempotent-platform` no puede alargar esa caducidad sin revisar este criterio |
| Correspondencia cuenta del proveedor de pago → centro (`ADR-081 §7.4` regla 1), **si** se implementa como tabla de plataforma | **Borrar** la fila del centro, **después** de desconectar la cuenta conectada y retirar sus rutas de notificación (`ADR-081 §10.4`) | Tabla de plataforma con categoría declarada en `config/tenancy.php` (`ADR-033 §7`). Sin la fila, una notificación tardía del proveedor no resuelve centro y va a cuarentena |
| Cuarentena de notificaciones de pago sin centro resuelto (`ADR-081 §7.4` regla 2) | **No se liga al centro**: no tiene `tenant_id` por definición. Caducidad corta propia; la purga no la toca | Tabla de plataforma con categoría declarada. Si su caducidad supera el plazo de `§5.1`, el certificado la nombra como residuo |
| Correspondencia identificador opaco → centro de los eventos del proveedor de correo (`ADR-079 §4.9` punto 4), **solo si** el proveedor no devuelve una cabecera propia que permita localizar la entrega en una tabla con `tenant_id` | **Borrar** las filas del centro | `ADR-079 §4.9` prefiere no crear esta tabla; si se crea, es tabla de plataforma con categoría declarada y entra aquí |
| `admin_action_logs`, `tenant_lifecycle_events` | **Conservar** | Auditoría de plataforma (`§5.8`) |
| `dual_authorizations` | **Conservar** | Prueba de quién pidió y quién aprobó |
| `tenants` | **Lápida** (`§5.8`) | Sostiene las tres anteriores y la reaplicación |

Las tres filas de proveedores de pago y correo vienen de los hallazgos `C2-05` y `C2-06` de la revisión de la ola 1 (2026-11-13); `ADR-079` y `ADR-081` están en `PROPUESTA` y la forma final de esas tablas la fija su especificación. **Regla para la cuenta conectada**: en las llamadas salientes al proveedor de pago, la cuenta conectada del centro se obtiene **solo** del `TenantContext` activo (configuración del centro en curso), **nunca** de un dato de la petición. En las entrantes, el centro se resuelve por el dato autenticado por la firma (`ADR-081 §7.4` regla 1).

**Regla para módulos futuros**: un módulo que guarde datos de un centro fuera de estas convenciones (servicio de terceros, otra base, otro *bucket*) no se aprueba sin declarar en su especificación cómo se purgan. Solo entonces se crea un contrato `PurgesExternalTenantData` en `REQ-CORE` (`ADR-048 §4.9`). No se construye ahora. Primer candidato conocido: el módulo `doc` de `ADR-082` (copias del prestador de firma, `ADR-082 §4.5`), y el adaptador de pago de `ADR-081 §10.4`; los dos en `PROPUESTA`.

**Recursos de terceros ya previstos** (peticiones de `ADR-079 §6.4`/`§10` y de `ADR-081 §10.4` punto 5/`§15`, aceptadas en la corrección de coherencia de la ola 1, 2026-11-13; los dos ADR en `PROPUESTA`):

| Tercero | Qué conserva del centro | Tratamiento en la purga |
|---|---|---|
| Proveedor de correo (`ADR-079`) | Registros de envío y lista de supresión con direcciones de personas del centro | Antes de la fase 9, petición de borrado al proveedor por su API o, si no la ofrece, caducidad documentada en su contrato. El certificado (`§5.11`) dice cuál de las dos y la fecha |
| Servicios de *push* (`ADR-079`) | Puntos de entrega de los dispositivos | Sus suscripciones viven en una tabla con `tenant_id` (`ADR-079 §4.4` punto 4) y las barre la fase 6; lo que el servicio de *push* conserve tras dejar de recibir envíos caduca por su cuenta, y el certificado lo nombra como residuo |
| Proveedor de pago (`ADR-081`) | Cuenta conectada o credenciales del centro, rutas de notificación, intentos vivos y lo que el proveedor guarde por sus propias obligaciones legales | Antes de la fase 5: desconectar la cuenta o invalidar las credenciales, retirar las rutas de notificación y cancelar los intentos vivos; después, borrar la fila de la correspondencia (tabla de arriba). Lo que el proveedor conserve por obligación legal propia no lo controla la plataforma: el certificado lo declara como excepción |

Cada uno entra por el contrato `PurgesExternalTenantData` cuando su módulo se especifique; hasta entonces es un requisito que esa especificación debe cubrir, no código.

**Límite del criterio de colas**: un trabajo despachado **sin** contexto de tenant lleva `tenant_id = null` en el *payload* aunque su constructor reciba un centro (p. ej. `PurgeUnlockTokens(int $tenantId)` en `Auth`). Esos trabajos no los alcanza el criterio de igualdad. Si existen trabajos así despachados fuera de contexto **no está verificado**: la especificación lo comprueba y, si los hay, o los despacha dentro del contexto del centro o se decide su tratamiento (`§12`).

**Issue #534**: queda resuelto por esta regla. Las alertas de recogida cerradas se **borran**, no se anonimizan; el disparador `pickup_alerts_closed_immutable` solo bloquea `UPDATE`.

### 5.4 Orden de ejecución

El proceso no puede ser atómico (S3 y Redis no participan en la transacción), así que es una secuencia de **fases idempotentes y reanudables**, con el avance anotado en `tenant_purges` (`§5.8`). El orden sigue una regla: **se borra primero lo que depende de otro dato para ser localizado, y al final lo que sirve de índice**. El `id` y el `public_id` del centro, que localizan todo, viven en la lápida y no se borran nunca.

| Fase | Qué hace | Si falla |
|---|---|---|
| **0 · Comprobación** | Estado `eliminado`, `purged_at` nulo, autorización aprobada y vigente, ninguna purga `en_curso` de otro centro (una a la vez) | No empieza |
| **1 · Inventario** | Recuento de filas por tabla, de objetos y bytes en S3, de claves en Redis, de trabajos y sesiones. **Solo cifras**, nunca valores. Al crear la solicitud lo calcula un **trabajo en cola** con `plataforma_platform` (`INV-012`: recorrer 66 tablas y listar un prefijo de S3 no cabe en una petición HTTP); la solicitud queda `inventario pendiente` y no es aprobable hasta que el trabajo termina (`§5.2`, cerrojo 4). El comando lo recalcula | No empieza |
| **2 · Colas** | Borra `jobs` y `failed_jobs` del centro (conexiones `database` y `database_long`, misma tabla) con el criterio de igualdad exacta de `§5.3` | Reanudable |
| **3 · Redis** | `SCAN` con `MATCH` del prefijo y `UNLINK` por lotes. Nunca `KEYS`, nunca `FLUSHDB`. Invalida la caché de resolución | Reanudable |
| **4 · S3** | Lista por prefijo y borra por lotes de hasta 1.000 claves, leyendo los errores **por clave** de la respuesta; aborta las subidas multiparte incompletas; si el *bucket* tiene versiones, borra versiones y marcadores | Reanudable. Un objeto con retención WORM vigente no es un fallo: se anota su fecha de caducidad (`§5.6`) |
| **5 · PostgreSQL, tablas de plataforma con borrado** | `sessions` (por `user_sessions`), `feature_flag_rules`, `platform_idempotency_keys` y, cuando existan, las correspondencias de proveedores del registro de `§5.3` | Reanudable |
| **6 · PostgreSQL, tablas de tenant** | Como `§5.5`: una transacción por tabla, en cualquier orden | Reanudable: la tabla ya vacía se salta |
| **7 · Verificación** | `§5.7`. Si algo no da cero, la purga queda `fallida` y **no** se marca la lápida | Se corrige y se relanza desde la fase 2 |
| **8 · Mantenimiento** | `VACUUM (ANALYZE)` de las tablas afectadas. **No** `VACUUM FULL` (bloqueo exclusivo sobre tablas compartidas) | No invalida la purga; se reintenta |
| **9 · Cierre** | En una transacción: `tenants.purged_at`, vaciado de la lápida, `tenant_purges` a `completada`, `tenant_lifecycle_events`, `admin_action_logs` con `tenant.purgado`. Después, copia del registro al libro externo (`§5.9`) y emisión del certificado (`§5.11`) | El libro externo se reintenta; mientras falte, alarma |

Entre fases el centro está en `eliminado` y responde `503`: un estado intermedio no lo ve nadie. Por eso no hace falta atomicidad y sí idempotencia.

**Condiciones que la especificación debe comprobar en el código** (no verificadas aquí): que un trabajo en cola no pueda entrar en el contexto de un tenant `eliminado`, y que las tareas con `RunsPerTenant` lo salten. Si no es así, pueden reaparecer filas, claves u objetos durante la purga.

### 5.5 Borrado en PostgreSQL

- **Rol**: `plataforma_owner`, por una conexión propia de mantenimiento (`pgsql_owner` o la que ya usen las migraciones). Ni `plataforma_app` ni `plataforma_platform` ganan un solo privilegio: los `REVOKE DELETE` de las tablas de solo anexar siguen intactos.
- **RLS como red de seguridad**: el comando fija `app.tenant_id` al centro a purgar. Como el propietario no tiene `BYPASSRLS` y las tablas llevan `FORCE`, cualquier `DELETE` solo ve filas de ese centro, aunque le falte el `WHERE`. El `WHERE tenant_id = ?` se escribe igualmente, con parámetro ligado (`CLAUDE.md §8`).
- **Disparadores y claves foráneas**: `SET LOCAL session_replication_role = replica` dentro de cada transacción de borrado. Requiere, una sola vez y por superusuario, `GRANT SET ON PARAMETER session_replication_role TO plataforma_owner` en el script de aprovisionamiento de roles de `SYSADMIN.md`. `SET LOCAL` y no `SET`: el parámetro no sobrevive a la transacción.
- **Unidad de trabajo**: una transacción por tabla. El troceado por lotes dentro de una tabla grande es parámetro de implementación, sin cifra fijada aquí (sin medir).
- **Particiones**: se borra por la tabla raíz. Si algún día una tabla se particiona **por tenant**, separar y eliminar la partición sería la vía rápida y exige ADR nuevo.
- **Qué no hace**: no borra la fila de `tenants`, no toca tablas de referencia ni de plataforma fuera del registro, no ejecuta `TRUNCATE`.
- **Residuo físico, dicho sin maquillar**: en PostgreSQL un `DELETE` no elimina la versión de la fila hasta que pasa `VACUUM`, y el `VACUUM` ordinario marca el espacio como reutilizable sin devolverlo al sistema. Hasta que se sobrescriban, quedan restos en páginas y en el WAL, no accesibles por SQL. Se tratan como las copias: fuera de uso, con caducidad. El cifrado en reposo del volumen es la mitigación.

### 5.6 Borrado en S3

- Interfaz **separada** de `TenantObjectStore`, en `App\Support\Files` (nombre orientativo: `TenantObjectPurger`), con un test de arquitectura que solo permita usarla al motor de purga. No se añade un «borrar todo» al contrato que usan los módulos.
- El prefijo lo compone la implementación a partir del `public_id` de la lápida; el motor no recibe ni construye rutas.
- **Requisitos para el proveedor de producción** (`ADR-083`, `OPEN-STO-01`): listado y borrado de versiones y de marcadores, o caducidad de versiones no vigentes con plazo acotado; listado y aborto de subidas multiparte por prefijo; si hay retención WORM en el *bucket* de datos, su plazo no puede superar el tope de `§5.10`. `ADR-083 §6` los recoge como peticiones por escrito `P-15` (borrado de versiones y marcadores y aborto de multiparte por prefijo) y `P-16` (retención de Object Lock configurable por debajo del tope de `§5.10`).
- **Garage** (desarrollo, CI, E2E) no tiene versiones ni bloqueo de objetos según su documentación (sin verificar, `ADR-076 §10`): el camino con versiones **solo se prueba en staging**.
- **Riesgo `tenants//`** (`almacen-objetos.md §10` y `§18`, `OPEN-CORE-76`): cuatro trabajos escritores (`GenerateUserExport`, `GenerateAuditLogExport`, `GenerateImageConsentsExport` y `ValidateUserImport`) componen la clave con un `public_id` recibido como argumento y no rechazan la cadena vacía; con ella escribirían en `tenants//…`, fuera del prefijo de todo centro. La fase 4 lista `tenants/{public_id}/` y la verificación de `§5.7` el mismo prefijo: **un objeto así no lo vería ninguna de las dos**. `S-2` lo hace imposible para lo nuevo; para lo ya escrito, antes de la primera purga con datos reales se comprueba cada *bucket* en busca de objetos fuera de `tenants/<public_id de un centro existente>/` (`OPEN-CORE-76`, recomendada A) y, si aparecen, se tratan a mano y constan en el certificado.
- Las copias por centro de `REQ-BKP-002`, cuando existan, deben vivir bajo el prefijo del centro o en una tabla con `tenant_id`, para que esta fase las destruya sin conocerlas (`§5.10`).

### 5.7 Verificación de borrado

La hace una vía **distinta** de la que borró: la conexión `pgsql_platform` (`BYPASSRLS`, solo lectura para esto), sin depender de `app.tenant_id`.

1. Cada tabla con `tenant_id`, descubierta de nuevo en el catálogo: `count(*) WHERE tenant_id = ?` = 0.
2. Tablas del registro con tratamiento «borrar»: 0 filas del centro.
3. S3: listado del prefijo vacío en objetos, versiones y subidas multiparte; o solo objetos con retención vigente, con su fecha.
4. Redis: `SCAN` del prefijo sin resultados.
5. **Centro testigo**, limitado a las tablas **de solo anexar** y a las **de borrado lógico** (`INV-004`): recuento total de filas del resto de centros antes y después; no puede bajar por la purga. En esas tablas un recuento que baja solo se explica por un borrado físico ajeno, así que detecta un borrado que se sale del centro. En el resto de tablas el borrado físico ordinario de los demás centros (y las purgas por plazo, como `platform_idempotency_keys` o las de `Auth`) bajaría el recuento sin error: dar falsas alarmas es peor que no comprobar, y para ellas la defensa es RLS (`§5.5`) y el test T1 (`C1-16`).

El resultado (cifras por almacén, antes y después) se guarda en `tenant_purges` y alimenta el certificado.

### 5.8 Qué sobrevive

| Qué | Dónde | Por qué | Hasta cuándo |
|---|---|---|---|
| Lápida del centro | `tenants`: `id`, `public_id`, `slug`, `name`, `status`, fechas, `purged_at`. `suspension_message` se vacía | Clave de la auditoría de plataforma y de la reaplicación | Indefinido (`OPEN-PURGA-07`) |
| Auditoría de plataforma | `admin_action_logs`, `tenant_lifecycle_events`, `dual_authorizations` | `REQ-BO-007`, LOPDGDD art. 33.4: prueba de lo que hizo el proveedor | `OPEN-BO-06` (abierta) |
| Registro de la purga | Tabla nueva de plataforma `tenant_purges` (solo anexar en lo esencial): tenant, autorización, referencia de devolución, estado, fase, inventario, resultado, verificación, fechas, fecha de caducidad de copias, **referencia y huella SHA-256 de la instrucción o exportación, de la declaración de conservación y del certificado**. **Solo cifras, identificadores y huellas** | `REQ-PRIV-006` («informe de purgas ejecutadas») | Igual que la auditoría de plataforma |
| Documentos de la purga | Almacén documental de plataforma (`§5.9`): instrucción escrita o referencia de la exportación, declaración de conservación (`§5.2`, cerrojo 3) y certificado (`§5.11`). Fuera del prefijo de cualquier centro; **prohibidos en Git** (repositorio público) | Son la prueba de que la destrucción se pidió y se hizo | Igual que la auditoría de plataforma (`OPEN-BO-06`) |
| Libro externo de purgas | Objeto fuera del prefijo de cualquier centro y fuera de la base, en el *bucket* de copias con Object Lock y encadenado por huellas (`§5.9`) | `REQ-BKP-005` | Mientras exista alguna copia anterior a la purga, como mínimo |
| Copias de seguridad | Donde `REQ-BKP` las tenga | No se pueden reescribir | Tope de `§5.10` |
| Datos de facturación y contrato con el centro | Fuera del tenant (`REQ-SAAS`, no existe) | La plataforma es responsable de esos datos, no encargado | No es de este ADR |

**Auditoría del centro (`audit_logs`)**: **se purga con el resto** (`OPEN-PURGA-02`, decidida el 2026-11-12; `C1-02`, decidida el 2026-11-13). Es el registro de rendición de cuentas del responsable; se le devuelve en la exportación y se destruye. **Nueva lectura de `REQ-CORE-005`**: la retención mínima de dos años rige mientras dura el servicio, no después de la purga del centro. Esto sustituye en parte a `ADR-035` (cabecera) y es un cambio decidido **pendiente de validación jurídica** (`OPEN-STO-12`); se propone anotarlo **en el propio `REQ-CORE-005`**, marcado «pendiente de validación jurídica», y en el historial de `REQUISITOS` (`§11`). Es la decisión con más carga jurídica del documento.

**Comprobación pendiente antes de aceptar que `admin_action_logs` sobrevive**: que sus columnas `changes`, `reason` y `context` no contengan datos personales de personas del centro (por ejemplo, el correo del primer administrador en el alta). No verificado aquí. Si los contienen, hace falta decidir su redacción con `OPEN-BO-06`.

### 5.9 Registro fuera de la base y reaplicación

Una restauración a un punto anterior a la purga devuelve los datos del centro **y borra la fila de `tenant_purges` que decía que estaban purgados**. Por eso el registro de la base no basta:

- Al cerrar cada purga se escribe una entrada en un **libro externo**: un objeto por purga, fuera de `tenants/`, con `public_id` del centro, identificador de la autorización `tenant.purgar` que la amparó, fecha, cifras, huella del certificado y **huella de la entrada anterior** (cadena de huellas: borrar o alterar una entrada intermedia rompe la cadena y se detecta). Sin datos personales.
- **Protección del libro** (`C1-03`): vive en el ***bucket* de copias con Object Lock** (`OPEN-STO-08`, decidida el 2026-11-12: dos *buckets*, el de copias con Object Lock), no en el de datos vivos. Ni la API ni los *workers* tienen credenciales de escritura sobre él; escribe solo el comando de purga. La retención de cada entrada es, como mínimo, la de la copia más larga que pueda contener al centro (`§5.10`). Valor y modo de retención: `OPEN-PURGA-03`.
- **Almacén documental de plataforma** (`C1-06`): en el mismo *bucket* y bajo un prefijo de plataforma propio (nunca bajo `tenants/`), los documentos de la purga de `§5.8` (instrucción o referencia de la exportación, declaración de conservación, certificado). La fase 4 borra solo `tenants/{public_id}/`, así que no los alcanza; T10 lo comprueba.
- **Tras toda restauración** (plataforma completa o PITR), y antes de abrir el servicio, se verifica la cadena de huellas del libro y se compara con la base restaurada. Una entrada se reaplica **solo si** coinciden el `public_id` del centro **y** el identificador de la autorización original con la fila de `dual_authorizations` restaurada, y el centro restaurado está en `eliminado`. Si la cadena no cuadra, si no coinciden los identificadores, o si el centro restaurado **no** está en `eliminado` (por ejemplo, `activo`), **se para y se pide confirmación humana**: nada se purga automáticamente. Cumplidas las condiciones, la reaplicación **no pide doble autorización nueva**: la ampara la original (`REQ-BKP-005`: «se reaplica automáticamente»), y deja su propia entrada en `admin_action_logs`.
- La restauración de **un solo centro** (`REQ-BKP-003` nivel 2) se rechaza si el centro figura en el libro.

### 5.10 Encaje con `REQ-BKP`

`REQ-BKP` (`1.26`) no existe y se implementa después de la purga (`1.23b`). Este ADR le deja cinco condiciones, que su especificación debe recoger:

1. **Tope de retención** para toda copia que contenga datos personales, incluida la inmutable, y sin copias de plataforma de larga duración que lo superen. Valor: `OPEN-PURGA-03`. La «retención escalonada» de `REQ-BKP-001` (diarias, semanales, mensuales y anuales) queda acotada por ese tope o exige justificación jurídica.
2. Copias por centro bajo el prefijo del centro o con `tenant_id` (`§5.6`).
3. Reaplicación del libro de purgas como paso obligatorio del procedimiento de restauración (`§5.9`) y de la restauración automática de prueba (`REQ-BKP-004`).
4. La «destrucción certificada de las copias en la baja de un tenant» (`REQ-BKP-005`) se cumple así: copias por centro, destruidas en la purga; copias de plataforma, por caducidad, con la **fecha de la última** en el certificado.
5. Hasta que `REQ-BKP` exista: el operador declara en la ejecución que no quedan volcados manuales con datos del centro (`RUNBOOK.md`, actualización de PostgreSQL) o indica cuáles y cuándo se destruyen. Consta en `tenant_purges`.

### 5.11 Certificado de destrucción

`REQ-OPS-004` lo exige. Contenido: centro, fechas de baja, eliminación y purga; quién solicitó y quién aprobó (rol, no más datos de los necesarios); referencia de la devolución; cifras por almacén antes y después; resultado de la verificación; **qué sobrevive y hasta cuándo** (auditoría de plataforma, copias y su fecha de caducidad, residuo físico). No promete un borrado que no se ha hecho.

Es un documento generado y un texto legal: cuatro idiomas (`INV-009`), sin traducción automática (`CLAUDE.md §2`, punto 9). Su formato depende del motor de PDF (`ADR-078`, en `PROPUESTA`); mientras no exista, el certificado es el registro de `tenant_purges` exportado. El texto lo valida legal (`OPEN-PURGA-09`).

### 5.12 Reparto entre módulos (`INV-007`)

- **`REQ-BO`**: solicitud, doble autorización, alarma de plazo, `admin_action_logs`, `tenant_purges`, certificado, y el comando de consola que orquesta.
- **Núcleo compartido** (`App\Support\Tenancy` para PostgreSQL y Redis, `App\Support\Files` para S3): el motor. Trabaja por catálogo y prefijos, no importa modelos de ningún módulo.
- Entre ambos, un contrato síncrono declarado en el propietario del motor (`ADR-048 §4.9`), que devuelve el resultado por fase.
- Ningún módulo de negocio escribe una línea para ser purgable. Esa es la propiedad que se quiere conservar con 53 módulos.

### 5.13 Entorno de desarrollo y E2E

`apps/web/scripts/lib/e2e-purge.sh` hace hoy, con el superusuario de desarrollo, la mitad de la fase 6. Recomendación: que la limpieza de E2E pase a usar **el mismo motor**, con una entrada que omite la doble autorización y que solo existe fuera de producción (`OPEN-PURGA-10`). Motivo: el motor se ejercita cada día contra el esquema real y una tabla o un disparador nuevos lo rompen en CI, no en la primera baja real.

---

## 6 · Motivo

1. **Porque la plataforma es encargado y no responsable.** Eso ordena todo lo demás: primero se devuelve, después se destruye, y lo que tiene obligación legal de conservación lo conserva el centro (LOPDGDD art. 33.3). Sin esa lectura, la purga tendría que distinguir tabla a tabla qué se puede borrar, que es el catálogo de retención entero. Con ella, la regla es uniforme y cabe en un paso del plan.
2. **Porque el descubrimiento por catálogo es lo único que aguanta 53 módulos.** Las cifras del issue #371 quedaron obsoletas en cinco semanas (31 tablas entonces, 66 hoy). Una lista escrita o un contrato por módulo se habrían quedado atrás igual.
3. **Porque RLS, que se diseñó para aislar lecturas, sirve aquí de cinturón para el borrado.** Ejecutar con el propietario sujeto a `FORCE` convierte «un `DELETE` sin `WHERE`» de catástrofe de plataforma en error acotado a un centro que ya se iba a borrar. Ninguna otra opción da esa garantía gratis.
4. **Porque `replica` cambia una garantía del motor por una verificación propia, y hay que decir por qué compensa.** Mantener las claves foráneas activas obliga a un orden de borrado con ciclos y a `UPDATE` sobre tablas inmutables. Desactivarlas es seguro si y solo si no queda ninguna fila del centro, y eso es exactamente lo que la fase 7 comprueba por otra conexión y lo que el test T1 comprueba en cada *push*.
5. **Porque las credenciales del propietario no deben llegar a la API ni a los *workers*.** Ejecutar desde consola no es una limitación de la interfaz: es lo que evita que el proceso expuesto a Internet tenga un rol capaz de desactivar disparadores.
6. **Porque una purga que una restauración deshace no es una purga.** El libro externo y la reaplicación son la parte menos vistosa y la que hace cierta la frase del certificado.
7. **Porque es reversible en lo que puede serlo.** Todo es aditivo: una columna, una tabla, dos valores de vocabulario, un `GRANT`, un registro de configuración. Lo único irreversible es el borrado, que es el objeto de la decisión.

---

## 7 · Consecuencias

**A favor**

- Cierra la deuda de `OPEN-BO-05` en su mitad de purga y desbloquea `1.24` en lo que depende de #371.
- Los módulos no escriben nada para ser purgables.
- Resuelve el issue #534 sin tocar su disparador.
- El motor se prueba a diario si se acepta `OPEN-PURGA-10`.

**En contra, y se asume**

- **La purga de un centro con datos reales no puede ejecutarse hasta que exista la devolución** (`REQ-OPS-004` o instrucción escrita). El mecanismo estará antes que su condición previa. Y exige siempre la declaración escrita de conservación del responsable (`§5.2`, cerrojo 3): un centro que no la firme no se purga hasta que exista la exportación de salida.
- **`plataforma_owner` gana un privilegio** (`SET` sobre `session_replication_role`). El propietario ya puede eliminar tablas, así que no hay escalada real, pero es una línea más que auditar en el aprovisionamiento.
- **Un disparador futuro que deba actuar también durante una purga no actuará.** No se conoce ninguno. Si aparece, se resuelve en ese módulo.
- **La operación exige acceso al servidor** y no se completa desde el backoffice. Para un operador en solitario es un paso manual más, deliberado.
- **El borrado no es instantáneo ni forense**: copias hasta el tope, restos en páginas y WAL, registros de aplicación con identificadores hasta su rotación (retención de registros sin verificar). El certificado lo dice.
- **La doble autorización necesita dos superadministradores distintos**, como la eliminación. Con una sola persona operando, no hay purga.
- `REQ-BKP` nace con cinco condiciones heredadas y `ADR-083` con tres.

**Requisitos afectados**: `REQ-PRIV-006`, `REQ-BO-001`, `REQ-BO-007`, `REQ-OPS-004`, `REQ-BKP-001`, `-002`, `-003`, `-004`, `-005`, `REQ-CORE-005`, `RSEC-GDPR-002`, `INV-001`, `INV-003`, `INV-004`, `INV-012`.

**Invariantes**: `INV-001` reforzada (RLS activa durante la purga). `INV-003`: la purga se audita en `admin_action_logs`, fuera del tenant. `INV-004`: no se contradice; `ADR-004` ya prevé el nivel 3 tras el borrado lógico. `INV-007`: `§5.12`. `INV-012`: nada ocurre en la petición HTTP.

**Reversión de la entrega**: las migraciones son de expansión (`CLAUDE.md §9`); el `GRANT` se revoca; el comando se retira. Una purga ejecutada no se revierte: es el motivo de los cinco cerrojos.

**Tamaño estimado** (`CLAUDE.md §2`, punto 7), orientativo: tres entregas. `1.23b-a`: datos, vocabularios, registro, privilegio y tests de esquema. `1.23b-b`: motor, verificación, comando y libro externo. `1.23b-c`: API y pantalla de solicitud y aprobación, alarma, certificado, E2E real. Es un paso **crítico** para el reparto de revisores.

---

## 8 · Alternativas descartadas y por qué

| Alternativa | Por qué no |
|---|---|
| Purgar dentro de la eliminación (D1-A) | Reescribe una operación entregada y junta lo reversible con lo irreversible |
| Purga automática por plazo (D1-C) | Destrucción sin persona delante; choca con `REQ-BO-007` y con la condición de devolución |
| `DISABLE TRIGGER USER` y orden de dependencias (D2-B) | Bloquea tablas compartidas a todos los centros y exige romper ciclos con `UPDATE` sobre tablas inmutables |
| Disparadores conscientes de la purga (D2-C) | Carga permanente sobre cada disparador futuro y migración de 253 claves foráneas |
| `ON DELETE CASCADE` desde `tenants` (D2-D) | Convierte un `DELETE` de una fila en el borrado de un centro; migración sobre todo el esquema |
| Ejecutar con el superusuario del clúster | Sin RLS: pierde el cinturón de `§5.5`. Es lo que hace el parche de desarrollo y por eso no sirve en producción |
| Dar `DELETE` a `plataforma_platform` en las tablas de solo anexar | Con `BYPASSRLS` y desde el proceso web: deshace `ADR-034 §3` |
| Ejecutar desde un *worker* de cola | Llevaría las credenciales del propietario a un proceso permanente |
| Contrato de purga por módulo (D3-B) y lista manual (D3-C) | Se olvidan y se quedan obsoletos |
| Borrar la fila de `tenants` (D4-A) y estado `purgado` (D4-C) | Rompe la auditoría de plataforma; inventa un estado que el requisito no tiene |
| Conservar `audit_logs` del centro hasta su plazo propio | Deja datos personales de actores (IP, agente de usuario) dos años sin responsable que los pida; convierte el certificado en parcial. Queda como alternativa en `OPEN-PURGA-02` si legal lo exige |
| *Crypto-shredding* (D5-B) y reescritura de copias (D5-C) | Coste desproporcionado sobre base compartida; imposible con WORM |
| Anonimizar en vez de borrar | `REQ-BO §5.5.3`: no aplica a un tenant. Un centro anonimizado sigue ocupando 66 tablas con datos de menores seudonimizados |
| `VACUUM FULL` tras la purga | Bloqueo exclusivo sobre tablas de todos los centros para un beneficio que el cifrado en reposo ya cubre |
| Ventana de enfriamiento tras aprobar | Estado y planificador nuevos sin reducir un riesgo que ya cubren 90 días, nombre tecleado y dos personas |

---

## 9 · Tests de regresión que exige esta decisión

Todos referencian `REQ-PRIV-006` y este ADR (`INV-015`).

| Test | Qué demuestra | Tipo |
|---|---|---|
| **T1 · Completitud y aislamiento** | Con dos centros sembrados por `REQ-SEED`, se purga A. Para **cada** tabla con `tenant_id` descubierta en el catálogo: A tiene 0 filas y B conserva su recuento exacto. Ídem para objetos bajo cada prefijo de Garage y claves de cada prefijo de Redis. Como descubre por catálogo, una tabla nueva queda cubierta sin tocar el test | Feature, PostgreSQL, Redis y Garage reales |
| **T2 · El sembrado cubre el esquema** | Antes de purgar, cada tabla con `tenant_id` tiene al menos una fila del centro A, salvo las de una lista de excepciones explícita que solo puede encoger. Sin esto, T1 pasa en vacío | Feature |
| **T3 · Nada fuera de la convención sin registrar** | Toda tabla sin `tenant_id` con clave foránea a `tenants` o a una tabla de tenant figura en el registro `purge` con su tratamiento | Esquema, junto a los de `ADR-033 §10` |
| **T4 · Tablas de solo anexar y disparadores** | La purga borra filas de las nueve tablas de solo anexar, de un curso **cerrado** (`ADR-057`), de una versión de términos **publicada** y de una alerta de recogida **cerrada** (#534) | Feature |
| **T5 · Privilegios intactos** | Tras la migración, `plataforma_app` y `plataforma_platform` siguen sin `DELETE` en las nueve tablas y sin poder fijar `session_replication_role`; fuera de la transacción de purga el parámetro vale `origin` | Esquema |
| **T6 · RLS como red** | Con `app.tenant_id` = A y el propietario, un `DELETE` **sin `WHERE`** sobre una tabla de tenant no toca filas de B | Feature |
| **T7 · Cerrojos** | Rechazo si el estado no es `eliminado`, si ya está purgado, sin nombre exacto, sin referencia de devolución, sin declaración de conservación del responsable, aprobación intentada con el inventario aún pendiente, sin autorización aprobada, con la misma persona como solicitante y aprobador, o con la autorización ya consumida | Feature |
| **T8 · Idempotencia y reanudación** | Un fallo inyectado en cada fase deja la purga `fallida` sin marcar la lápida; relanzarla la completa; relanzar una completada no hace nada | Feature |
| **T9 · Verificación que falla** | Una fila plantada tras la fase 6 hace fallar la fase 7 y no se emite certificado | Feature |
| **T10 · Lo que sobrevive** | Tras purgar: fila de `tenants` con `purged_at`, `admin_action_logs` y `tenant_lifecycle_events` del centro intactos y con `tenant.purgado`, `tenant_purges` completa, entrada en el libro externo. **La instrucción escrita, la declaración de conservación y el certificado siguen en el almacén documental de plataforma tras la fase 4**, y sus huellas coinciden con las de `tenant_purges` | Feature |
| **T11 · Reaplicación** | Con una entrada en el libro externo cuya cadena de huellas cuadra, que coincide en `public_id` e identificador de autorización, y datos de un centro restaurado en `eliminado`, la reaplicación purga sin pedir autorización y lo audita | Feature |
| **T11b · Reaplicación rechazada** | Una entrada **falsificada** (huella que rompe la cadena, o identificador de autorización que no existe en `dual_authorizations`) y una entrada válida sobre un centro restaurado en **`activo`**: en los dos casos no se borra nada, el proceso se para y queda pendiente de confirmación humana | Feature |
| **T12 · Colas y sesiones** | Con dos centros de `id` **1 y 11**: un trabajo pendiente y uno fallido del centro 1 desaparecen y los del 11 siguen (demuestra la igualdad exacta frente a la subcadena); las filas de `sessions` de usuarios del 1 desaparecen; `platform_idempotency_keys` sigue el criterio de `§5.3` | Feature |
| **T13 · Arquitectura** | Solo el motor de purga depende de la interfaz de borrado por prefijo de S3 | Arquitectura (Pest) |
| **E2E real** | Flujo completo contra la API real: baja, eliminación, solicitud, aprobación por otro administrador, ejecución del comando, `503` del centro, certificado | `verificador`, `CLAUDE.md §3` |

**Solo en staging, no en CI**: borrado de versiones y marcadores, y objetos con retención vigente (`§5.6`). Se registra como no probado hasta entonces (`ADR-076 §2.3`).

---

## 10 · Preguntas abiertas

### 10.0 Decididas por el usuario (2026-11-12 y 2026-11-13)

**Decididas el 2026-11-13** (hallazgos de la revisión C1 de la ola 1):

| ID | Decisión | Dónde se aplica | Pendiente |
|---|---|---|---|
| `C1-01` | El cerrojo 3 exige, además de la instrucción o la exportación, una **declaración escrita del responsable** de que conserva por su cuenta todo lo sujeto a obligación legal de conservación (expediente, actas, contabilidad). Sin ella, no hay purga con datos reales hasta que exista la exportación de salida. Este ADR **sustituye en parte** a `ADR-004` nivel 3 para el caso de tenant | Cabecera, `§5.2`, T7 | Validación jurídica (`OPEN-STO-12`) y texto de la declaración (`OPEN-PURGA-09`) |
| `C1-02` | Este ADR **sustituye en parte** a `ADR-035` (retención de `audit_logs`) en la purga de tenant: `audit_logs` del centro se destruye tras devolverse. Nueva lectura de `REQ-CORE-005` como cambio decidido | Cabecera, `§5.8`, `§11` | Validación jurídica (`OPEN-STO-12`) |

**Decididas el 2026-11-12.** El usuario aceptó la recomendación de este ADR en las tres decisiones de la tabla siguiente. Siguen marcadas en `§10.1` para conservar el razonamiento. El ADR continúa en `PROPUESTA` mientras queden preguntas abiertas.

| ID | Decisión | Pendiente |
|---|---|---|
| `OPEN-PURGA-02` | Se purga `audit_logs` del centro (y `login_attempts`, `mfa_resets`) con el resto, devolviéndolo antes en la exportación | Validación jurídica (`ADR-083`, `OPEN-STO-12`) |
| `OPEN-PURGA-04` | Sin `REQ-OPS-004`, la devolución se acredita solo con la instrucción escrita del responsable de destruir sin devolución, referenciada en la solicitud | Ninguno |
| `OPEN-PURGA-11` | Se acepta `GRANT SET ON PARAMETER session_replication_role TO plataforma_owner` y la ejecución desde consola (D2-A) | Revisión de `db-reviewer` y `security-reviewer`, que `§10.1` exige |

### 10.1 Bloquean la implementación de `1.23b`

| ID | Pregunta | Recomendación | Impacto | Decide |
|---|---|---|---|---|
| `OPEN-PURGA-02` | ¿Se purga `audit_logs` del centro (y `login_attempts`, `mfa_resets`) junto con el resto, o se conserva bloqueado hasta su plazo propio? | **Purgar con el resto**, devolviéndolo antes en la exportación. La defensa del proveedor es `admin_action_logs` | Si se conserva: purga en dos tiempos, filas con referencias colgantes, certificado parcial y un diseño distinto de `§5.3` y `§5.7` | Legal, y el usuario |
| `OPEN-PURGA-04` | ¿Cómo se acredita la devolución mientras `REQ-OPS-004` no exista? | **Solo con instrucción escrita del responsable de destruir sin devolución**, referenciada en la solicitud. No se improvisa una exportación manual. Alternativa: adelantar la exportación de salida a antes de `1.24` | Sin respuesta, el cerrojo 3 no se puede especificar. Con la recomendación, un centro real que quiera sus datos no puede purgarse hasta `1.26` | Usuario |
| `OPEN-PURGA-11` | ¿Se acepta `GRANT SET ON PARAMETER session_replication_role TO plataforma_owner` y la ejecución desde consola con credenciales del propietario? | **Sí** (D2-A). Alternativa real: D2-B, con bloqueo de tablas compartidas durante la purga | Decide la mitad del motor y el script de aprovisionamiento de roles | Usuario, con `db-reviewer` y `security-reviewer` |

### 10.2 No bloquean la implementación; sí bloquean la primera purga de datos reales

| ID | Pregunta | Recomendación | Impacto | Decide |
|---|---|---|---|---|
| `OPEN-PURGA-01` | Plazo máximo entre eliminación y purga | **30 días**, constante en `config/backoffice.php` sin `env()` (mismo criterio que los 90 días) | Va al contrato de encargado y a `PRIVACY.md` | Legal |
| `OPEN-PURGA-03` | Tope de retención de copias con datos personales, ubicación y protección del libro externo; confirmación de que la AEPD admite la caducidad en copias | **Tope de 90 días** para toda copia de plataforma, incluida la inmutable; libro en el almacén de copias, fuera del *host*. Ambas cifras son propuesta sin respaldo en requisitos | Condiciona `REQ-BKP-001` («anuales») y el texto del certificado | Legal y usuario, al especificar `REQ-BKP` |
| `OPEN-PURGA-05` | ¿Escriben siempre bajo `tenants/{public_id}/` las llamadas directas al disco (16 en `ADR-076 §3.2`, **13 desde `S-1b`**)? | **Respondida en el código por `almacen-objetos.md §5` y `§10`**: las 13 componen bajo el prefijo, salvo el riesgo `tenants//` de cuatro escritoras (`§5.6`). Queda: `S-2` antes que la purga y la comprobación de *buckets* de `OPEN-CORE-76` | Un objeto fuera del prefijo sobrevive a la purga sin que la verificación lo vea | Técnico |
| `OPEN-PURGA-09` | Texto del certificado de destrucción, de la declaración de conservación del responsable (`§5.2`, cerrojo 3) y cláusula del contrato de encargado (devolución, gracia, plazo, copias) | Redacción por legal en es-ES; traducción humana a en, de, fr | Sin texto validado no se entrega certificado a un centro real | Legal |
| `OPEN-PURGA-12` | ¿Contienen `admin_action_logs` o `tenant_lifecycle_events` datos personales de personas del centro? | **Auditar las columnas `changes`, `reason` y `context`** al especificar; si los hay, decidir con `OPEN-BO-06` | Lo que «sobrevive» podría incluir datos que debían destruirse | Técnico, luego legal |

### 10.3 No bloquean

| ID | Pregunta | Recomendación | Impacto | Decide |
|---|---|---|---|---|
| `OPEN-PURGA-06` | ¿Hace falta un mecanismo de retención por litigio? | **No**: no solicitar la purga y anotar el motivo | Si se quisiera, es columna y regla nuevas | Usuario |
| `OPEN-PURGA-07` | ¿La lápida conserva `name` y `slug` indefinidamente? | **Sí**; revisar si el titular del centro es persona física | Si no, el certificado y la auditoría identifican al centro solo por `public_id` | Legal |
| `OPEN-PURGA-08` | `1.23b` incluye el catálogo de retención y `ADR-035 §10` exige tres mínimos antes de H0. ¿Van en el mismo paso? | **No**: `1.23b` = purga de tenant; el catálogo y los tres mínimos, en paso propio con su especificación, también antes de datos reales | Sin separar, `1.23b` no cabe en tres tramos | Usuario |
| `OPEN-PURGA-10` | ¿La limpieza de E2E usa el motor real con una entrada sin doble autorización fuera de producción? | **Sí**, rechazando en `production` y limitada a centros sembrados por `REQ-SEED` | Si no, `e2e-purge.sh` sigue como está y el motor solo se prueba en sus tests | Usuario, con `security-reviewer` |
| `OPEN-PURGA-13` | ¿Puede el responsable pedir por escrito una purga anticipada, antes de los 90 días? | **No construirlo**; los 90 días constan en el contrato | RGPD art. 28.3.g deja la elección al responsable: legal debe confirmar que el contrato basta | Legal |

---

## 11 · Cambios propuestos en ficheros compartidos

No se ha tocado ninguno. Los aplica la sesión orquestadora al integrar.

| Fichero | Cambio propuesto |
|---|---|
| `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md §18` | Fila nueva: `ADR-077` · Purga física de un tenant (**sustituye en parte** a `ADR-004` nivel 3 y a `ADR-035` en el caso de la purga de un tenant completo; entrada de `1.23b`; issue #371). **Estado: `PROPUESTA`**. Al aceptar: nota «sustituido en parte por `ADR-077`» junto a `ADR-004` y en la fila de `ADR-035` (el texto de un ADR no se reescribe) |
| `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md`, `REQ-CORE-005` | En el propio requisito, tras «Retención mínima de 2 años, configurable por compliance», una nota marcada **«pendiente de validación jurídica (`OPEN-STO-12`)»**: «La retención mínima rige mientras dura el servicio al centro. En la purga física de un tenant (`ADR-077`), `audit_logs` del centro se devuelve al responsable y se destruye». El texto original del requisito no se borra hasta esa validación; la nota se quita o se consolida cuando legal la confirme o la rechace |
| `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md`, historial de versiones | Entrada: «`REQ-CORE-005`: la retención mínima de dos años de los registros de auditoría rige mientras dura el servicio al centro; en la purga física de un tenant (`ADR-077`), `audit_logs` del centro se devuelve al responsable y se destruye. **Cambio decidido por el usuario el 2026-11-13, pendiente de validación jurídica** (`OPEN-STO-12`); anotado en el propio requisito». No se reescribe el texto del requisito hasta esa validación |
| `PLAN-IMPLEMENTACION.md` | `1.23b`: enlazar este ADR; corregir «31 tablas, 85 claves foráneas» por «66 y 261, a 2026-11-12»; partir en `1.23b-a/b/c` (`§7`); separar el catálogo de retención según `OPEN-PURGA-08`; anotar la dependencia de `REQ-OPS-004` (`OPEN-PURGA-04`). `1.26` (`REQ-BKP`): añadir las cinco condiciones de `§5.10`. Nota «No olvidar» de la línea 276: misma corrección de cifras |
| `memory.md` | Decisiones pendientes del usuario: `OPEN-PURGA-02`, `-04`, `-11` |
| `ARCHITECTURE.md` | Tras aceptar: apartado del ciclo de vida del tenant con la fase de purga y qué sobrevive |
| `SYSADMIN.md` | Tras implementar: `GRANT SET ON PARAMETER` en el script de roles; procedimiento de ejecución del comando con credenciales del propietario |
| `RUNBOOK.md` | Tras implementar: purga fallida y reanudación; reaplicación del libro tras restaurar (`§4`); declaración de volcados manuales |
| `PRIVACY.md` | Tras aceptar: fin del servicio, plazos, copias y certificado |
| `CHANGELOG.md` | Entrada del ADR al integrarlo |
| `CLAUDE.md` | Ninguno |
| Issue #371 y #534 | Comentario con enlace a este ADR; #371: cifras actualizadas. No se cierran hasta implementar |

**Fuera de mi ámbito, señalado y no corregido**:

- `docs/modulos/REQ-BO/` (`funcional.md §5.5.3`, `datos.md`, `api.md`, `permisos.md`, `operacion.md`): necesitará la acción `tenant.purgar`, `tenant_purges` y la alarma. Es trabajo de `spec-writer`.
- `ADR-083`: los requisitos de `§5.6` quedan recogidos como `P-15` y `P-16` de su `§6` (lote 3 de correcciones, 2026-11-13).
- `ADR-079 §4.9` y `ADR-081 §7.4` (en `PROPUESTA`): las tablas de plataforma que registra `§5.3` deben declarar su categoría en `config/tenancy.php` cuando se especifiquen.
- `ADR-079 §10` y `ADR-081 §15` pedían a este ADR los recursos de terceros y el contenido de la exportación: recogidos en `§5.3` y `§2.3` (2026-11-13).
- `docs/modulos/REQ-CORE/almacen-objetos.md` (rama del PR #643): su `§17` pide esta misma corrección de cifras, ya aplicada en `§1.2`, `§5.3` y `OPEN-PURGA-05`.

---

## 12 · Fuentes y lo no verificado

**Fuentes, consultadas el 2026-11-12**

| Fuente | Para qué |
|---|---|
| Catálogo de la base de desarrollo (PostgreSQL 18.6), consultas de solo lectura | `§1.2` |
| Repositorio en `b7995bc`: `config/tenancy.php`, `TenantContext.php`, `e2e-purge.sh`, migraciones, `REQ-BO/funcional.md`, ADR citados | `§1.2`, `§5` |
| PostgreSQL 18, «Client Connection Defaults» (`session_replication_role`): https://www.postgresql.org/docs/18/runtime-config-client.html | `replica` desactiva disparadores y comprobaciones de clave foránea; lo cambian superusuarios o quien tenga el privilegio `SET` |
| PostgreSQL 18, `ALTER TABLE`: https://www.postgresql.org/docs/18/sql-altertable.html | `DISABLE TRIGGER USER` lo hace el propietario; los disparadores de restricción, solo superusuario |
| PostgreSQL 18, «Routine Vacuuming»: https://www.postgresql.org/docs/18/routine-vacuuming.html | `DELETE` no elimina la versión de fila de inmediato; `VACUUM` y `VACUUM FULL` |
| AWS, `DeleteObjects`: https://docs.aws.amazon.com/AmazonS3/latest/API/API_DeleteObjects.html | Hasta 1.000 claves por petición, resultado por clave, marcadores de borrado con versiones. Es la referencia de la API S3, no un proveedor elegido |
| BOE, Ley Orgánica 3/2018, texto consolidado: https://www.boe.es/buscar/act.php?id=BOE-A-2018-16673 | Arts. 32 (apartados 1 a 4) y 33 |
| RGPD art. 28: https://gdpr-info.eu/art-28-gdpr/ (reproducción no oficial, en inglés) | Art. 28.3.g |
| `docs/cumplimiento/matriz-normas.md`, fila N-03 (rama de `ADR-083`, PR #642: dependencia de mezcla, no existe en `develop`; consulta del agente A7 del 2026-11-12 al texto español de la Oficina de Publicaciones de la UE, CELEX `32016R0679`) | Art. 28.3 a)-h) en estado «Verificada»; lectura jurídica [PVJ]. Citado en el lote 3 de correcciones (2026-11-13); no releído en esta corrección |
| Código del repositorio, consultado el 2026-11-13 sobre `develop` (`786c94e`): `app/Providers/TenancyServiceProvider.php`, `config/queue.php`, migración `2026_09_15_100200_create_platform_idempotency_keys_table.php`, `RequirePlatformIdempotencyKey.php`, `Backoffice/Http/routes.php` | Criterio de `jobs`/`failed_jobs` y de `platform_idempotency_keys` (`§5.3`) |
| `ADR-079 §4.9` y `ADR-081 §7.4`, `§10.4`, en sus ramas (`PROPUESTA`), leídos el 2026-11-13 | Tablas de proveedores del registro de `§5.3` |
| Búsquedas sobre copias y supresión (ICO, CNIL) | `§3`, solo como orientación |

**Sin verificar**

1. Literal en español de los arts. 5.1.e y 17 del RGPD: EUR-Lex devolvió la página vacía (la matriz, fila N-08, también los deja «sin verificar»). El 28.3.g figura como «Verificada» en la fila N-03 de la matriz; este ADR no lo ha releído.
2. Criterio de la AEPD sobre datos suprimidos en copias de seguridad: no encontrado. La guía del ICO, por fuente secundaria (su página devolvió 404).
3. Art. 32.5 de la LOPDGDD: leído incompleto.
4. Documentación de Redis sobre `SCAN`: la página se descargó pero no se leyó entera; el uso de `SCAN` con `MATCH` y `UNLINK` es práctica conocida, a confirmar en la especificación.
5. Que Garage carezca de versiones y bloqueo de objetos (heredado de `ADR-076 §10`).
6. Formato real de las claves de limitación de tasa en Redis y que caigan bajo el prefijo `t{tenant_id}:`.
7. Que no existan ya objetos bajo `tenants//` en ningún *bucket* (`OPEN-CORE-76`). El código de las 13 llamadas directas está inventariado en `almacen-objetos.md §5` (rama del PR #643); este ADR no lo ha releído llamada a llamada, solo ha repetido el recuento.
8. Que un trabajo en cola o una tarea programada no puedan entrar en un tenant `eliminado` (`§5.4`).
9. Que la revocación de sesiones de la eliminación borre las filas de `sessions`.
10. Contenido de `admin_action_logs` respecto a datos personales del centro (`OPEN-PURGA-12`).
11. ~~Que `jobs` sea una sola tabla para las conexiones `database` y `database_long`~~: **comprobado** el 2026-11-13 en `config/queue.php` (las dos usan `env('DB_QUEUE_TABLE', 'jobs')`), salvo que un entorno fije `DB_QUEUE_TABLE` distinto.
12. Volumen y duración de una purga real: no hay datos; ninguna cifra de lotes ni de tiempos se fija.
13. Retención de los registros de aplicación.
14. Las cifras de `§1.2` son de la base de **desarrollo** con todas las migraciones de `develop`; no hay otro entorno con el que contrastarlas.
15. Si hay trabajos despachados **sin** contexto de tenant que lleven un centro en su constructor (`tenant_id = null` en el *payload*), a los que no alcanza el criterio de `§5.3`.
16. Que los proveedores de pago y correo de `ADR-081` y `ADR-079` necesiten de verdad las tablas de correspondencia registradas en `§5.3`: depende de su especificación.
