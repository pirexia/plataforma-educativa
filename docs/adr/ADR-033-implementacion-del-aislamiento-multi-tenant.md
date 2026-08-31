# ADR-033 · Implementación del aislamiento multi-tenant en Laravel y PostgreSQL

**Estado**: ACEPTADA (2026-08-17, implementada en el paso 0.7 — núcleo multi-tenant)
**Fecha**: 2026-08-17
**Concreta**: `ADR-001` (estrategia), `ADR-014` (resolución del tenant)
**Afecta a**: `INV-001`, `RNF-MANT-006`, `RMT-001`, `RMT-002`, `RMT-008`, `RMT-009`, `REQ-BO`, `REQ-SUP`, sección 16 del documento de requisitos
**No sustituye a ningún ADR anterior.**

---

## Contexto

`ADR-001` decidió *qué*: base de datos compartida, columna `tenant_id`, seguridad a nivel de fila. `ADR-014` decidió *de dónde sale* el tenant: del subdominio, en un middleware previo a cualquier acceso a datos. Ninguno de los dos dice *cómo* se implementa, y el cómo es donde este patrón falla en la práctica.

El paso 0.7 del plan abre el núcleo multi-tenant y el 0.8 escribe las migraciones de las entidades núcleo. Varias de las decisiones que siguen (claves foráneas compuestas, rol de conexión, tablas compartidas) tienen que estar tomadas **antes** de la primera migración de negocio: retrofitearlas sobre 50 tablas es una migración de esquema masiva, no un refactor.

El propio `ADR-001` admite el riesgo: *"el aislamiento pasa a depender de la disciplina del código"*. Este ADR existe para reducir cuanto sea posible la superficie de esa disciplina, sustituyéndola por mecanismos que fallan en cerrado y por comprobaciones automáticas en CI.

Cinco problemas concretos que hay que resolver:

1. Dónde vive el "tenant actual" durante la petición, y qué ocurre en los contextos **sin petición HTTP**: jobs en cola, comandos de consola, tareas programadas, listeners y seeders. El worker de colas es un proceso de larga vida que procesa jobs de tenants distintos en secuencia; es el vector de fuga más probable de todo el sistema.
2. Un *global scope* de Eloquent se desactiva con `withoutGlobalScope()`, que es API pública y no se puede quitar. Y no cubre en absoluto `DB::table()`, `DB::select()`, `whereRaw` ni una consulta lanzada desde `tinker`.
3. La variable de sesión de PostgreSQL que alimenta las políticas RLS se fija sobre una conexión que puede reutilizarse. Si un día se pone PgBouncer en modo *transaction pooling* delante, la conexión vuelve al pool conservando el ajuste y el siguiente tenant lo hereda. Es el fallo clásico de este patrón y es silencioso.
4. Redis es compartido: caché, colas y limitación de tasa pueden filtrar entre tenants aunque la base de datos sea impecable.
5. `RNF-MANT-006` exige tests de aislamiento en cada pipeline. Hoy la suite corre sobre SQLite en memoria (`apps/api/phpunit.xml`), que **no tiene RLS**. Cualquier test de aislamiento escrito hoy validaría el scope de Eloquent y daría verde aunque las políticas RLS no existieran.

---

## Opciones consideradas

### A. Librería de terceros (`stancl/tenancy` o `spatie/laravel-multitenancy`)

`stancl/tenancy` está orientada a multi-base y multi-schema; su modo de base única existe pero arrastra un ciclo de vida propio, *bootstrappers* que reconfiguran el contenedor y una superficie de API grande. `spatie/laravel-multitenancy` es mucho más pequeña y está bien mantenida, y resuelve razonablemente el conmutado de tenant y el paso de tenant a los jobs.

Es una decisión más ajustada de lo que parece, pero ninguna de las dos hace lo que aquí importa: ninguna implementa RLS, ninguna falla en cerrado cuando no hay tenant, ninguna verifica el esquema, y el modelo `Tenant` acaba siendo nuestro de todos modos. Se adoptaría una dependencia para quedarse con la mitad del trabajo hecho y la mitad crítica sin hacer.

### B. Solo scope global en Eloquent

