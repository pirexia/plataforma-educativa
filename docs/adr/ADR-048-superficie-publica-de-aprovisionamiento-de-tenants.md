# ADR-048 · Superficie pública de aprovisionamiento de tenants: contrato síncrono en `REQ-CORE`, no evento de `REQ-BO`

**Estado**: **ACEPTADA en cuanto al mecanismo** (decisión de `architect`, 2026-09-11, tomada sobre el código real y no sobre las dos opciones planteadas a ciegas). **Pendiente de la ratificación que `OPEN-BO-15` reservaba al usuario**, que no es el mecanismo sino el permiso: que un sub-paso de `REQ-BO` (`1.6b`) escriba código dentro de `REQ-CORE`. `§10` enumera exactamente qué se toca de `REQ-CORE` y qué no.

**Fecha**: 2026-09-11

**Resuelve**: `OPEN-BO-15` (`docs/modulos/REQ-BO/funcional.md §14`), abierta por `spec-writer` al especificar `1.6b` · **y cierra, con la misma decisión, un agujero del mismo tipo que la especificación no había nombrado**: la clonación de `§5.6` copia `tenant_settings`, `roles` y `permission_role` —tres tablas de `REQ-CORE`— y, tal como estaba escrita, obligaba a `REQ-BO` a leerlas y escribirlas directamente (`§1.2`).

**Concreta**: `INV-007` (un módulo no importa código interno de otro), `INV-012` (tareas pesadas en colas), `REQ-BO-001`, `REQ-CORE-001`, `RN-BO-53`

**Se apoya en**: `ADR-002` (monolito modular: la frontera entre módulos es de código, no de red), `ADR-045 §4.8` (todo evento del ciclo de vida de un módulo pertenece a `REQ-CORE`) y `ADR-045 §4.5`/`RN-BO-22` (una sola implementación en un servicio de dominio de `REQ-CORE`, consumida por todos los caminos), `ADR-046 §7.1` (que descartó el despliegue aparte precisamente para no duplicar ese servicio), `ADR-034 §2`, `ADR-038 §7.2` (ampliar un cuerpo es cambio compatible), `CLAUDE.md §7` (invariantes)

**Afecta a**: el sub-paso **`1.6b`** (`REQ-BO-001`: alta y clonación de tenants) de forma inmediata, y al módulo **`REQ-CORE`**, cuya superficie pública gana un contrato. Fija además el patrón para **`1.6c`** (contratación de módulos), **`1.24`** (`REQ-ONB`, que reutilizará el mismo aprovisionamiento) y para cualquier módulo futuro que necesite pedirle a otro una operación cuyo **resultado** le importa.

**No sustituye a ningún ADR anterior.** No toca `ADR-002`, `ADR-045`, `ADR-046` ni `ADR-047`. En particular **no reabre `ADR-045 §4.1`**: `module_subscriptions` lo sigue escribiendo sólo el backoffice, y `§4.5` de este ADR dice por qué eso no entra en el contrato.

---

## 1 · Contexto

### 1.1 · La pregunta, tal como la dejó `spec-writer`

`RN-BO-53` obliga a que el backoffice **pida** el aprovisionamiento a `REQ-CORE` por interfaz pública en vez de implementarlo. `funcional.md §5.3.4` constata que la interfaz que existe hoy no acepta la configuración inicial del centro y que hay que ampliarla. `OPEN-BO-15` no preguntaba *cómo* —daba por hecho un método—, sino si se aprueba **tocar otro módulo desde un paso de éste**.

Al llevar la pregunta al usuario se le plantearon dos opciones —interfaz síncrona ampliada o evento de dominio— sin haber leído el código. El usuario, correctamente, se negó a elegir a ciegas. Este ADR decide sobre lo verificado.

### 1.2 · Estado real verificado en el repositorio, no supuesto

Verificado el 2026-09-11 sobre `feature/REQ-BO-1.6b-ciclo-vida-tenants` (`1c68f7a` en `develop`, sin código de `1.6b` todavía: sólo especificación).

**Cómo consume hoy un módulo a `REQ-CORE`, que es el precedente que manda:**

