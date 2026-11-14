# ADR-039 · Vocabulario de `audit_logs.event` y eventos de autenticación

**Estado**: ACEPTADA (2026-08-22, incluida la resolución de `OPEN-AUTH-12`)
**Fecha**: 2026-08-22
**Resuelve**: `OPEN-AUTH-02` y `OPEN-AUTH-12` (`docs/modulos/REQ-AUTH/funcional.md §10.2` y §14)
**Amplía**: `ADR-034 §3` (vocabulario cerrado de `audit_logs.event` **y** de `audit_logs.actor_type`; no cambia ninguna otra decisión de ese ADR)
**Concreta**: `INV-003` (auditoría), `INV-013` (trazabilidad)
**Ratifica**: `ADR-035 §7` (`changes` es `NULL` en los eventos sin diff)
**Afecta a**: los 53 módulos del producto — `audit_logs` es tabla única polimórfica
**No decide**: qué eventos no-CRUD podrá añadir cada módulo futuro (§5.3 fija el procedimiento, no la lista)

---

## 1. Contexto

El paso `1.2` (`REQ-AUTH`) necesita registrar en `audit_logs` tres hechos que `INV-003` exige y que el mecanismo automático de `0.9` no produce: **inicio de sesión**, **cierre de sesión** y **solicitud de restablecimiento de contraseña**.

`funcional.md §10.1` deja claro que la mitad del problema no existe: contraseña cambiada, cuenta activada, invitación canjeada, bloqueo y desbloqueo ya quedan registrados por el *observer* del ciclo de vida del ORM, porque **son creación o modificación de entidades reales**. Eso no es casualidad ni suerte: es la consecuencia de que `datos.md §5.2` modelara el bloqueo como la entidad `AccountLockout` en vez de como un evento suelto.

Los tres que faltan no tienen entidad que crear ni columna que modificar. Y el `CHECK` que los rechaza es cerrado por decisión explícita de `ADR-034 §3`.

### 1.1 Estado real del esquema, verificado en el código

Migración `apps/api/database/migrations/2026_08_18_100700_create_audit_logs_table.php`:

```sql
ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_event_check
    CHECK (event IN ('created', 'updated', 'deleted', 'restored', 'read', 'exported'))
```

Nombre real de la restricción: **`audit_logs_event_check`**. Valores actuales: **seis**, los de arriba. La tabla es *append-only* con `REVOKE UPDATE, DELETE` en el motor, `auditable_id` es `NOT NULL`, y `auditable_type` guarda un **alias estable del morph map** (`'user'`, `'person'`, …), nunca el FQCN — `AppServiceProvider::boot()` lo impone con `Relation::enforceMorphMap()`.

### 1.2 Por qué esto no se decide dentro de `REQ-AUTH`

`audit_logs` es **una sola tabla polimórfica para los 53 módulos**. Su vocabulario de `event` es el eje sobre el que se filtra la pantalla de auditoría de `REQ-CORE-005`, sobre el que se exporta a CSV y sobre el que se construirá cualquier informe de cumplimiento. Una convención de ese alcance decidida en la especificación de un módulo no es visible desde los otros 52, no es inmutable y nadie la busca ahí.

Es el precedente exacto de `OPEN-CORE-09` → `ADR-038`, y la regla está escrita en `CLAUDE.md §6.3`.

---

## 2. Qué NO decide este ADR

- **No cambia** la inmutabilidad de `audit_logs`, ni los permisos de motor, ni la política de `changes` de `ADR-035`.
- **No decide** el destino de los intentos fallidos: `funcional.md §10.2` y `datos.md §A.1` ya los pusieron en `login_attempts` por dos motivos independientes de este ADR. §4.3 solo lo ratifica como regla del vocabulario.
- **No abre** el vocabulario a cada módulo: §5.3 fija cómo se amplía, y la respuesta por defecto sigue siendo que no.
- **No decide** el `actor_type` de una petición anónima. Ese problema apareció al redactar este ADR y se documenta sin resolver en §7.

---

## 3. Opciones consideradas

Las reales, las tres que estaban sobre la mesa:

**A · Forzar los tres hechos al vocabulario existente.** Un login sería `read` sobre `User`; un logout, otro `read`; la solicitud de restablecimiento, un `updated`. Coste de implementación: cero. Coste real: el registro que existe **precisamente para no mentir** pasa a contener seis mil filas anuales por centro que dicen «alguien leyó la ficha de este usuario» cuando lo que ocurrió es que entró en el sistema. La pantalla de auditoría deja de poder distinguir un acceso de una consulta, y no hay forma de arreglarlo después: la tabla no admite `UPDATE`.