Lo mínimo que cumple la letra de `ADR-001`. Coste bajísimo. Deja fuera toda consulta cruda y depende de que nadie escriba `withoutGlobalScope()`. Con datos de menores de varios colegios en la misma tabla, apostar el aislamiento a que nadie se equivoque nunca en tres años no es una opción defendible.

### C. Solo RLS en PostgreSQL

Aislamiento real e inevitable, incluso desde `psql`. Pero deja al desarrollador sin ayuda: los `INSERT` no rellenan `tenant_id` solos, un registro de otro tenant devuelve "no existe" de forma indistinguible de un error de lógica, y el planificador de consultas no siempre elige el índice que elegiría con el predicado explícito.

### D. Ambas capas, con RLS como barrera primaria y Eloquent como capa de ergonomía, más verificación automática del esquema y del código en CI

Es la opción que se recomienda. El matiz de "cuál es la primaria" no es retórico: determina qué se prueba y qué se considera un fallo grave.

---

## Decisión

### 1. Reparto de responsabilidades entre capas

| Capa | Papel | Qué pasa si falla |
|------|-------|-------------------|
| **RLS en PostgreSQL** | **Barrera primaria.** Nadie ve filas de otro tenant, ni por Eloquent, ni por `DB::table()`, ni por SQL crudo, ni desde una consola | Fuga. Incidente crítico. Hay test dedicado |
| **Scope global de Eloquent** | Ergonomía y rendimiento: rellena `tenant_id` al insertar, añade el predicado indexado, da semántica 404 | Sin fuga: RLS sigue en pie. Degrada a consultas peores y a errores confusos |
| **Claves foráneas compuestas** | Impide referenciar una fila de otro tenant | Registros con referencias cruzadas. RLS **no** protege aquí (ver punto 6) |
| **Tests de esquema y de arquitectura en CI** | Impide que una tabla o un modelo nuevos se salten el sistema | El sistema se erosiona tabla a tabla sin que nadie lo note |

Se rechaza explícitamente la formulación habitual "RLS como red de seguridad del scope". Es al revés.

### 2. Resolución del tenant y contexto de petición

Middleware `ResolveTenant`, **primero** del grupo `api` y del grupo `web`, antes de autenticación y de sesión:

```php
final class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = TenantHost::slugFrom($request->getHost());   // subdominio o dominio propio

        $tenant = $this->tenants->findActiveBySlug($slug)   // conexión de plataforma, cacheada
            ?? throw new TenantNotResolved($slug);           // → 404, nunca 400 ni redirección

        $this->context->enter($tenant);                      // fija GUC + prefijo de caché

        return $next($request);
    }
}
```

Reglas fijadas:

- El `tenant_id` **nunca** procede de la entrada del usuario. No hay parámetro de ruta, ni cabecera, ni campo de formulario que lo transporte. Si aparece en un `FormRequest`, es un fallo de revisión.
- Tenant inexistente → **404**. Tenant suspendido (`REQ-BO-001`) → **503** con el mensaje configurado. Nunca se revela cuáles existen.
- La sesión guarda el `tenant_id` con el que se autenticó y se **reverifica** contra el tenant resuelto en cada petición. Discrepancia → sesión invalidada y auditada.
- **La cookie de sesión es *host-only***: se emite sin dominio principal, ligada al subdominio exacto. Esto cumple `RMT-009` (nunca sesión simultánea con datos mezclados) por construcción y evita que una cookie de `centroa.dominio` viaje a `centrob.dominio`. Es compatible con `ADR-025` porque, según `ADR-028`, la SPA y la API se sirven **bajo el mismo host** y Traefik enruta `/api/*`; no hay subdominio `api.` separado.

### 3. `TenantContext`: fallo en cerrado

Objeto singleton con tres operaciones y un estado por defecto que es *ausencia de tenant*:

```php
$context->enter(Tenant $t): void;      // fija GUC de PG y prefijo de caché
$context->leave(): void;               // limpia ambos
$context->runFor(Tenant $t, Closure $c): mixed;  // guarda, ejecuta, restaura siempre
$context->tenantId(): int;             // lanza TenantContextMissing si no hay
```

`tenantId()` **lanza excepción** cuando no hay tenant. No devuelve `null`, y ningún camino de código lo convierte en "sin filtro". Un job mal escrito revienta en vez de leer la tabla entera.