- `REQ-AUTH` consume `REQ-CORE` **siete veces** y siempre por lo mismo: una **interfaz declarada en `App\Modules\Core\Domain`**, enlazada en `CoreServiceProvider::register()` a una implementación `Eloquent*` de `Core\Infrastructure`. Son `TenantSettingsReader` (5 llamadores), `UserDirectory` (3) e `InvitationRedeemer` (1). Ninguna importación de `Core\Application` ni de `Core\Domain\Models` desde otro módulo. `REQ-CORE/funcional.md §7` las declara explícitamente como «interfaces públicas que `REQ-CORE` expone en su `Domain`».
- El **evento de dominio** se usa en la dirección contraria y para otra cosa: `REQ-AUTH` escucha `TenantSettingsUpdated`, `UserDeactivated` y `RoleMfaRequirementChanged` para **reaccionar a un hecho consumado**. Ningún evento del repositorio manda hacer nada a nadie, ni devuelve resultado a quien lo emite.
- `App\Support\*` (incluidos `Tenant`, `TenantContext`, `PlatformAccessPurpose`, `ModuleAvailability`) es núcleo compartido: usarlo desde cualquier módulo no es cruzar una frontera.

**El aprovisionamiento, hoy:**

- `App\Modules\Core\Application\ProvisionTenantDefaults` — clase **concreta y `final`**, sin interfaz. Firma: `provision(Tenant $tenant, string $adminEmail, string $adminGivenName, string $adminFamilyName): void`.
- Su único llamador es `Core\Infrastructure\Console\ProvisionTenantDefaultsCommand` (`tenant:provision-defaults`), dentro del propio módulo. Su *docblock* dice literalmente: «*1.6 lo envolverá en el alta del backoffice*».
- Lo que hace, dentro de `TenantContext::runFor()` + `AuditActor::actingAs('console')` + `DB::transaction()`: `TenantSetting::create([])` **vacío**, 16 roles, la matriz de concesiones, una `Person`, un `User` en `pendiente`, el evento `UserCreated` y la invitación.
- **Es idempotente**, confirmado: `if (TenantSetting::query()->exists()) { return; }` antes de escribir nada. Esa propiedad es la que sostiene `bo:retry-provisioning` y `CA-BO-108`, y no se puede perder.
- `tenant_settings` tiene valores por defecto en el esquema (`default_locale='es-ES'`, `active_locales='["es-ES"]'`, `timezone='Europe/Madrid'`, `currency='EUR'`, `fiscal_country_code='ES'`) y `autonomous_community` **es anulable**. Por eso «crear la fila vacía» funciona hoy: el centro nace en español peninsular tanto si lo es como si no.
- `createAdministrator()` escribe `'locale' => 'es-ES'` **literal**. Y `IssueUserInvitation::issue()` calcula el idioma del correo como `$user->person->locale ?? $this->settings->defaultLocale()`: como `person.locale` nunca es nulo por esa vía, **el `??` no se ejecuta jamás y la invitación del primer administrador sale siempre en español**, aunque el centro sea alemán. Hoy es inevitable —la configuración no llega al aprovisionamiento—; con este ADR deja de serlo.

**La clonación, que la especificación no había clasificado como el mismo problema:**

- `funcional.md §5.6.2` copia del origen: `tenant_settings` (parte), `roles`, `permission_role` y `module_subscriptions`. Las **tres primeras son tablas de `REQ-CORE`** (`Core/Database/migrations`, modelos en `Core\Domain\Models` y `App\Models`).
- `operacion.md §6.1` asigna el trabajo `CloneTenant` a `REQ-BO`. Tal como está escrito, ese trabajo tendría que consultar y escribir `tenant_settings`, `roles` y `permission_role` desde `REQ-BO`: **exactamente la infracción de `INV-007` que `RN-BO-53` prohíbe para el alta**, tres secciones más abajo y sin que nadie lo hubiera nombrado.
- `§5.6.4` exige además que la lectura del origen sea **un único punto en el tiempo, dentro de una transacción**. Eso descarta que `REQ-BO` lea la plantilla y se la pase a `REQ-CORE` como datos: entre la lectura y la escritura habría una ventana, y `REQ-BO` seguiría leyendo tablas ajenas.

### 1.3 · Por qué esto merece un ADR y no un párrafo de especificación

Porque no decide cómo se escribe un método, sino **qué forma tiene la frontera entre dos módulos cuando uno necesita que el otro haga algo y le importa el resultado**. Es la primera vez que este proyecto lo necesita: hasta ahora, o se leía un dato (interfaz de consulta), o se notificaba un hecho (evento). Lo que se decida aquí lo copiarán `1.6c`, `1.24` y los 50 módulos que faltan, igual que `ADR-045 §4.8` fijó el patrón de los eventos de módulo.

---

## 2 · Qué NO decide este ADR