**B · Dejar los tres fuera de `audit_logs`, solo en `login_attempts`.** Coste de implementación: cero, la tabla ya existe en la especificación. Es defendible y no es absurda. Pero `login_attempts` tiene retención de **90 días** y `audit_logs` de **dos años** (`REQ-CORE-005`), así que la pregunta «¿quién entró en el sistema en marzo?» —de las primeras que hace un centro cuando algo se ha modificado y nadie lo reconoce— pasa a no tener respuesta. Y `logout` y `password_reset_requested` no son intentos de acceso: meterlos en una tabla llamada «intentos de acceso» con `outcome IN (exito, credenciales_invalidas, cuenta_bloqueada, estado_no_activo)` obligaría a inventarles un `outcome` que no les corresponde. Es la opción A otra vez, en otra tabla.

**C · Ampliar el `CHECK` con los tres valores.** Coste de implementación: una migración de tres líneas. Coste de mantenimiento a tres años: tres valores más en un enumerado que ya tiene seis, documentados aquí. Impacto en invariantes: ninguno negativo — es lo que hace que `INV-003` se cumpla de verdad en lugar de aparentarlo. Reversibilidad: la ampliación de un `CHECK` es aditiva y no rompe nada escrito antes; lo que no es reversible son las filas que se escriban con el vocabulario equivocado si se elige A.

---

## 4. Decisión

### 4.1 El vocabulario se amplía: nueve valores de `event`, seis de `actor_type`

```sql
ALTER TABLE audit_logs DROP CONSTRAINT audit_logs_event_check;
ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_event_check
    CHECK (event IN (
        'created', 'updated', 'deleted', 'restored', 'read', 'exported',
        'login', 'logout', 'password_reset_requested'
    ));

ALTER TABLE audit_logs DROP CONSTRAINT audit_logs_actor_type_check;
ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_actor_type_check
    CHECK (actor_type IN ('user', 'system', 'console', 'import', 'platform', 'anonymous'));
```

Los seis valores de `event` y los cinco de `actor_type` existentes se conservan **literalmente**, con el mismo nombre de restricción cada uno. Es una ampliación pura en ambos casos: ninguna fila escrita hasta hoy deja de ser válida. El motivo del segundo `ALTER` se explica en §7.

### 4.2 `event` describe el hecho ocurrido, nunca un verbo CRUD que no le corresponde

Regla general del registro de auditoría, aplicable a los 53 módulos:

> `audit_logs.event` nombra **lo que pasó**. Cuando el hecho es una operación CRUD sobre una entidad, el valor es el verbo CRUD (`created`, `updated`, `deleted`, `restored`) y lo escribe el *observer* automáticamente. Cuando el hecho **no** es una operación CRUD, se le da un nombre propio y **no** se disfraza de una.

`read` y `exported` ya eran precedente de esto desde `ADR-034`: tampoco son CRUD. `login`, `logout` y `password_reset_requested` siguen la misma línea, no la abren.

El corolario práctico importa más que la regla: **si un hecho no cabe en el vocabulario, la respuesta correcta es un ADR que lo amplíe, no elegir el valor menos malo de los que hay.** Elegir el menos malo es barato hoy y no tiene arreglo dentro de una tabla sin `UPDATE`.

### 4.3 Los tres eventos exigen un `User` real, y nunca cubren un correo inexistente

- `auditable_type` = **`'user'`** (alias del morph map, `User::class`), siempre.
- `auditable_id` = la clave interna del usuario real, siempre. `auditable_public_id` = su ULID.
- **Nunca** se escribe una fila de estos tres eventos para un correo que no corresponde a ningún usuario del tenant.

No es una preferencia de diseño: `audit_logs.auditable_id` es `NOT NULL` y hay una FK compuesta hacia `users`. Un intento contra `nadie@example.com` **no tiene a qué apuntar**, y no existe forma correcta de escribirlo en esta tabla.

Ese caso va a **`login_attempts`** (`REQ-AUTH/datos.md §A.1`), que se diseñó con `user_id` nullable exactamente para eso, con retención propia de 90 días y sin auditoría propia. El segundo motivo, independiente, es de volumen: un ataque por diccionario inundaría la tabla que `REQ-CORE-005` obliga a conservar dos años.

`login` en `audit_logs` registra por tanto **accesos consumados**, no intentos. Las dos tablas se correlacionan por `request_id` (`INV-013`), que ambas guardan.

### 4.4 `changes` es `NULL` en los tres