### 4. Scope global y modelo base

- Todo modelo Eloquent de negocio extiende `App\Support\Tenancy\TenantModel`, que aplica el trait `BelongsToTenant`: *global scope* de lectura, relleno de `tenant_id` en `creating`, y prohibición de cambiar `tenant_id` en `updating`.
- `withoutGlobalScope(TenantScope::class)` queda **prohibido en todo el código de módulos**. Cuando de verdad hace falta ámbito de plataforma, se usa `TenantContext::runAsPlatform()`, que conmuta a la conexión de plataforma (punto 5) y deja rastro en el registro de auditoría de plataforma.
- La prohibición no se confía a la revisión: hay un test de arquitectura en Pest que falla si la cadena aparece fuera de una lista de excepciones explícita, y otro que falla si un modelo bajo `app/Modules/**` no extiende `TenantModel` sin estar en el registro de modelos compartidos.

Que el scope sea desactivable deja de importar cuando desactivarlo (a) rompe el build y (b) no sirve de nada, porque RLS sigue filtrando por debajo.

### 5. RLS: roles, variable de sesión y políticas

**Tres roles de base de datos, no uno.** Es la parte de la decisión que más aisla y la que más se suele omitir:

| Rol | Uso | Atributos |
|-----|-----|-----------|
| `plataforma_owner` | Propietario del esquema. Solo migraciones | Sin `SUPERUSER`. Sujeto a RLS por `FORCE` |
| `plataforma_app` | **Runtime de la API y de los workers** | Sin `SUPERUSER`, **sin `BYPASSRLS`**. Solo DML |
| `plataforma_platform` | Backoffice (`REQ-BO`) y mantenimiento entre tenants | `BYPASSRLS`. Credenciales separadas, conexión `pgsql_platform` |

El acceso entre tenants es un **rol y una conexión distintos**, no una bandera en una variable de sesión. Una bandera la puede activar cualquier ruta de código; unas credenciales distintas, no. Además encaja con que `REQ-BO` sea una aplicación aparte.

Variable de sesión, fijada **con parámetro ligado** (`SET` no admite *bind*, `set_config` sí):

```php
DB::statement("select set_config('app.tenant_id', ?, false)", [(string) $tenantId]);
```

El tercer argumento `false` la hace de sesión, no de transacción. Se reaplica en tres momentos: al entrar en un tenant, al establecerse una conexión (`Illuminate\Database\Events\ConnectionEstablished`) y al reconectar. Como PHP-FPM no usa conexiones persistentes, cada petición fija la suya antes de la primera consulta.

Función auxiliar, en esquema propio y propiedad del *owner*:

```sql
CREATE SCHEMA IF NOT EXISTS app;

CREATE OR REPLACE FUNCTION app.current_tenant_id() RETURNS bigint
    LANGUAGE sql STABLE PARALLEL SAFE
AS $$ SELECT nullif(current_setting('app.tenant_id', true), '')::bigint $$;
```

El `nullif(..., '')` importa: si la variable nunca se fijó, `current_setting` con `missing_ok = true` devuelve `NULL`; si se limpió, devuelve cadena vacía, y `''::bigint` sería un error en tiempo de ejecución. Con `nullif` ambos casos dan `NULL`.

Política, idéntica en todas las tablas de negocio:

```sql
ALTER TABLE students ENABLE ROW LEVEL SECURITY;
ALTER TABLE students FORCE  ROW LEVEL SECURITY;

CREATE POLICY tenant_isolation ON students
    USING      (tenant_id = app.current_tenant_id())
    WITH CHECK (tenant_id = app.current_tenant_id());
```

Cuatro detalles no negociables:

- **`FORCE`** además de `ENABLE`: sin él, el propietario de la tabla se salta sus propias políticas.
- **`WITH CHECK`** además de `USING`: sin él se lee correctamente pero se puede *escribir* una fila en otro tenant.
- **Sin tenant, cero filas.** `tenant_id = NULL` es `NULL`, que no es verdadero. Queda **terminantemente prohibido** añadir `OR app.current_tenant_id() IS NULL` a una política: es la "comodidad" que desactiva el sistema entero de forma invisible.
- La columna lleva `DEFAULT app.current_tenant_id()`, de modo que un `INSERT` crudo por `DB::table()` que olvide la columna también acaba correcto.