1. **No decide `OPEN-BO-14` ni `OPEN-BO-16`**. Siguen abiertas y son del usuario.
2. **No cambia el alcance de `1.6b`** ni añade endpoints, columnas, permisos ni capacidades. Ninguna migración sale de aquí.
3. **No reabre `ADR-045 §4.1`**: `module_subscriptions.enabled` lo sigue escribiendo sólo el backoffice, por `pgsql_platform`. `§4.5` explica por qué queda fuera del contrato.
4. **No decide dónde vive la resolución de dependencias de módulos** (`RN-BO-22`): eso ya está decidido y es de `1.6c`.
5. **No arregla nada de lo que encuentra fuera de su alcance.** `§11` lo reporta.

---

## 3 · Opciones reales

No las teóricas: las que se pueden escribir sobre este código esta semana.

| # | Opción | En qué consiste |
|---|---|---|
| **A** | **Contrato síncrono en `Core\Domain`** | `REQ-CORE` declara una interfaz pública de aprovisionamiento; `REQ-BO` la inyecta en su trabajo en cola y la invoca. La asincronía la pone el trabajo de `REQ-BO`, no el contrato |
| **B** | **Evento de dominio emitido por `REQ-BO`** | `REQ-BO` emite `TenantCreated` con la configuración inicial en la carga; un *listener* de `REQ-CORE` aprovisiona |
| **C** | **`REQ-BO` escribe `tenant_settings` por su cuenta** y llama al aprovisionamiento actual sin tocarlo | Sin ampliar nada de `REQ-CORE` |
| **D** | **Bus de comandos** genérico (`CommandBus`, mensajes con *handlers* registrados) | Infraestructura nueva compartida; el aprovisionamiento sería su primer mensaje |
| **E** | **`REQ-BO` invoca el comando de consola** (`Artisan::call('tenant:provision-defaults', …)`) | Sin contrato nuevo: la «interfaz» es la firma del comando |

Evaluación contra los cuatro criterios de rigor (coste en solitario, mantenimiento a 3 años, impacto en invariantes, reversibilidad):

| | A · contrato | B · evento | C · escritura propia | D · bus | E · consola |
|---|---|---|---|---|---|
| **Coste de implementación** | Bajo. Una interfaz, dos objetos de valor, un enlace en el *provider*, `implements` en la clase que ya existe | Medio. Evento, *listener*, registro, y **además** hay que resolver cómo vuelve el resultado | Aparentemente el más bajo | Alto. Infraestructura nueva sin ningún otro caso que la pida | Bajo |
| **Mantenimiento a 3 años** | El patrón que ya usan 9 consumidores. Un desarrollador nuevo no aprende nada | El único evento del sistema que **ordena** en vez de **notificar**: una excepción al patrón, y las excepciones se copian | Dos implementaciones del mismo aprovisionamiento que divergen en cuanto alguien toque una | Una capa más entre el llamador y el trabajo, para un solo mensaje | El acoplamiento va por cadenas de texto: ningún análisis estático lo ve |
| **Impacto en invariantes** | Cumple `INV-007` por construcción. `INV-012` lo cumple el trabajo en cola, que ya existe | `INV-007` **se cumple del revés**: `REQ-BO` pasaría a poseer el vocabulario del dominio de `REQ-CORE` (idiomas, moneda, CCAA) dentro de una clase de `Backoffice`. Contradice `ADR-045 §4.8` | **Viola `INV-007`** sin discusión | Cumple, con más piezas | Cumple la letra, no el fondo: la frontera pública es un `$signature` sin tipos |
| **Reversibilidad** | Alta. Interfaz con un implementador y dos llamadores; cambiarla es un *commit* | Baja. Un evento publicado es superficie que otros módulos empiezan a escuchar; retirarlo rompe a terceros | Media, pero deja datos escritos por el módulo equivocado | Media | Alta |

### 3.1 · Por qué **B** falla por algo más que por estilo

Tres fallos de fondo, no de gusto:

1. **El resultado no vuelve.** `funcional.md §5.3.3` fase 2 obliga a que, **al terminar sin error**, se escriba la transición `en_alta` → `activo`; y `§5.3.5`, a que si falla se escriba `tenant.aprovisionamiento_fallido` con **el error en `context`**. Un evento no devuelve valor y no propaga excepción al emisor: si el *listener* es en cola, el emisor no se entera de nada; si es síncrono, es una llamada a método con tipos peores. Habría que inventar un segundo evento de vuelta (`TenantProvisioned` / `TenantProvisioningFailed`) y un correlador — es decir, reconstruir a mano lo que una llamada síncrona ya da.
2. **El reintento se vuelve una mentira.** `bo:retry-provisioning` reencola **el mismo trabajo** (`operacion.md §5.1`). Con eventos habría que **reemitir `TenantCreated`**, que es un enunciado de hecho: el tenant no se ha vuelto a crear. Y lo recibiría todo *listener* futuro, no sólo el que falló.
3. **Es asincronía sobre asincronía.** `INV-012` ya está satisfecho por el trabajo `ProvisionTenant` de `REQ-BO`. Meter un evento dentro de un trabajo en cola no desacopla nada: añade un salto que no cambia ni el momento ni el hilo de ejecución.