Igual que en `read` y `exported`, y por el mismo motivo de `ADR-035 §7`: no hay diff que registrar, y lo que se registra es *que ocurrió*, no un contenido.

No hace falta tocar `AuditRecorder`: la línea

```php
'changes' => $rawChanges === [] ? null : $this->changeBuilder->build($model, $rawChanges),
```

ya produce `NULL` con un `$rawChanges` vacío, que es como se invocarán los tres.

### 4.5 Quién escribe estas filas

Los tres eventos **no** proceden del ciclo de vida del ORM, así que no los emite `RecordsAuditTrail`. Se escriben con una llamada explícita a `AuditRecorder::record($user, 'login')` desde el servicio de aplicación de `REQ-AUTH`, sin `$rawChanges`. `User` implementa `Auditable`, así que encaja en la firma actual sin cambios.

Dos reglas que acotan esa puerta, porque abrir la escritura manual de auditoría es lo que degrada un registro con el tiempo:

1. La llamada manual solo es legítima para un hecho **que el ciclo de vida del ORM no puede producir**. Si el hecho es un `INSERT`/`UPDATE`/`DELETE` sobre una entidad auditable, lo escribe el *observer* y punto.
2. Los únicos valores admitidos en una llamada manual son los tres de este ADR. Cualquier otro exige ADR nuevo (§5.3).

Consecuencia de orden que la implementación debe respetar y el test verificar: **`logout` se registra antes de destruir la sesión**. Después, `AuditActor::resolveUserId()` devuelve `null` y la fila queda sin actor.

### 4.6 Migración

Es un `DROP CONSTRAINT` + `ADD CONSTRAINT` sobre la misma restricción, ejecutado con la conexión `pgsql_owner`, y pertenece a `REQ-AUTH` (`app/Modules/Auth/Database/migrations/`), no al núcleo: es `REQ-AUTH` quien necesita los valores.

**Compatible con despliegue sin interrupción** (`CLAUDE.md §9`), y conviene decir por qué con precisión en vez de afirmarlo:

- **No hay fase de contracción.** No se retira nada, no se renombra nada, no se deja de usar nada. Expand/contract describe el ciclo completo de un cambio destructivo; esto es solo *expand*, y el ciclo termina ahí.
- **La versión anterior de la aplicación sigue siendo válida contra el esquema nuevo.** Un `CHECK` más permisivo nunca rechaza lo que el código antiguo escribe. El caso inverso —código nuevo contra esquema antiguo— no se da si la migración precede al despliegue, que es el orden normal.
- **Una réplica o instancia antigua no falla por desconocer los valores nuevos.** El `CHECK` es una restricción de escritura, no de lectura: una versión anterior de la aplicación **nunca escribirá** `login`, `logout` ni `password_reset_requested`, porque su código no los produce. Y al leer, `event` es `text`: una fila con un valor que ese binario no conoce se muestra tal cual. Esto es exactamente lo que `ADR-038 §7.3` obliga a garantizar para todo enumerado de respuesta —rama por defecto en el cliente, mostrar el código en crudo antes que fallar—, así que la SPA ya está obligada a tolerarlo por otra vía.
- **Reversión.** El `down()` restaura la restricción de seis valores y **fallará si ya existe alguna fila con los valores nuevos**. Es el comportamiento correcto y se documenta como tal: `audit_logs` no admite `DELETE`, así que no hay forma legítima de deshacer la ampliación una vez usada. En la práctica la migración es de un solo sentido; revertir la *aplicación* a la versión anterior no requiere revertir esta migración, y es lo que se hará.

---

## 5. Motivo

**5.1 El registro de auditoría solo vale lo que vale su honestidad.** Todo lo demás de `audit_logs` —el *append-only* forzado en el motor, el `REVOKE UPDATE`, la redacción de `ADR-035`, la inmutabilidad sin excepciones— está construido sobre la premisa de que lo que dice la tabla es lo que pasó. Registrar un login como `read` sobre `User` gasta ese capital para ahorrar una migración de tres líneas. Es el peor cambio disponible: coste inmediato nulo, coste permanente e irreparable.

**5.2 La opción cara aquí es la de no decidir.** Ampliar un `CHECK` es aditivo, no bloquea despliegues, no rompe clientes y no obliga a tocar los otros 52 módulos. Las filas mal escritas, en cambio, no se pueden corregir: la aplicación no tiene `UPDATE` sobre esta tabla por diseño, y darle `UPDATE` para arreglarlas destruiría la garantía que hace útil el registro. La asimetría es total y decide sola.