Sobre tablas particionadas por curso académico (`ADR-029`), la política se define en la tabla padre y PostgreSQL 17 la propaga a las particiones.

**PgBouncer en modo `transaction` queda prohibido** mientras rija este ADR. Con *pooling* por transacción, una variable de sesión sobrevive a la devolución de la conexión al pool y el siguiente tenant la hereda: fuga total y silenciosa. Si en E2 hace falta *pooling*, las salidas son modo `session`, o migrar a `set_config(..., true)` con transacción obligatoria en toda petición. Cualquiera de las dos exige **ADR nuevo**.

### 6. Claves foráneas compuestas

RLS **no** protege la integridad referencial: la comprobación de una clave foránea la ejecuta el sistema saltándose las políticas. Sin más medidas, una matrícula del centro A puede apuntar a un grupo del centro B y la base de datos lo acepta.

Por tanto, en toda tabla de negocio:

```sql
-- en el padre
UNIQUE (tenant_id, id)

-- en el hijo
FOREIGN KEY (tenant_id, group_id) REFERENCES groups (tenant_id, id)
```

Coste: un índice único adicional por tabla. Beneficio: la referencia cruzada entre tenants pasa a ser imposible, no improbable. Se decide ahora porque añadirlo después de 0.8 significa reescribir todas las claves foráneas del núcleo.

Se mantiene `ADR-029`: las claves foráneas usan la clave interna `bigint`; `public_id` es solo de exposición.

### 7. Tablas compartidas

Una tabla es **de tenant por defecto**. Ser compartida exige declararla explícitamente en `config/tenancy.php`, y el test de esquema falla si aparece en la base una tabla que no tiene `tenant_id` con RLS activo ni figura en ese registro.

| Categoría | Tablas | Tratamiento |
|-----------|--------|-------------|
| Raíz del aislamiento | `tenants` | Política propia: `USING (id = app.current_tenant_id())`. El alta y el listado van por la conexión de plataforma |
| Plataforma | `platform_admins`, `admin_action_logs`, `plans`, `failed_jobs` | Sin `tenant_id`. `REVOKE` completo para `plataforma_app` salvo lo imprescindible |
| Infraestructura del framework | `migrations`, `job_batches`, `cache` | Fuera del sistema. La caché real va a Redis |
| Catálogos de referencia | CCAA, países, currículo oficial | Solo lectura, sin `tenant_id`, `GRANT SELECT` |

`AuditLog` **es de tenant** y lleva RLS, con la excepción de las acciones de personal de plataforma, que van a `admin_action_logs` (`REQ-BO-007` exige registro independiente y no mezclado).

### 8. Contextos sin petición HTTP

- **Colas**: `Queue::createPayloadUsing()` estampa el `tenant_id` en **todos** los jobs automáticamente; no hay que acordarse de nada ni heredar de ninguna clase. Un listener de `JobProcessing` entra en el tenant, y `JobProcessed` / `JobFailed` / `JobExceptionOccurred` **siempre** salen. `Queue::looping()` comprueba que el contexto está limpio antes de tomar el siguiente job y aborta el worker si no lo está: preferimos un worker caído a un job que lee el tenant anterior.
- **Comandos de consola y tareas programadas**: sin tenant por defecto. Un comando que toque datos de negocio usa el trait `RunsPerTenant`, que itera con `runFor()`. Sin él, la primera consulta lanza `TenantContextMissing`.
- **Seeders**: igual que los comandos. `REQ-SEED` genera por tenant.
- **Eventos y listeners**: los listeners en cola heredan el mecanismo de colas. Los síncronos corren dentro del contexto de quien los dispara.

### 9. Redis y almacenamiento