**Dónde sí cabría un evento**, y por qué tampoco entra: ver `§4.6`.

### 3.2 · Por qué **E** es la trampa más fácil de tragar

«Llamar a un comando de consola no es importar código interno» es cierto de la letra y falso del fondo. Un comando es una interfaz de operador: argumentos como cadenas, errores como código de salida, sin tipos y sin poder devolver un objeto. Además `ProvisionTenantDefaults` escribe hoy con `AuditActor::actingAs('console')`, y `funcional.md §5.3.4` punto 2 exige justo lo contrario: que el alta del backoffice se audite como lo que es y la consola como `console`. Con **E** el actor sería siempre `console`, o habría que pasarlo por opción de línea de comandos.

---

## 4 · Decisión

**Opción A.** `REQ-CORE` declara un contrato público de aprovisionamiento en su `Domain`, y `REQ-BO` lo consume desde sus trabajos en cola. Concretamente:

### 4.1 · El contrato

```php
namespace App\Modules\Core\Domain;

interface TenantProvisioner
{
    public function provision(
        Tenant $tenant,
        TenantInitialSettings $settings,
        TenantAdministrator $administrator,
    ): TenantProvisioningOutcome;

    public function provisionFromTemplate(
        Tenant $source,
        Tenant $target,
        TenantAdministrator $administrator,
    ): TenantProvisioningOutcome;
}
```

`Tenant` es `App\Support\Tenancy\Tenant` (núcleo compartido), no un modelo de `REQ-CORE`: pasarlo no cruza ninguna frontera.

### 4.2 · Los dos objetos de valor, y por qué no son ocho parámetros sueltos

```php
final readonly class TenantInitialSettings   // App\Modules\Core\Domain
{
    // default_locale, active_locales, timezone, currency, autonomous_community
}

final readonly class TenantAdministrator     // App\Modules\Core\Domain
{
    // email, given_name, family_name
}
```

Dos motivos, ninguno estético:

1. **La firma va a crecer y no puede romperse cada vez.** Hoy son cinco ajustes y tres datos de persona; `funcional.md §5.3.1` ya nombra cuatro campos más que llegarán (dominio propio, plan, etapas y régimen). Con objetos de valor, cada llegada es una propiedad nueva con valor por defecto y **cambio compatible** (`ADR-038 §7.2`, aplicado aquí a un contrato de código en vez de a un cuerpo HTTP). Con parámetros sueltos, cada llegada rompe a los tres llamadores.
2. **Separar los dos objetos es lo que hace posible `provisionFromTemplate()`** sin duplicar nada: el clon no trae ajustes —los hereda del origen— pero sí trae administrador. Un único objeto «petición de aprovisionamiento» obligaría a un campo opcional que a veces es obligatorio, que es la forma habitual de que un contrato deje de decir la verdad.

**`autonomous_community` es anulable en el contrato** (`?string`), porque la columna lo es y porque el arranque por consola no tiene por qué inventarse una. Que en el alta por API sea **obligatoria** es regla de `REQ-BO` (`funcional.md §5.3.2` punto 4) y se comprueba en su `FormRequest`, no aquí (`§4.7`).

### 4.3 · El resultado: un enumerado de dos casos, no `void`

```php
enum TenantProvisioningOutcome { case Provisioned; case AlreadyProvisioned; }
```

**La idempotencia deja de ser silenciosa.** Hoy la segunda ejecución retorna sin decir nada y la única forma de observar «no hizo nada» es contar filas. `CA-BO-108` tiene que demostrar que reintentar un alta a medias no duplica roles, personas ni invitaciones, y `bo:retry-provisioning` tiene que poder decirle al operador si reparó algo o si no había nada que reparar.

Digo con honestidad lo que cuesta y lo que da: sus consumidores reales son **dos** —el mensaje del comando y el test de idempotencia—; el trabajo `ProvisionTenant` se comporta igual en los dos casos (transita `en_alta` → `activo` de todos modos, porque un tenant ya aprovisionado que sigue en `en_alta` es exactamente el caso que `bo:retry-provisioning` repara). Se acepta porque cuesta un enumerado de dos casos y porque es reversible sin coste: hay un solo implementador.

