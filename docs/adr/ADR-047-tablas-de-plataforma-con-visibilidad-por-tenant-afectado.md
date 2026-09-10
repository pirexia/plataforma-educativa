# ADR-047 · Tablas de plataforma con visibilidad por tenant afectado

**Estado**: **ACEPTADA** (2026-09-08). El usuario ratificó el documento completo, incluida la decisión de `§5.1` de no poner la clave foránea `platform_admin_sessions.session_id → platform_sessions.id`.

**Fecha**: 2026-09-08

**Resuelve**: `OPEN-BO-10` (`docs/modulos/REQ-BO/funcional.md §14`, `datos.md §4.3`), declarada por la propia especificación como puerta previa a la primera migración de `1.6`, sometida al visto bueno conjunto de `architect` y `db-reviewer`. **Ambos vistos buenos están dados**: las tres piezas (`admin_action_logs` con RLS, `platform_sessions`, `platform_admin_sessions`) quedan aprobadas con cambios, ninguna rechazada.

**Amplía**: `ADR-033 §7` (registro de tablas compartidas) y `ADR-033 §10` (test de esquema #8). Mismo mecanismo con el que `ADR-034` amplió `ADR-033 §7` sin sustituirlo.

**Concreta**: `REQ-BO-007`, `INV-001`, `INV-002`, `INV-003`

**Se apoya en**: `ADR-029` (convenciones de tipos e identificadores), `ADR-034 §3` (`audit_logs` append-only), `ADR-035` (redacción de datos personales en auditoría), `ADR-045 §4.4` («un `REVOKE` que no se prueba no existe»), `ADR-046 §2.3` (que declinó decidir esto y lo remitió a `datos.md`), `CLAUDE.md §9` (migraciones expand/contract)

**Afecta a**: el paso **1.6** (`REQ-BO`) de forma inmediata —`admin_action_logs`, `tenant_lifecycle_events`, `platform_admin_sessions`—, el sub-paso **`1.6e`** (`feature_flag_rules`), y **todo módulo futuro** que necesite registrar en una tabla de plataforma un hecho que afecte a un centro concreto. La convención de nombres de `§4.2` es la parte que vincula a los 53 módulos.

**No sustituye a ningún ADR anterior.** En particular **no toca `ADR-033 §5`**: la prohibición de `OR app.current_tenant_id() IS NULL` sigue íntegra, y `§4.3` la refuerza. **No reabre `ADR-046`**, cuyas tres decisiones se dan por firmes.

---

## 1 · Contexto

`ADR-033 §7` clasifica las tablas del sistema en cuatro categorías: de tenant (por defecto), raíz del aislamiento (`tenants`), plataforma (`platform_admins`, `admin_action_logs`, `plans`, `failed_jobs` — «sin `tenant_id`, `REVOKE` completo para `plataforma_app` salvo lo imprescindible»), infraestructura del framework, y catálogos de referencia.

`REQ-BO-007` exige que la auditoría de las acciones del personal del proveedor sea «consultable por el propio centro en lo que le afecte». Las dos frases se tensan: la fila no pertenece al centro —no la crea su actividad, no sigue su suerte al darlo de baja— pero el centro tiene derecho a leer la parte que le concierne.

`docs/modulos/REQ-BO/datos.md §4.3` propuso resolverlo con una columna `affected_tenant_id` (declarada explícitamente como referencia y no como propiedad) más una política RLS de solo lectura, y sometió la propuesta a revisión antes de escribir la migración. La revisión conjunta de `architect` y `db-reviewer` la aprueba, y al hacerlo constata que **el patrón no es de una tabla**: la misma especificación lo instancia tres veces (`admin_action_logs §4.3`, `tenant_lifecycle_events §5.2`, `feature_flag_rules §9.3`), y cualquier módulo que registre acciones del proveedor sobre un centro lo necesitará igual.

### 1.1 · Lo que la revisión encontró, verificado sobre el código

Cuatro hechos que ordenan la decisión. Ninguno es una hipótesis: los tres primeros se comprobaron ejecutando la lectura de los ficheros citados.

1. **El test de esquema #8 define «tabla de tenant» por el nombre literal de la columna.** `apps/api/tests/Feature/Tenancy/IsolationBatteryTest.php` (líneas 76-104): si existe una columna llamada exactamente `tenant_id`, exige `ENABLE` + `FORCE` y **no mira** el registro de tablas compartidas; si no existe, exige la declaración en `config('tenancy.shared_tables')` aplanado.

2. **`SchemaInvariantsTest` usa la misma definición, y con consecuencia más dura.** `apps/api/tests/Feature/Core/SchemaInvariantsTest.php` (líneas 88-127): toda columna `*_id` de tipo `bigint` en una tabla que tenga columna `tenant_id` debe llevar clave foránea **compuesta** `(tenant_id, columna)`. Una tabla de plataforma que nombre `tenant_id` a una referencia queda sujeta a esa exigencia y **no puede cumplirla**: sus otras referencias apuntan a tablas de plataforma, que no tienen `tenant_id` y por tanto no ofrecen el `UNIQUE (tenant_id, id)` que el `FOREIGN KEY` compuesto necesita.

   Aplicado a la especificación tal como está escrita hoy: `tenant_lifecycle_events` exigiría `(tenant_id, performed_by) REFERENCES platform_admins (tenant_id, id)` y `(tenant_id, dual_authorization_id) REFERENCES dual_authorizations (tenant_id, id)`, ninguna de las dos posible. `feature_flag_rules` exigiría `(tenant_id, feature_flag_id) REFERENCES feature_flags (tenant_id, id)`, tampoco. Y `feature_flag_rules`, además, **no lleva RLS** por decisión razonada de `datos.md §9.6`, de modo que también falla el test #8. Son fallos de *build*, no advertencias.

   `admin_action_logs`, que nombró la columna `affected_tenant_id`, pasa los dos tests limpiamente. Es la mejor prueba de que la convención correcta ya estaba encontrada y solo faltaba escribirla como regla.

3. **Los privilegios por defecto conceden también sobre secuencias.** `infra/containers/postgres/init/01-tenancy.sql.tpl` (líneas 52-55) ejecuta `ALTER DEFAULT PRIVILEGES … GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES` y `GRANT USAGE, SELECT ON SEQUENCES` a `plataforma_app` y `plataforma_platform`. Un `REVOKE ALL ON <tabla>` **no toca la secuencia** de una clave `bigserial`: el rol de tenant conserva `nextval()` y la lectura de `last_value`. Es la misma clase de `REVOKE` incompleto que produjo el bug 6 de `0.7` con `failed_jobs`.

4. **RLS filtra filas, no columnas.** Un `GRANT SELECT` sobre la tabla completa deja al *runtime* de un centro leer, en las filas que le corresponden, el motivo interno escrito por el operador, el `context` —que según `datos.md §13` incluye el nombre del empleado del proveedor—, y la dirección IP y el agente de usuario del personal de plataforma. El requisito concede al centro «lo que le afecte», no las notas internas ni los datos personales de quien las escribió.

---

## 2 · Qué NO decide este ADR

1. **No decide qué columnas concretas ve cada centro.** `§4.4` fija que el `GRANT` es de columnas enumeradas y nunca de tabla completa, y el criterio para enumerarlas; la lista exacta de cada tabla la escribe `docs/modulos/REQ-BO/datos.md`, porque es una decisión de producto sobre qué transparencia se le debe al cliente.
2. **No decide la retención de `admin_action_logs`** (`OPEN-BO-06`, sigue abierta).
3. **No reabre `ADR-046`.** La separación de superficie, el almacén `platform_sessions` y la firma de `runAsPlatform()` son firmes.
4. **No arregla el issue [#81](https://github.com/pirexia/plataforma-educativa/issues/81)** ni toca `sessions`.
5. **No decide el particionado** de ninguna de estas tablas: `datos.md §4.4` ya lo evaluó, lo descartó y dejó disparador de revisión escrito.

---

## 3 · Opciones reales

El mecanismo (`affected_tenant_id` + política de solo lectura) no estaba realmente en disputa: los dos revisores lo aprobaron. Lo que había que decidir es **cómo se declara la categoría para que no se erosione**, y ahí sí había dos caminos incompatibles.

| Criterio | **A** · La convención de nombres lleva la semántica; los tests no se tocan | **B** · Los tests aprenden una lista de tablas «de plataforma con referencia a tenant» |
|---|---|---|
| **Coste de implementación en solitario** | Cero. Ninguna migración está escrita todavía; es elegir el nombre de tres columnas | Bajo pero recurrente: dos tests que leer y modificar, más una entrada de configuración nueva que mantener |
| **Mantenimiento a 3 años** | El invariante «`tenant_id` ⇒ propiedad ⇒ RLS a dos sentidos» se mantiene absoluto y legible sin contexto. Un revisor futuro no necesita saber nada más que el nombre de la columna | Los dos guardarraíles pasan a tener lista de exenciones. Cada módulo nuevo añade una entrada, y una entrada de más deja de detectar una tabla de tenant mal declarada. Es el mecanismo de erosión que `ADR-033 §10` existe para impedir |
| **Impacto en las invariantes** | `INV-001` intacto: el test que lo defiende conserva un único significado | `INV-001` se debilita en la capa que lo verifica, no en la que lo aplica — que es peor, porque es la que avisa |
| **Reversibilidad** | Alta mientras no haya migración escrita; baja después (renombrar una columna es ciclo expand/contract sobre una tabla append-only, ver `§7`). **Por eso se decide ahora** | Alta siempre, pero irrelevante: lo que se degrada no es el esquema, es la confianza en el test |

Se adopta **A**. La decisión es de las fáciles: A cuesta cero hoy y B cuesta un poco cada vez, para siempre. Lo único que la hace urgente es la ventana: hoy no hay ninguna migración escrita y renombrar es gratis; en cuanto exista la primera fila, deja de serlo.

---

## 4 · Decisión

### 4.1 · Categoría nueva en la taxonomía de `ADR-033 §7`

Se añade una quinta categoría al cuadro de `ADR-033 §7`, sin modificar ninguna de las cuatro existentes:

| Categoría | Definición | Tratamiento |
|---|---|---|
| **Plataforma con visibilidad por tenant afectado** | La fila **no pertenece** a ningún centro —no la crea su actividad, no la escribe su *runtime*, no sigue su suerte al darlo de baja— pero **registra un hecho que le concierne**, y el producto le debe poder consultarlo | Sin `tenant_id`. Columna de referencia `affected_tenant_id` (`§4.2`). Política RLS propia de solo lectura (`§4.3`). Privilegios de `§4.4`. Declaración obligatoria en `config/tenancy.php` bajo `shared_tables.platform` |

Qué la distingue de sus dos vecinas, porque la confusión es el riesgo:

- **No es una tabla de tenant.** No lleva `DEFAULT app.current_tenant_id()`, no lleva `WITH CHECK`, no lleva clave foránea compuesta, no la escribe `plataforma_app`, y la ausencia de `tenant_id` no es un descuido que haya que corregir algún día.
- **No es una tabla de plataforma ordinaria.** `plataforma_app` conserva `SELECT` sobre un subconjunto de columnas, que es exactamente la cláusula «salvo lo imprescindible» que `ADR-033 §7` dejó abierta y que hasta hoy nadie había ejercido.

Pertenecen a esta categoría, en `1.6`: `admin_action_logs` y `tenant_lifecycle_events`. **No pertenece** `feature_flag_rules` (`§9`, alternativa descartada).

### 4.2 · La columna se llama `affected_tenant_id`, siempre

**El nombre `tenant_id` queda reservado, sin excepción, a la columna de propiedad**: la que lleva `DEFAULT app.current_tenant_id()`, la política `tenant_isolation` con `USING` y `WITH CHECK`, y participa en las claves foráneas compuestas de `ADR-033 §6`.

Una referencia a un tenant desde una tabla de plataforma se llama **`affected_tenant_id`**. Un solo nombre para toda la categoría, no uno por tabla: la regla tiene que ser reconocible de un vistazo en una revisión, y un vocabulario de sinónimos (`subject_`, `target_`, `owner_`) reintroduce por la puerta de atrás la ambigüedad que esto viene a quitar.

Consecuencias inmediatas sobre `docs/modulos/REQ-BO/datos.md`, que aplica la sesión orquestadora:

- `§5.2` · `tenant_lifecycle_events.tenant_id` → **`affected_tenant_id`**.
- `§9.3` · `feature_flag_rules.tenant_id` → **`affected_tenant_id`** (aunque esa tabla no adopte la categoría de `§4.1`: la regla de nombres es sobre la columna, no sobre la política, y es lo que la saca de las dos ramas equivocadas de los tests).
- `§4.3` · `admin_action_logs.affected_tenant_id` ya es correcta y no cambia.

**La columna es anulable y sin `DEFAULT`.** Nula significa alcance global —una acción del proveedor que no afecta a ningún centro en particular—, y esas filas no las ve nadie desde un tenant (`§4.3`).

**Efecto secundario buscado, y no menor**: con el nombre corregido, estas tablas caen en la rama «sin `tenant_id`» del test #8 y su declaración en `shared_tables.platform` pasa a ser **verificada**. Hoy `tenant_lifecycle_events`, al llamar `tenant_id` a su referencia, pasaría el test por la rama de RLS sin que nadie comprobase su declaración — de modo que la afirmación del checklist de `datos.md §11` («las trece se declaran … o el test #8 falla») era falsa precisamente para ella. La convención de nombres no solo evita dos fallos de *build*: devuelve al guardarraíl la cobertura que creía tener.

### 4.3 · Forma canónica de la política RLS

```sql
ALTER TABLE <tabla> ENABLE ROW LEVEL SECURITY;
ALTER TABLE <tabla> FORCE  ROW LEVEL SECURITY;

CREATE POLICY tenant_visibility ON <tabla>
    FOR SELECT
    USING (affected_tenant_id = app.current_tenant_id());
```

Cinco reglas, ninguna negociable:

1. **`FOR SELECT` únicamente.** No hay política de `INSERT`, `UPDATE` ni `DELETE`, y su ausencia **no es un olvido**: bajo `FORCE`, la ausencia de política permisiva es lo que cierra la escritura para todo rol que no tenga `BYPASSRLS`, incluido el propietario del esquema. Es el cierre de verdad; el `REVOKE` de `§4.4` es la capa de encima.
2. **Sin `WITH CHECK`.** Una cláusula `WITH CHECK` en una política de solo lectura no hace nada y sugiere que la tabla admite escritura desde el tenant. No se escribe.
3. **Sin `OR app.current_tenant_id() IS NULL`**, ni ninguna variante. `ADR-033 §5` lo prohíbe terminantemente para las tablas de tenant y aquí rige idéntico.
4. **El nombre de la política es `tenant_visibility`**, distinto de `tenant_isolation`. Dos nombres porque son dos cosas: quien lea un volcado del esquema tiene que poder distinguirlas sin leer el predicado.
5. **Las filas de alcance global son invisibles desde un tenant.** Con `affected_tenant_id` nula, `NULL = <algo>` no es verdadero y la fila no se devuelve. Sin contexto de tenant, `app.current_tenant_id()` es nula y no se devuelve ninguna fila. Falla en cerrado en los dos sentidos, sin código que lo recuerde.

### 4.4 · Forma canónica de privilegios

```sql
-- 1. Punto de partida limpio: los privilegios por defecto de
--    01-tenancy.sql.tpl conceden SELECT/INSERT/UPDATE/DELETE a los dos
--    roles de aplicación, y USAGE/SELECT sobre la secuencia.
REVOKE ALL ON <tabla>              FROM plataforma_app;
REVOKE ALL ON SEQUENCE <tabla>_id_seq FROM plataforma_app;

-- 2. Lo imprescindible, y solo eso: columnas enumeradas, nunca la tabla.
GRANT SELECT (<columnas enumeradas>) ON <tabla> TO plataforma_app;

-- 3. Inmutabilidad también para el rol de plataforma, que es el que
--    escribe (precedente literal: harden_audit_logs_platform_grants).
REVOKE UPDATE, DELETE ON <tabla> FROM plataforma_platform;
```

**Por qué el `GRANT` es de columnas y no de tabla.** RLS filtra filas; no filtra columnas. Con `GRANT SELECT` sobre la tabla completa, el *runtime* de un centro puede leer el motivo interno, el `context`, y la IP y el agente de usuario del personal del proveedor — datos personales de empleados del proveedor expuestos a un cliente, además de información operativa interna. Que el *endpoint* proyecte solo lo debido no basta: la superficie es el `GRANT`, y una consulta descuidada o una inyección ve lo que el `GRANT` permita. El privilegio de columna ya es idiomático en este proyecto (`ADR-045 §4.4` sobre `module_subscriptions`), y tiene la propiedad de fallar **ruidosamente**: un `SELECT *` desde el tenant da error de privilegios en vez de devolver de más.

**Criterio para enumerar.** Entra lo que responde a «qué le pasó a este centro y cuándo»: el identificador público de la entrada, el instante, la acción, la referencia al tenant y la identificación pública del sujeto afectado. **No entra** nada que identifique al empleado del proveedor (`actor_platform_admin_id`, `ip_address`, `user_agent`), ni el `context`, ni el `changes` de `ADR-035`. El `reason` es la única frontera discutible y la decide el producto en `datos.md`, no este ADR (`§2.1`).

**Por qué el `REVOKE` sobre `plataforma_platform` es capa adicional y no el cierre.** El propietario del esquema puede volver a concederse cualquier privilegio revocado con un `GRANT`; un `REVOKE` de rol es una señal fuerte y un obstáculo real, pero no una barrera. Lo que de verdad impide escribir es `FORCE ROW LEVEL SECURITY` sin política permisiva de escritura (`§4.3` regla 1). Se escriben las dos cosas —defensa en profundidad, como `ADR-046 §6.4` hace con la excepción de `AuditRecorder`— pero conviene que quede escrito cuál de las dos es la que sostiene la garantía, para que nadie retire la que importa creyendo que retira la redundante.

**Un `REVOKE` que no se prueba no existe** (`ADR-045 §4.4`). Cada tabla de esta categoría necesita, como mínimo, tres criterios de aceptación: `plataforma_app` no puede insertar; `plataforma_app` no puede leer una columna fuera de la lista; `plataforma_app` **sí** ve, y solo ve, las filas de su tenant. El tercero es el que atrapa una lista de columnas demasiado corta, que es el fallo que no aparece en revisión y sí en producción.

### 4.5 · Estas tablas no usan los ayudantes de `TenantMigration`

`App\Support\Tenancy\TenantMigration::tenantTable()` y `tenantTableAppendOnly()` fuerzan `tenant_id` **NOT NULL** con `DEFAULT app.current_tenant_id()`, la política `tenant_isolation` a dos sentidos y el `UNIQUE (tenant_id, id)`. Las tres cosas son incorrectas para esta categoría. Las tablas de `§4.1` se crean con `Schema::create` sobre la conexión `pgsql_owner` y aplican a mano la forma de `§4.3` y `§4.4`.

Queda escrito porque el ayudante es el camino por costumbre y usarlo aquí produciría, en silencio, una tabla de tenant mal disfrazada.

**Recomendación, no decisión** (no bloquea `1.6` y la toma quien implemente): trece tablas de plataforma escribiendo a mano su juego de `GRANT`/`REVOKE` es exactamente el reparto que motivó la existencia de `TenantMigration`. Un `TenantMigration::platformTable()` que aplique el `REVOKE` de tabla **y de secuencia** en un solo sitio convertiría la omisión de `§1.1` punto 3 en imposible en vez de en probable.

### 4.6 · Los tests de esquema no se tocan

Ni el #8 de `ADR-033 §10` ni `SchemaInvariantsTest`. Su definición de «tabla de tenant» —tener una columna llamada `tenant_id`— pasa a ser exacta gracias a `§4.2`, y esa exactitud es la razón de ser de la convención. Queda **prohibido** añadirles listas de exenciones para acomodar tablas de esta categoría: si una tabla nueva las hace fallar, lo que está mal es la tabla.

---

## 5 · Decisiones puntuales de este ADR

Cosas que no son taxonomía pero que la revisión de `OPEN-BO-10` dejó decididas y que no tienen otro sitio donde vivir.

### 5.1 · `platform_admin_sessions.session_id` **no lleva clave foránea**

La revisión conjunta propuso `FOREIGN KEY (session_id) REFERENCES platform_sessions (id) ON DELETE SET NULL`, con el argumento —correcto en su nivel— de que `SET NULL` no destruye el rastro como haría `CASCADE` ni rompe el recolector como haría `RESTRICT`, y convierte en garantía del motor lo que hoy depende de que el código de cierre se ejecute.

**No se adopta, y el motivo no es ninguno de esos dos: es el orden de escritura.** Verificado sobre el código, no supuesto:

- `App\Modules\Auth\Application\SessionRegistrationService::register()` crea la fila de `user_sessions` **dentro de una transacción, durante la petición**, con el identificador que `session()->getId()` ya conoce tras `regenerate()`.
- La fila de `sessions` la escribe el *driver* al **final** de la petición, cuando `StartSession` guarda la sesión.

En el instante del `INSERT`, la fila referenciada **todavía no existe**. Una clave foránea fallaría en cada inicio de sesión, y como la escritura va en transacción —deliberadamente: «si algo falla, el login falla», dice el propio servicio—, el fallo sería el login. `ON DELETE SET NULL` gobierna el borrado del padre; no dice nada sobre su ausencia en el momento de insertar el hijo, y `DEFERRABLE INITIALLY DEFERRED` tampoco salva el caso, porque la transacción confirma antes de que el *driver* escriba.

Es, literalmente, el segundo de los tres motivos que la migración `2026_08_25_100200_create_user_sessions_table.php` dejó escritos en su *docblock* para no ponerle clave foránea a `user_sessions.session_id`. La sesión de plataforma reproduce la misma mecánica con otro *guard*.

**Lo que se pone en su lugar, y que es más fuerte que la clave foránea**: un barrido de sesiones huérfanas de plataforma, con el precedente exacto de `CloseOrphanedUserSessions` (*job* y comando, `app/Modules/Auth/Infrastructure/`), que para toda fila viva cuya sesión ya no exista en `platform_sessions` anule `session_id`, fije `ended_at` y escriba `end_reason = 'caducidad'`.

Resuelve dos cosas que la clave foránea no resolvía ninguna de las dos:

1. `end_reason = 'caducidad'` está en el `CHECK` de `datos.md §2.7` y **hoy no tiene ningún escritor**: el recolector del *driver* borra de `platform_sessions` y no toca esta tabla. Sin el barrido, toda sesión caducada queda con `ended_at IS NULL` para siempre, y el índice `(platform_admin_id, started_at DESC) WHERE ended_at IS NULL` —que es **la consulta de la revocación**— devuelve sesiones muertas. Suspender a alguien por un incidente pasa a mostrar datos falsos, que es justo el caso de uso con el que `§2.7` justifica la existencia de la tabla.
2. Un `ON DELETE SET NULL` solo actúa cuando el recolector borra, y nunca puede escribir `ended_at` ni `end_reason`, porque una clave foránea no escribe columnas ajenas.

`datos.md §2.7` debe decir explícitamente «sin clave foránea» y por qué, como hizo `1.2`, y `operacion.md` dar al barrido su entrada en el planificador.

### 5.2 · Consecuencia de `§4.3` sobre las migraciones futuras

**Las tablas de esta categoría son de solo anexión permanente, y ninguna migración puede reescribirlas.** Bajo `FORCE ROW LEVEL SECURITY` sin política permisiva de escritura, el rol que ejecuta las migraciones (`plataforma_owner`) tampoco puede escribir: no hay `UPDATE` posible sobre estas tablas por ningún camino que no sea `BYPASSRLS`.

Se anota aquí porque **hoy no está escrito en ningún sitio** y `CLAUDE.md §9` exige que las migraciones futuras lo sepan:

- Una migración *expand/contract* que añada una columna y necesite **rellenarla** sobre las filas existentes **no puede hacerlo**. `ADD COLUMN` es DDL y funciona; el `UPDATE` de relleno, no.
- Por tanto, toda columna nueva en estas tablas nace anulable y sin retroactividad, o no nace. Renombrar una columna es imposible sin pérdida del histórico.
- Es la razón por la que `§3` califica de urgente la ventana de la convención de nombres: **hoy** renombrar `tenant_id` → `affected_tenant_id` es editar un fichero que no existe; después de la primera fila, no hay ciclo *contract* que valga.
- `INV-004` (borrado lógico) **no aplica** a estas tablas, igual que no aplica a `audit_logs`: una columna `deleted_at` en una tabla que nadie puede actualizar sería inerte y engañosa. `INV-003` se cumple **siendo** el registro, no registrándose en otro.

---

## 6 · Motivo

1. **El mecanismo ya era correcto; lo que faltaba era el sitio donde escribirlo.** `affected_tenant_id` con política de solo lectura respeta la letra de `ADR-033 §7` («sin `tenant_id`»: la fila no pertenece a nadie) y su espíritu («`REVOKE` completo salvo lo imprescindible»: se concede menos de lo que la cláusula permitía, no más). Pero es una regla que van a copiar los 53 módulos, y una especificación de módulo no es un sitio donde nadie la vaya a buscar. Es el mismo argumento con el que `OPEN-CORE-09` acabó siendo `ADR-038`.

2. **La convención de nombres compra la vigencia de dos guardarraíles por el precio de tres palabras.** Es la parte más barata de esta decisión y la que más rinde a tres años: mientras `tenant_id` signifique exactamente una cosa, los tests que defienden `INV-001` siguen midiendo lo que dicen medir sin lista de exenciones. La alternativa era enseñarles excepciones, que es el mecanismo por el que un guardarraíl se vacía sin que nadie lo note.

3. **Un `GRANT` de columna es la diferencia entre «el *endpoint* no lo devuelve» y «el motor no lo deja leer».** Es la forma que este proyecto le da a `INV-001` desde `ADR-033 §5`, aplicada al eje que RLS no cubre. Y falla ruidosamente, que es la propiedad que se busca siempre aquí.

4. **Lo que cierra la escritura es la ausencia de política bajo `FORCE`, no el `REVOKE`.** Escribir los dos y decir cuál es cuál evita que dentro de dos años alguien retire la barrera creyendo que retira la redundancia.

5. **Se decide ahora porque después no se puede.** `§5.2` lo explica: sobre una tabla que nadie puede actualizar, no hay migración correctiva posible. La ventana en la que esto es gratis está abierta exactamente hasta la primera migración de `1.6`.

---

## 7 · Consecuencias

**Buscadas**

- `REQ-BO-007` se cumple sin excepcionar `ADR-033`: el centro consulta lo que le afecta, y lo que ve lo decide el motor, no un `if` del controlador.
- El test de esquema #8 recupera cobertura real sobre `tenant_lifecycle_events`, que la había perdido sin que nadie lo supiera (`§4.2`).
- Dos fallos de *build* ciertos (`tenant_lifecycle_events`, `feature_flag_rules`) se evitan **antes** de escribirse, no se depuran después.
- Los datos personales del personal del proveedor dejan de ser alcanzables desde el *runtime* de un centro, cosa que ninguna de las dos revisiones daba por resuelta al empezar.
- Todo módulo futuro que necesite este patrón tiene una forma canónica que copiar y un test que le dice si la copió mal.

**Costes aceptados**

- **Tres columnas se renombran en la especificación** antes de existir. Coste real hoy: cero. Coste si se decidiera después: irreversible (`§5.2`).
- **Cada tabla de esta categoría necesita tres criterios de aceptación propios** de privilegios. La suite crece.
- **La lista de columnas del `GRANT SELECT` hay que verificarla, no suponerla.** Una lista de más filtra; una de menos rompe la consulta del centro con un error de privilegios. Es el mismo trabajo que `datos.md §7.1` ya reserva a `db-reviewer` para `module_subscriptions`, y aplica aquí sin descuento.
- **Estas tablas quedan fuera del ciclo *expand/contract* para siempre** (`§5.2`). Es una restricción real sobre `CLAUDE.md §9`, no una nota.
- **`feature_flag_rules` renombra su columna sin adoptar la categoría** (`§9`), lo que obliga a explicar en `datos.md §9.3` por qué tiene `affected_tenant_id` y no tiene política. Se acepta: la explicación es una frase y la alternativa era un fallo de *build*.

**Reversibilidad**

- La política y los privilegios: **muy reversibles**. Se quitan con una migración.
- La categoría en la taxonomía: reversible con un ADR nuevo, sin coste de datos.
- **Los nombres de columna: irreversibles en la práctica una vez escrita la primera migración**, por `§5.2`. Es la única parte de esta decisión que hay que acertar a la primera, y es la razón de su urgencia.

---

## 8 · Riesgo residual

`plataforma_app` conserva `SELECT` sobre un subconjunto de columnas de dos tablas que no son suyas. Es superficie que antes no existía, y la decisión la crea a sabiendas: sin ella, `REQ-BO-007` no se cumple. Lo que la acota es que la política falla en cerrado en los dos sentidos, que las columnas están enumeradas y que hay tests que lo comprueban. **No se elimina**, y si algún día `REQ-BO-007` se sirviera por otra vía —una API de plataforma que el centro consulte contra el *host* del backoffice, sin conceder nada a `plataforma_app`— sería legítimo revisar esta decisión con un ADR nuevo que la sustituya. Queda dicho ahora, no descubierto entonces.

---

## 9 · Alternativas descartadas y por qué

| Alternativa | Por qué se descarta |
|---|---|
| **Nombrar `tenant_id` a la referencia y enseñar a los tests una lista de exenciones** | Convierte dos guardarraíles de `INV-001` en listas que crecen con cada módulo. Es el mecanismo de erosión que `ADR-033 §10` existe para impedir, aplicado justamente a los tests que ese ADR llama «los que dan valor a tres años vista» |
| **`subject_tenant_id` como nombre de la columna** (propuesta inicial de `architect`) | `affected_tenant_id` ya estaba escrito y verificado en `admin_action_logs`, y un vocabulario de sinónimos por tabla reintroduce la ambigüedad. Un solo nombre, reconocible en revisión sin leer el predicado |
| **`GRANT SELECT` sobre la tabla completa** (propuesta inicial de `db-reviewer`) | RLS filtra filas, no columnas: expondría el motivo interno, el `context` con el nombre del empleado, y la IP y el agente de usuario del personal del proveedor al *runtime* de un centro |
| **Confiar la restricción de columnas solo al *endpoint*** | La superficie es el `GRANT`. Un *endpoint* correcto no protege de una consulta descuidada ni de una inyección, que es la clase de fallo contra la que existe el modelo de tres roles de `ADR-033 §5` |
| **Añadir `tenant_id` real y política `tenant_isolation` a `admin_action_logs`** | Haría de la fila propiedad del centro: se borraría con él, la escribiría su *runtime*, y la auditoría de las acciones del proveedor pasaría a depender del sujeto auditado. Es exactamente lo que `ADR-033 §7` evitó al clasificarla como de plataforma |
| **Política con `OR app.current_tenant_id() IS NULL`** para que las filas globales se vieran | Prohibido por `ADR-033 §5` y por buenos motivos: es la «comodidad» que desactiva el sistema entero de forma invisible. Que un centro no vea las acciones de alcance global es la respuesta correcta, no una limitación |
| **`FOREIGN KEY (session_id) … ON DELETE SET NULL`** en `platform_admin_sessions` | La fila referenciada no existe todavía en el momento del `INSERT` (`§5.1`): fallaría en cada inicio de sesión. Se sustituye por el barrido de huérfanas, que además escribe `ended_at` y `end_reason`, cosa que una clave foránea no puede hacer |
| **Adoptar la categoría también para `feature_flag_rules`** | `datos.md §9.6` razona bien que su contenido no es de ningún centro —es el catálogo de despliegue del producto— y que la barrera correcta está en el *endpoint*. Darle RLS sería aislar por tenant un dato que no es de ningún tenant. Solo se le aplica la convención de nombres de `§4.2`, que es lo que la saca de las ramas equivocadas de los dos tests |
| **Dejar todo esto escrito solo en `docs/modulos/REQ-BO/datos.md`** | Es una regla que vincula a los 53 módulos y estaría en la carpeta de uno. Mismo argumento por el que `OPEN-CORE-09` acabó siendo `ADR-038` y por el que `ADR-034` amplió `ADR-033 §7` con un ADR y no con una nota |

---

## 10 · Aprobación

`architect` entregó este documento en `PROPUESTA` porque `§5.1` decidía lo contrario de lo que se le había encargado: la instrucción de la sesión orquestadora era incorporar la clave foránea `platform_admin_sessions.session_id → platform_sessions.id ON DELETE SET NULL` «sin reabrirlo», y `§5.1` no reabre el argumento ya zanjado —el de `CASCADE`/`RESTRICT`, donde la resolución previa era correcta—, sino que aporta un tercer motivo, verificado en el código, que ningún revisor había puesto sobre la mesa: el orden de escritura hace que la fila referenciada no exista en el instante del `INSERT`, y con la clave foránea puesta ningún administrador de plataforma podría iniciar sesión. `CLAUDE.md §0` obliga a plantarse ante eso, no a ejecutarlo y anotar la discrepancia después.

La sesión orquestadora verificó la objeción directamente contra `App\Modules\Auth\Application\SessionRegistrationService::register()` (líneas 46-63): confirma que la fila de sesión de negocio se escribe dentro de una transacción durante la petición, antes de que el *driver* de Laravel escriba la fila de sesión al final de la petición. La objeción es correcta.

**El usuario aprobó el documento completo el 2026-09-08, incluida la decisión de `§5.1` de no poner la clave foránea.** El ADR queda `ACEPTADA` sin cambios sobre lo que `architect` entregó.

---

## 11 · Hallazgos fuera del alcance de este ADR, reportados y no corregidos

Conforme al issue [#150](https://github.com/pirexia/plataforma-educativa/issues/150): se señalan, no se arreglan.

1. **`REVOKE ALL ON <tabla>` no cubre la secuencia, y esto afecta a las trece tablas nuevas de `1.6`, no solo a las de este ADR.** `plataforma_app` conserva `USAGE, SELECT` sobre la secuencia de toda clave `bigserial` por los privilegios por defecto de `infra/containers/postgres/init/01-tenancy.sql.tpl`. Severidad **media**: fuga de cardinalidad y `nextval()` alcanzable desde el *runtime* de un centro. `§4.4` lo corrige para las tablas de esta categoría; `platform_sessions`, `platform_admin_sessions`, `dual_authorizations` y las del chasis necesitan lo mismo y quedan fuera de este ADR.
2. **`datos.md §2.6.3` no nombra la conexión del almacén de sesión de plataforma.** Debe fijar `session.connection = 'pgsql_platform'` y decir explícitamente que el *middleware* fija configuración **de sesión** y nunca la conexión de base de datos por defecto. Si el grupo de rutas de plataforma acabara corriendo entero sobre `pgsql_platform`, el `BYPASSRLS` quedaría puesto de fondo sin pasar por `runAsPlatform()`, y con él el enum de propósito, la prohibición de tenant activo y la comprobación de auditoría al cierre de `ADR-046 §6`. Es la única forma que se ha identificado de vaciar `ADR-046 §6` sin que nadie lo note; merece criterio de aceptación propio.
3. **`end_reason = 'caducidad'` de `platform_admin_sessions` no tiene escritor** (`§5.1`). Corregible dentro de `1.6` con el barrido; se señala aquí porque hoy la especificación no lo contempla en `datos.md`, `funcional.md` ni `operacion.md`.
4. **`feature_flag_rules` (`1.6e`) falla hoy el test de esquema #8** por llevar `tenant_id` literal sin RLS. La convención de `§4.2` lo resuelve, pero la corrección pertenece al sub-paso `1.6e`, no a este ADR.
5. **`datos.md §11` afirma que las trece tablas se declaran en `shared_tables.platform` «o el test #8 falla».** No era cierto para `tenant_lifecycle_events` (`§4.2`). Con la convención aplicada pasa a serlo.