| Recurso | Segregación |
|---------|-------------|
| Caché | Prefijo `t{tenant_id}:` fijado en `enter()`. Como el almacén se resuelve una vez y guarda su prefijo, al cambiar de tenant se llama a `forgetDriver()` para que se reconstruya. Hay test de que la misma clave da valores distintos en dos tenants |
| Colas | Nombres de cola **compartidos**; el tenant viaja en el *payload*. No se crea una cola por tenant: no acota nada y hace ingobernable Horizon |
| Limitación de tasa | La clave incluye el tenant |
| Canales de difusión | Todo canal privado lleva el tenant en el nombre y se autoriza contra el tenant activo |
| Objetos S3 | Prefijo `tenants/{public_id}/…`, resuelto por un `FilesystemAdapter` propio, nunca componiendo la ruta a mano |

### 10. Tests de aislamiento (`RNF-MANT-006`)

**La suite pasa a ejecutarse sobre PostgreSQL real.** Se retira `DB_CONNECTION=sqlite` de `phpunit.xml` y se añade un servicio `postgres:17` al workflow `ci-api.yml`. Mantener SQLite para "ir rápido" significaría que la barrera primaria no se prueba nunca; y una suite que corre sobre un motor distinto al de producción es precisamente cómo se despliegan los fallos de RLS. Se acepta el coste (contenedor en CI, base de datos obligatoria en local, suite algo más lenta) a cambio de que los tests midan lo que dicen medir.

Batería mínima, en `tests/Feature/Tenancy/`, cada test referenciando `INV-001` y `RNF-MANT-006`:

| # | Qué prueba | Por qué existe |
|---|-----------|----------------|
| 1 | Autenticado en A, ningún endpoint devuelve, cuenta ni modifica datos de B; el `public_id` de B da **404** | El caso de uso |
| 2 | `DB::select('select * from students')` con contexto A devuelve solo filas de A | **Prueba RLS.** Es el que falla si alguien quita las políticas |
| 3 | `UPDATE`/`INSERT` con `tenant_id` de B → rechazado por `WITH CHECK` | Escritura cruzada |
| 4 | Sin contexto: consulta cruda → 0 filas; consulta por Eloquent → `TenantContextMissing` | Fallo en cerrado |
| 5 | Job de A seguido de job de B en el mismo worker: ninguno ve datos del otro; contexto limpio entre ambos | El vector más probable |
| 6 | Misma clave de caché en A y en B → valores distintos | Fuga por Redis |
| 7 | Clave foránea con padre de otro tenant → rechazada | Punto 6 |
| 8 | **Esquema**: toda tabla tiene `tenant_id` + RLS `ENABLE` + `FORCE` + política, o está en el registro de compartidas | Impide la erosión con cada módulo nuevo |
| 9 | **Código**: todo modelo de módulo extiende `TenantModel`; `withoutGlobalScope` no aparece fuera de la lista de excepciones | Convierte disciplina en build roto |
| 10 | `SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user` → ambos falsos | Detecta que `.env` apunta al rol equivocado, que anula RLS entera sin ningún síntoma |

Los tests 8, 9 y 10 son los que dan valor a tres años vista: los otros siete prueban el código de hoy, estos tres impiden que el sistema se degrade sin que nadie se dé cuenta.

---

## Motivo

La justificación de fondo es la asimetría del coste del error. Una fuga entre tenants expone datos personales de menores de un colegio a otro colegio: es incidente crítico, notificable a la AEPD, y con el segmento objetivo (centros concertados que se conocen entre sí) el daño comercial es terminal. Frente a eso, el coste de este diseño es del orden de dos a tres días de implementación y un puñado de milisegundos por consulta.

La elección de RLS como barrera primaria se sostiene en que es la única capa que no se puede olvidar: cubre el SQL crudo, los informes de `REQ-BI`, un script de migración de `REQ-ONB`, una sesión de `tinker` en producción a las tres de la mañana. Cualquier mecanismo que viva solo en PHP se salta escribiendo una línea de PHP distinta.

Los tres roles de base de datos y los tests 8-10 son la parte que un desarrollador en solitario más agradece a los dos años: no dependen de recordar nada. El resto del diseño se apoya en fallo en cerrado por el mismo motivo — cuando el sistema se rompe, tiene que dejar de funcionar de forma ruidosa, no seguir funcionando de forma permisiva.

Se descarta la librería de terceros porque resolvería la mitad menos crítica del problema a cambio de una dependencia con ciclo de actualización ajeno en un componente que toca todas las consultas del sistema. `RNF-MANT-007` obligaría a envolverla en una interfaz propia; a ese punto, la interfaz propia sin la librería debajo es menos código y totalmente comprensible.