**El fallo se propaga como excepción, no como un tercer caso del enumerado.** El trabajo de `REQ-BO` la captura, escribe `tenant.aprovisionamiento_fallido` con el mensaje en `context` y deja que Laravel agote los reintentos (`funcional.md §5.3.5`). Un enumerado `Failed` obligaría a cada llamador a acordarse de mirarlo; una excepción no se puede ignorar por descuido.

### 4.4 · Dónde vive la implementación: `ProvisionTenantDefaults` **no se mueve ni se renombra**

`Core\Application\ProvisionTenantDefaults implements TenantProvisioner`, enlazado en `CoreServiceProvider::register()`:

```php
$this->app->bind(TenantProvisioner::class, ProvisionTenantDefaults::class);
```

Esto **se aparta a propósito** de la convención de los otros cinco contratos, cuyas implementaciones son `Eloquent*` en `Core\Infrastructure`. La convención `Eloquent*` marca un **adaptador de persistencia**: `EloquentUserDirectory` traduce una consulta. `ProvisionTenantDefaults` no es eso — es un servicio de aplicación que orquesta una transacción, un contexto de tenant y un actor de auditoría, y `Core\Application` es exactamente su sitio. Moverlo costaría: su nombre está citado en `REQ-CORE/funcional.md §4.7`, `REQ-BO/funcional.md §5.3.3` y `§5.3.4`, `REQ-BO/operacion.md §6.1`, el comando de consola y los tests, y además posee la constante `ROLE_ADMINISTRATION_PERMISSIONS` que `GrantRoleAdministrationCommand` reutiliza. **Renombrar cinco documentos y dos clases para que el sufijo case es cosmética pagada con riesgo.** Queda escrito aquí para que nadie lo «arregle» después sin saber que fue una decisión.

### 4.5 · La clonación entra en el contrato; `module_subscriptions`, no

`provisionFromTemplate()` copia, **dentro del contexto del tenant destino y en una sola transacción**, leyendo el origen de una vez (`funcional.md §5.6.4`):

- `tenant_settings`: **sólo los operativos** — idiomas, zona horaria, moneda, CCAA, `session_timeout_minutes`, `mfa_allowed_methods`, `mfa_grace_period_days`. **Nunca** identidad fiscal ni marca (`§5.6.2`). La lista de qué se copia vive en `REQ-CORE`, que es quien conoce esas columnas.
- `roles` y `permission_role`, **incluidos los roles personalizados** del origen.
- Y crea `Person`, `User` e invitación del primer administrador del clon, igual que `provision()`.

**`module_subscriptions` queda fuera y lo sigue copiando `REQ-BO`.** No es una excepción: `ADR-045 §4.1` decidió que el backoffice es el **único escritor** de esa tabla, por la conexión `pgsql_platform`. Meterla en el contrato de `REQ-CORE` revocaría esa decisión sin ADR que la sustituya, que es justo lo que `CLAUDE.md §11` prohíbe. La frontera queda así, y es coherente: **`REQ-CORE` es dueño de lo que el centro es; `REQ-BO` es dueño de lo que el proveedor le ha vendido.**

### 4.6 · **No** se emite ningún evento de dominio nuevo en `1.6b`

Y esto es un «no» deliberado, no un olvido. `ADR-045 §4.8` sí emitió `ModuleContracted` sin consumidor, y la comparación no aplica: allí el evento **era el mecanismo** con el que se cumplía un requisito del usuario («avisar al centro») sin construir `REQ-COM` trece pasos antes. Aquí no hay requisito que avisar de nada.

El hecho «este tenant ya está aprovisionado» **ya tiene dos registros duraderos**: la fila `en_alta` → `activo` de `tenant_lifecycle_events` y la de `admin_action_logs`. Un `TenantProvisioned` sin oyente sería una tercera fuente de verdad del mismo hecho, con el riesgo habitual: en cuanto alguien la escuche, habrá dos caminos por los que enterarse y uno de los dos se quedará atrás.

Cuando `REQ-ONB` (1.24) necesite engancharse, **añadir el evento es una línea en el proveedor y no necesita ADR**: el patrón ya está establecido por las once clases de `Modules/*/Domain/Events` y el evento será de `REQ-CORE`, no de `REQ-BO` (regla de `ADR-045 §4.8`). Lo reversible es no emitirlo hoy; lo irreversible es publicar superficie que otros empiezan a consumir.