**5.3 Se dice que sí a tres valores y que no a un vocabulario abierto.** La tentación evidente al escribir esto es dejar `event` como `text` libre, o añadir de una vez los eventos que previsiblemente pedirán `REQ-CALIF`, `REQ-COM` o `REQ-ECON`. Las dos cosas se rechazan:

- **`text` libre** significa que en tres años habrá `login`, `log_in`, `user_login` y `Login` en la misma columna, y ningún filtro fiable. El `CHECK` es lo que impide eso sin disciplina, y con un solo desarrollador toda convención que dependa de recordarla ya está rota.
- **Añadir eventos por adelantado** es inventar requisitos (`CLAUDE.md §11`) y contradice `ADR-034 OPEN-13`. Cada módulo que necesite un evento no-CRUD amplía el `CHECK` en **su** migración, con **un** ADR corto que cite este, y con la carga de la prueba de su lado: demostrar que el hecho no es CRUD sobre ninguna entidad. Casi siempre lo será —el precedente de `AccountLockout` en §1 lo enseña— y entonces la respuesta correcta es modelar la entidad, no ampliar el vocabulario.

**5.4 La separación con `login_attempts` no es duplicación.** Son dos registros con propósitos, retenciones y volúmenes distintos: `audit_logs` responde a «quién hizo qué» durante dos años; `login_attempts` responde a «qué presión está recibiendo esta cuenta» durante 90 días. Fundirlos obligaría a elegir una retención equivocada para uno de los dos.

---

## 6. Consecuencias

**Buenas**

- `CA-AUTH-071` pasa a ser verificable de verdad para los seis hechos que enumera, no para cuatro.
- La pantalla de auditoría de `REQ-CORE-005` puede responder «quién entró en el sistema» con el filtro `event` que ya existe, sin trabajo adicional de UI ni endpoint nuevo.
- `login` en `audit_logs` + `login_attempts` correlacionados por `request_id` dan la trazabilidad completa de un acceso sin duplicar filas.
- Queda escrito el procedimiento para el próximo módulo que traiga un evento no-CRUD, en vez de que lo resuelva por analogía a su manera.

**Malas, o al menos incómodas**

- El vocabulario pasa de seis a nueve valores y deja de ser puramente estructural: tres de los nueve son de dominio. Es la puerta que §5.3 acota, pero la puerta queda abierta y hay que vigilarla en cada revisión de migración (`db-reviewer`).
- Aparece la **escritura manual de auditoría**, que hasta ahora no existía: todo lo escrito en `audit_logs` venía del *observer*. Un mecanismo automático que nadie puede olvidar convive ahora con uno manual que sí. Se mitiga con §4.5 y con los tests, no se elimina.
- La migración es de un solo sentido en la práctica (§4.6).
- Un `logout` registrado en el orden equivocado produce una fila sin actor, silenciosamente. Necesita test explícito, no revisión.

**Afecta a**: `REQ-AUTH` (`funcional.md §10`, `datos.md`, `CA-AUTH-071`), `REQ-CORE-005` (pantalla y exportación de auditoría), `INV-003`, `INV-013`, y el esquema de `audit_logs` de los 53 módulos.

---

## 7. Problema detectado al redactar este ADR — `OPEN-AUTH-12`, RESUELTA

No estaba en la propuesta original y no se resolvió por cuenta propia: se documentó sin decidir y se llevó al usuario. Queda resuelta aquí.

**`password_reset_requested` lo origina una petición anónima.** Quien pulsa «he olvidado mi contraseña» no tiene sesión. `AuditActor::resolveType()`, tal como está escrito hoy, resuelve así:

```php
if (Auth::id() !== null) { return 'user'; }
return app()->runningInConsole() ? 'console' : 'system';
```

En una petición HTTP sin sesión devuelve **`'system'`**, y `actor_user_id` queda `NULL`. La fila resultante dice que **el sistema** solicitó el restablecimiento, y es indistinguible de la que escribiría un job programado. Es válida contra el `CHECK` de `actor_type` (`user`, `system`, `console`, `import`, `platform`), y es falsa. Exactamente el defecto que §4.2 prohíbe en la columna de al lado.

Las tres salidas consideradas:

- **Aceptar `'system'`.** Coste cero hoy. Escribe filas mentirosas que no se pueden corregir nunca, por el mismo argumento de §5.2.
- **Atribuirlo al usuario destinatario (`actor_type = 'user'`, `actor_user_id` = el usuario del correo).** Peor que la anterior: si un tercero introduce el correo de un profesor, el registro afirma que fue el profesor quien lo pidió. Convierte un dato ausente en una acusación falsa.
- **Añadir `'anonymous'` al `CHECK` de `actor_type`**, en la misma migración de §4.6 y con el mismo carácter aditivo. Una línea más. Es el valor honesto: hay un actor, no está identificado, y no es el sistema.

**Decisión (usuario, 2026-08-22): la tercera.** `AuditActor::resolveType()` gana una rama: si la petición es HTTP, no hay sesión y no se ejecuta en consola, devuelve `'anonymous'` en vez de caer en `'system'` por descarte (`'system'` queda reservado a los jobs y comandos programados que de verdad lo son). `password_reset_requested` se escribe con `actor_type = 'anonymous'`, `actor_user_id = NULL`, y `auditable_type`/`auditable_id` apuntando al usuario real del correo (§4.3, sin cambios: sigue sin escribirse fila si el correo no corresponde a nadie). `login` y `logout` no están afectados — su actor es un usuario ya autenticado y `resolveType()` ya lo resuelve como `'user'`.

**Consecuencia operativa**: ninguna. La migración de §4.6 amplía `event` y `actor_type` a la vez, y no hay periodo en el que `password_reset_requested` deba quedar sin escribirse — `1.2` implementa la rama `'anonymous'` de `resolveType()` antes de emitir la primera fila.

---

## 8. Alternativas descartadas y por qué

| Alternativa | Por qué no |
|-------------|------------|
| Registrar login/logout como `read` sobre `User` (opción A de §3) | Miente en el único registro cuya utilidad depende de no mentir, y la tabla no admite corrección. Coste evitado: tres líneas de migración |
| Dejar los tres solo en `login_attempts` (opción B de §3) | Retención de 90 días frente a los 2 años de `REQ-CORE-005`: la pregunta «quién entró en marzo» se queda sin respuesta. Y `logout` no es un intento de acceso |
| Convertir `event` en `text` sin `CHECK` | Sin restricción de motor, el vocabulario diverge por ortografía en cuestión de meses y los filtros dejan de ser fiables. La restricción es lo que sustituye a la disciplina |
| Tabla `auth_events` separada, con su propio esquema | Tercera tabla de registro con tercera retención, y la pantalla de auditoría tendría que unir tres orígenes. `audit_logs` es polimórfica precisamente para no hacer esto |
| Añadir ya los eventos previsibles de otros módulos | Inventar requisitos (`CLAUDE.md §11`). Cada módulo amplía cuando le toque y demuestra que su hecho no es CRUD |
| Registrar también los intentos fallidos en `audit_logs` | `auditable_id` es `NOT NULL` y un correo inexistente no tiene entidad; además el volumen de un ataque por diccionario contamina una tabla con 2 años de retención |
| Escribir estas filas desde un *listener* de los eventos `Illuminate\Auth\Events\*` en vez de desde el servicio | Acopla el registro a eventos del framework que se disparan también en tests, comandos y autenticación de sesión recordada, y hace difícil garantizar el orden de `logout` respecto a la destrucción de sesión (§4.5) |

---

## 9. Cambios que este ADR obliga a hacer

1. **`apps/api/app/Modules/Auth/Database/migrations/`** — migración con los dos `DROP`/`ADD CONSTRAINT` de §4.1 (`audit_logs_event_check` y `audit_logs_actor_type_check`), en `pgsql_owner`.
2. **`AuditActor::resolveType()`** — nueva rama `'anonymous'` para petición HTTP sin sesión y fuera de consola, antes de la rama `'system'` (§7).
3. **`docs/modulos/REQ-AUTH/funcional.md`** — `OPEN-AUTH-02` y `OPEN-AUTH-12` quedan cerradas por este ADR (hecho, §14 aprobada 2026-08-22).
4. **`docs/modulos/REQ-AUTH/datos.md`** — nota en `§A.1` de que `login` en `audit_logs` registra accesos consumados y `login_attempts` registra intentos, correlacionados por `request_id`.
5. **`docs/modulos/REQ-CORE/datos.md`** — actualizar el vocabulario de `event` y `actor_type` documentado para `audit_logs`.
6. **Tests** — un test por evento referenciando `CA-AUTH-071` (`INV-015`), incluido el de orden de `logout` (fila con `actor_user_id` no nulo), el de `changes IS NULL` en los tres, y el de `actor_type = 'anonymous'` en `password_reset_requested`.
7. **`docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` §18** — añadir `ADR-039` a la tabla de ADR en fichero propio (hecho).