---

## Consecuencias

**A favor**

- `INV-001` deja de depender de la disciplina del código en su mayor parte, que era la consecuencia declarada como problemática en `ADR-001`.
- El aislamiento se sostiene frente a SQL crudo, informes y acceso administrativo.
- Una tabla o un modelo nuevos que se salten el sistema rompen el build, no la producción.
- Los tests 8 y 9 dan a `RNF-MANT-006` un cumplimiento verificable, no declarativo.

**En contra, y se asume**

- Cada tabla de negocio suma un índice único `(tenant_id, id)` y una política. Coste de almacenamiento y de escritura pequeño pero real.
- RLS añade un predicado a cada consulta. Con los índices liderados por `tenant_id` (sección 16.3, regla 2) el impacto medido en cargas equiparables está en el rango del 1-3%; se **mide** en el paso 0.8, no se da por bueno.
- La suite de tests requiere PostgreSQL. Se acabó `php artisan test` sin contenedores. CI gana un servicio.
- Provisionar la base de datos deja de ser `CREATE DATABASE`: hay tres roles, un esquema `app` y una función. Va documentado en `SYSADMIN.md` y automatizado en una migración inicial.
- **PgBouncer en modo transacción queda vetado** hasta que un ADR nuevo lo resuelva. Restricción real sobre la etapa E2 de `ADR-024`, y es mejor tenerla escrita ahora que descubrirla el día que se instale.
- Las migraciones con relleno de datos han de ser conscientes del tenant, por `FORCE ROW LEVEL SECURITY`.

**Reversibilidad**

Desigual, y conviene tenerlo presente:

- Políticas RLS, roles, scope, middleware, contexto: **muy reversibles**. Se quitan con una migración y un par de ficheros.
- Claves foráneas compuestas y la presencia de `tenant_id` en cada tabla: **poco reversibles** una vez escrito el esquema del núcleo. Por eso se deciden en 0.7 y no en 0.8.
- La cookie de sesión *host-only*: reversible, pero cambiarla después invalida todas las sesiones activas.

---

## Alternativas descartadas y por qué

- **`stancl/tenancy`**: pensada para multi-base y multi-schema; su modo de base única arrastra un ciclo de vida propio y una superficie grande para lo que aquí se necesita. `ADR-001` ya descartó multi-schema.
- **`spatie/laravel-multitenancy`**: pequeña y bien mantenida, decisión ajustada. Descartada porque no cubre RLS, ni el fallo en cerrado, ni la verificación de esquema — es decir, ninguna de las tres piezas que hacen falta — y el modelo `Tenant` sería nuestro igualmente.
- **Solo scope global de Eloquent**: cumple la letra de `ADR-001` y deja sin cubrir todo el SQL que no pasa por Eloquent. Insuficiente para datos de menores de varios centros en la misma tabla.
- **Solo RLS**: aísla, pero no rellena `tenant_id` al insertar ni da semántica 404, y empeora los planes de consulta. La capa de Eloquent cuesta poco y evita esos tres problemas.
- **Bandera de "modo plataforma" en la variable de sesión** en lugar de un rol con `BYPASSRLS`: cualquier ruta de código puede activarla, incluida una inyección en un `set_config`. Un rol distinto con credenciales distintas es una frontera de verdad.
- **`SET LOCAL` con transacción obligatoria en toda petición**: sería la opción correcta *si* hubiera PgBouncer en modo transacción. Envolver cada lectura en una transacción por un componente que hoy no existe es pagar complejidad por adelantado. Queda documentada como la salida prevista si algún día se necesita.
- **Una cola de Redis por tenant**: no aporta aislamiento sobre el tenant en el *payload* y hace ingobernable Horizon a partir de una decena de centros.
- **Mantener SQLite en los tests por velocidad**: dejaría la barrera primaria sin probar y haría que los tests de aislamiento diesen verde con las políticas RLS ausentes. Es peor que no tenerlos, porque genera confianza infundada.
- **Base de datos por tenant**: descartada en `ADR-001`, no se reabre aquí.