**La tensión aparente con `api.md §7`, dicha en voz alta y no esquivada.** Ese apartado declara `TenantSuspended`, `TenantReactivated` y `TenantMarkedForClosure` **sin consumidor en `1.6`**, argumentando que `REQ-BKP` y `REQ-COM` los necesitarán. **Esa decisión no se reabre aquí** y no la contradigo: es de `REQ-BO` sobre eventos de `REQ-BO`, ya aprobada. Lo que digo es por qué el aprovisionamiento **no** es ese caso: las tres transiciones son actos de un operador cuyo momento sólo conoce el servicio de transición, mientras que «este tenant quedó aprovisionado» ya está escrito dos veces —`tenant_lifecycle_events` y `admin_action_logs`— por el mismo trabajo y en el mismo instante. La especificación, de hecho, **ya había decidido lo mismo sin decirlo**: en esa tabla de `api.md §7` no hay ningún evento para el alta.

### 4.7 · Quién valida qué, dicho antes de que se escriba

| Quién | Qué comprueba | Qué produce si falla |
|---|---|---|
| `REQ-BO`, en su `FormRequest` | Que los campos vengan, que `autonomous_community` sea obligatoria, el `slug` libre y no reservado, el formato de correo | `422` con clave de traducción (`api.md §2.4.1`, `§5`) |
| `REQ-CORE`, en el objeto de valor | Que los valores sean **coherentes**: idioma por defecto contenido en los activos, los cuatro de `ADR-021`, zona IANA existente, moneda `^[A-Z]{3}$`, CCAA del catálogo si no es nula | `InvalidArgumentException` |

**La excepción de `REQ-CORE` no es un `422` y no lleva clave de traducción**: llegar ahí con un valor inválido significa que el llamador no validó, es decir un defecto de programación, no un error del operador. Escribir un mensaje de usuario en `REQ-CORE` para ese caso pondría la superficie HTTP de `REQ-BO` dentro de `REQ-CORE` — el error simétrico al que este ADR evita.

**El catálogo `Core\Domain\AutonomousCommunity::CODES` es superficie pública de `REQ-CORE`** y `REQ-BO` puede importarlo para validar. Ya está documentado como «la lista de referencia única» en su propio *docblock*; queda declarado aquí para que no se discuta. Duplicar diecinueve códigos en `REQ-BO` sería peor que la dependencia.

### 4.8 · El comando de consola se conserva y pasa por el mismo contrato

`tenant:provision-defaults` deja de instanciar la clase concreta y pasa a inyectar `TenantProvisioner`, con **opciones nuevas para los ajustes iniciales**, cada una con el valor por defecto que ya tiene la columna (`es-ES`, `["es-ES"]`, `Europe/Madrid`, `EUR`, CCAA nula). Es la exigencia de `funcional.md §5.3.4` punto 2 —dos caminos, una sola implementación— y el mismo criterio de `RN-BO-22`.

Un centro arrancado por consola sigue pudiendo arrancarse sin teclear nada nuevo: los valores por defecto son los de hoy. Lo que ya no pasa es que el camino de la API no pueda decir otra cosa.

### 4.9 · Regla general que se deriva, y que `spec-writer` debe escribir

> **Cuando un módulo necesita que otro ejecute una operación cuyo resultado o cuyo fallo le importan, se hace por contrato síncrono declarado en el `Domain` del módulo propietario. El evento de dominio queda reservado a notificar hechos consumados, y nunca lleva de vuelta un resultado.** La asincronía, cuando haga falta por `INV-012`, la pone el llamador con su propio trabajo en cola — no el contrato.

---

## 5 · Decisiones puntuales de este ADR

### 5.1 · El idioma del primer administrador deja de ser `es-ES` literal

`createAdministrator()` pasa a escribir `person.locale = $settings->defaultLocale()`. Es consecuencia directa —una vez que el aprovisionamiento conoce el idioma del centro, codificar «español» es indefendible— y **arregla un defecto real verificado en `§1.2`**: hoy la invitación del primer administrador de un centro alemán sale en español, porque `IssueUserInvitation` sólo consulta `defaultLocale()` cuando `person.locale` es nulo, y nunca lo es por esta vía.

**Ordenación obligatoria dentro de la transacción**: `TenantSetting` se escribe **antes** de crear la `Person` y **antes** de emitir la invitación. Si se invierte, `TenantSettingsReader` leería la configuración por defecto y el correo volvería a salir en español sin que nada falle visiblemente.

### 5.2 · La comprobación de idempotencia no cambia de forma

Sigue siendo «¿existe ya `tenant_settings` en este tenant?» y sigue siendo lo primero. **No** se añade una columna de estado de aprovisionamiento: `ADR-034 OPEN-13` y `api.md §2.4.1` ya decidieron que `provisioning.state` se **deriva**, y este ADR no crea el dato que aquella decisión descartó.

### 5.3 · `provisionFromTemplate()` es idempotente por la misma comprobación

Un clon reintentado no duplica ni roles ni concesiones, por el mismo `exists()` sobre el destino. No hace falta un mecanismo propio y no se añade.

---

## 6 · Motivo

Tres frases, si hay que quedarse con tres:

1. **Es el patrón que este repositorio ya usa nueve veces**, y un patrón repetido nueve veces es más barato de mantener que uno nuevo mejor.
2. **El alta necesita saber si el aprovisionamiento salió bien**, porque de eso dependen una transición de estado y una fila de auditoría. Un evento no devuelve eso, y el andamiaje que haría falta para simularlo cuesta más que la llamada que se evita.
3. **Deja `REQ-CORE` como dueño único de su dominio.** Los idiomas, la moneda y la comunidad autónoma de un centro son vocabulario de `REQ-CORE`; en la opción del evento acabarían viviendo en una clase de `Backoffice`, que es `INV-007` incumplida del revés y justo lo que `ADR-045 §4.8` zanjó para los módulos.

---

## 7 · Consecuencias

**Buenas:**

- `1.6b` puede implementar el alta y la clonación sin escribir una sola consulta contra tablas de `REQ-CORE`.
- El comando de consola y el endpoint comparten implementación, con lo que un defecto se arregla una vez (`RN-BO-22`, mismo criterio).
- La invitación del primer administrador sale en el idioma del centro (`§5.1`).
- `REQ-ONB` (1.24) hereda un punto de entrada con tipos en vez de un comando de consola.
- La idempotencia pasa a ser observable, y por tanto demostrable en un test (`CA-BO-108`).

**Costes, dichos sin adornos:**

- **`1.6b` escribe código dentro de `REQ-CORE`.** Es lo que `OPEN-BO-15` sometía al usuario y `§10` lo acota.
- `ProvisionTenantDefaults` gana una responsabilidad (escribir la configuración inicial) y con ella el camino de clonación. Se acepta porque las tres cosas son el mismo flujo: sembrar un centro.
- La implementación queda en `Core\Application` y no sigue el sufijo `Eloquent*` de sus cinco hermanas (`§4.4`). Está escrito para que la asimetría no se lea como descuido.
- `docs/modulos/REQ-CORE/funcional.md §7` queda desactualizada hasta que se le añada `TenantProvisioner` a la lista de interfaces públicas. **No la toca este ADR** (`§11`); queda anotada como pendiente en `REQ-BO/operacion.md §8`, donde ya viven las otras cuatro deudas del mismo tipo.

---

## 8 · Riesgo residual

1. **Un contrato con dos métodos puede crecer a cinco.** La contención es la regla de `§4.9`: entra en `TenantProvisioner` lo que *siembra* un centro; lo que lo *modifica* después ya tiene sus endpoints en `REQ-CORE`.
2. **El objeto de valor puede convertirse en un saco de campos** cuando lleguen plan, dominio y etapas. Cuando eso ocurra, la partición natural es por dueño del dato (`plan` es de `REQ-SAAS`, `stages` de `REQ-ACAD`), no un sexto campo en `TenantInitialSettings`.
3. **La ordenación de `§5.1` no la garantiza el tipo, sólo el código.** Se cubre con un test que aprovisione un centro con `default_locale = 'de'` y compruebe el idioma de la invitación, no con un comentario.

---

## 9 · Alternativas descartadas y por qué

| Alternativa | Por qué no |
|---|---|
| **Evento de dominio emitido por `REQ-BO`** (opción B) | No devuelve resultado ni propaga fallo, y las dos cosas son obligatorias en `§5.3.3`/`§5.3.5`; el reintento exigiría reemitir un hecho falso; y pondría el vocabulario de `REQ-CORE` en una clase de `Backoffice`, contra `ADR-045 §4.8` |
| **`REQ-BO` escribe `tenant_settings`** (opción C) | Viola `INV-007` y deja dos implementaciones del mismo sembrado |
| **Bus de comandos genérico** (opción D) | Infraestructura compartida nueva para un solo mensaje. Complejidad sin beneficio proporcional |
| **Invocar el comando de consola** (opción E) | Acoplamiento por cadenas, sin tipos, sin objetos de retorno, y con el actor de auditoría fijado en `console` |
| **Mover `ProvisionTenantDefaults` a `Infrastructure\EloquentTenantProvisioner`** | Renombrado cosmético que toca cinco documentos, dos clases y los tests, sin cambiar ni un comportamiento (`§4.4`) |
| **Emitir `TenantProvisioned` «para dejar el enganche puesto»** | Tercera fuente de verdad de un hecho que ya registran `tenant_lifecycle_events` y `admin_action_logs`, sin consumidor en `1.6b` (`§4.6`) |
| **Devolver un enumerado con caso `Failed`** en vez de excepción | Un fallo que se puede ignorar por descuido acaba ignorándose (`§4.3`) |
| **Columna `provisioning_state` en `tenants`** | Ya descartada por `ADR-034 OPEN-13` y `api.md §2.4.1`: el estado se deriva |
| **Meter `module_subscriptions` en el contrato de `REQ-CORE`** | Revocaría `ADR-045 §4.1` (el backoffice es el único escritor) sin ADR que lo sustituya (`§4.5`) |
| **Dejar que `REQ-BO` lea la plantilla del origen y se la pase a `REQ-CORE`** | `REQ-BO` seguiría leyendo `tenant_settings`, `roles` y `permission_role`, y se perdería la lectura en un único punto en el tiempo que exige `§5.6.4` |

---

## 10 · Aprobación: qué se le pide exactamente al usuario

El mecanismo (`§4`) es decisión de `architect` y está tomada. Lo que `OPEN-BO-15` reservaba al usuario es el **permiso para que `1.6b` toque `REQ-CORE`**, y se acota aquí para que el sí sea sobre algo enumerado y no sobre un cheque en blanco:

**Lo que `1.6b` añade a `REQ-CORE`:**

1. Tres tipos nuevos en `Core\Domain`: `TenantProvisioner`, `TenantInitialSettings`, `TenantAdministrator`, y el enumerado `TenantProvisioningOutcome`.
2. `implements TenantProvisioner` en `Core\Application\ProvisionTenantDefaults`, más el segundo método (`provisionFromTemplate`) y la escritura de la configuración inicial en el primero.
3. Una línea de enlace en `CoreServiceProvider::register()`.
4. Opciones nuevas en `tenant:provision-defaults`, con los valores por defecto de hoy.
5. `person.locale` del primer administrador deja de ser literal (`§5.1`).

**Lo que `1.6b` NO toca de `REQ-CORE`:** ninguna migración, ninguna columna, ningún endpoint, ningún permiso, ningún rol, ninguna regla de negocio ya escrita, y ninguno de los otros cinco contratos públicos.

Si el usuario prefiere que la ampliación de `REQ-CORE` se haga en un paso propio en vez de dentro de `1.6b`, el mecanismo de este ADR no cambia: cambia sólo dónde se commitea. Es lo único de este documento que sigue abierto.

---

## 11 · Hallazgos fuera del alcance de este ADR, reportados y no corregidos

Se reportan y **no se tocan** (`CLAUDE.md §5`, issue #150):

1. **Correo de invitación despachado dentro de la transacción, con `after_commit => false`** (severidad **Media**). `IssueUserInvitation::issue()` llama a `SendInvitationEmail::dispatch(...)` dentro del `DB::transaction()` de `ProvisionTenantDefaults`, y las cuatro conexiones de `config/queue.php` declaran `'after_commit' => false`. Si la transacción se revierte —y con `1.6b` el aprovisionamiento pasa a ejecutarse en cola, donde revertir deja de ser hipotético—, un trabajador puede haber enviado ya un correo con un token que no existe en la base de datos. Ficheros: `apps/api/app/Modules/Core/Application/IssueUserInvitation.php:46`, `apps/api/app/Modules/Core/Application/ProvisionTenantDefaults.php:116`, `apps/api/config/queue.php`. Afecta también al alta de usuario y a la importación masiva de `1.1`, no sólo a `1.6b`.
2. **El idioma de la invitación del primer administrador es siempre `es-ES`** (severidad **Baja** hoy, porque sólo hay centros de prueba). Descrito en `§1.2`; lo corrige `§5.1` como parte de `1.6b`, y se anota aquí porque el defecto existe desde `1.1` y su test de regresión pertenece a `REQ-CORE`.
3. **`docs/modulos/REQ-CORE/funcional.md §7` quedará incompleta** en cuanto se implemente este ADR: su lista de interfaces públicas no incluirá `TenantProvisioner`. Anotado en `REQ-BO/operacion.md §8` junto a las otras deudas de documentación de `REQ-CORE` que `1.6c` y `1.6e` ya arrastran.
4. **`funcional.md §5.6` y `operacion.md §6.1` describían una clonación que incumplía `INV-007`** (severidad **Media**, detectada al redactar este ADR, `§1.2`). Se corrige en la especificación como parte de esta misma pasada (`§4.5`), antes de que exista código.
