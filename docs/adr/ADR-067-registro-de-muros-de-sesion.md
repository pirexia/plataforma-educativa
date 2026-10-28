# ADR-067 · Registro de muros de sesión

**Estado**: **PROPUESTA** (2026-10-28). Pendiente de aprobación del usuario, incluidas una a una las ampliaciones de listas cerradas de `§6` y las preguntas `OPEN-067-01` a `-06` de `§10`.
**Fecha**: 2026-10-28
**Paso**: `1.14 · REQ-FAM-UNIT`, entrada de la entrega `1.14-c` (`docs/modulos/REQ-FAM-UNIT/funcional.md §4.10`, `§7.5`, `§10`, `§12.7`; `api.md §2.8`).
**Afecta a**: `REQ-FAM-UNIT-003`, `REQ-AUTH-003` (muro de alta de MFA), `REQ-CORE` (`GET /me`, *guard* de la SPA, `funcional.md §12.3.5`), `INV-001`, `INV-002`, `INV-007`, `INV-010`, `INV-015`.
**Se apoya en**: `ADR-038 §6.2` (catálogo cerrado de `type`), `ADR-044 §4.7` (sin caché de autorización entre peticiones) y `§4.10` (el núcleo no depende de un módulo de negocio), `ADR-045` (módulos esenciales), `ADR-046 §4.1` (pila propia del backoffice), `ADR-053 §6` (recarga de `/me` ante `403`), `ADR-056` (`AR-02`, `AR-07a`, listas cerradas).
**Resuelve** el diseño de `OPEN-FAM-UNIT-12` (aprobada en su recomendación A el 2026-10-26, con la condición de que pasara por `architect`).
**Amplía** el catálogo de `ADR-038 §6.2` con un `type`. **Sustituye parcialmente `ADR-053 §6`**, solo la frase «cualquier `403` que no sea `mfa-enrollment-required` recarga `GET /me`», y solo si se aprueba `OPEN-067-03` (`§5.11`). No sustituye ningún otro ADR.

---

## 1 · Contexto

`REQ-FAM-UNIT-003` exige que un tutor no pueda usar la plataforma sin aceptar las condiciones de uso vigentes. Eso es un **muro tras el inicio de sesión**: la sesión existe, pero la API solo deja pasar unas pocas rutas hasta que el usuario cumple una condición. Hoy existe exactamente uno, el de alta de MFA (`REQ-AUTH-003`, `funcional.md §C.4.9`). El segundo obliga a decidir si cada muro se escribe a mano o si hay un punto de extensión, y cómo conviven dos muros sobre el mismo usuario sin dejarlo encerrado.

Hay que decidirlo antes de `1.14-c`, porque la entrega toca la cadena de *middleware* de **todas** las rutas de `api/v1`: un error aquí deja una ruta fuera del muro (incumple `REQ-FAM-UNIT-003` y `INV-010`) o deja a un usuario sin salida.

### 1.1 Hechos verificados (rama `chore/adr-067-muros-de-sesion`, sobre `8ecf91c`)

**API**

1. El muro de MFA **no vive en `Auth`**: es `App\Http\Middleware\RequireMfaEnrollment`, del núcleo, que importa `App\Modules\Auth\Domain\MfaPolicy` (permitido por `AR-02`: el núcleo puede usar el `Domain` de un módulo). Va en la **posición 10** del grupo `v1` de `routes/api.php`, después de `resolve-locale`, con el alias `require-mfa-enrollment`.
2. Su lista blanca son **7 nombres de ruta** escritos en una constante: `core.me.show`, `auth.mfa.show`, `auth.mfa-enrollments.store`, `auth.mfa-factors.store`, `auth.session.destroy`, `auth.csrf-cookie`, `core.tenant.branding`.
3. El algoritmo: sin usuario `User` no hace nada; si la ruta está en la lista, deja pasar **sin evaluar la política**; si no, `MfaPolicy::resolve($user)->isEnforced()` decide el `403 urn:pge:error:mfa-enrollment-required`.
4. **`MfaPolicy::resolve()` tiene efectos.** Si el usuario está obligado, sin factor y sin obligación abierta, **materializa** la fila de `user_mfa_obligations` y emite `MfaObligationStarted` (`EloquentMfaPolicy::createObligation()`). Además memoiza por instancia (`scoped()`, una por petición). Por eso importa **dónde** se llama, no solo qué devuelve: llamarla en una ruta en la que hoy no se llama cambia el comportamiento (`§5.6`).
5. Ningún test comprueba que **toda** ruta de `api/v1` lleve el muro. Lo garantiza solo el grupo de `routes/api.php`. Hay un único grupo `v1` sin el muro, a propósito: el ACS de SAML (`auth.saml.acs`), que llega sin sesión. Solo existe la comprobación inversa: `CA-BO-011` prohíbe el alias en `api/platform/*`.
6. El backoffice usa otra pila (`platform/v1`, `guard` `platform`, modelo `PlatformAdmin`, `RequirePlatformMfa` por ruta). Una sesión de plataforma **nunca** llega a `api/v1` con un `User`, y `platform_sessions` es ilegible para el *runtime* de un centro (`ADR-046 §5.2`).
7. Hay dos registros de extensión que sirven de precedente: `App\Support\Authorization\ScopeResolverRegistry` (*singleton*, cada módulo registra el suyo desde el `boot()` de su *ServiceProvider*) y `App\Modules\Curso\Domain\AcademicYearClosureRegistry`. Los contratos que necesitan los *middleware* del núcleo viven en `App\Support\Sessions` (`ActiveSessionCloser`, creado en el issue #375 para dejar vacía la lista de excepciones de `AR-02`).
8. `ModuleAvailability::isEnabled()` cachea 300 s con prefijo de tenant, y `ModuleContractingService` invalida la clave al contratar o descontratar. `auth` y `core` son esenciales y devuelven `true` sin consulta.
9. `AR-07a` (`tests/Feature/Architecture/RouteAuthorizationTest.php`) tiene hoy **35** excepciones nominales, fijadas con `toHaveCount(35)`.
10. `api.md §2.8` de `REQ-FAM-UNIT` propone como rutas exentas `GET /me`, `GET /me/terms`, `POST /me/terms-acceptances`, el cierre de sesión y `GET /tenant/branding`, y un `params.version` en el cuerpo del `403`. **No incluye `auth.csrf-cookie`**, que el muro de MFA sí exime.

**SPA**

11. El muro de MFA se detecta **solo por el `type` de un `403`**: `api/client.ts` redirige a `mfa-enrollment-wall` y `session/useSession.ts` pasa a `mfa-required` si `GET /me` responde ese `403`. **Pero `GET /me` está en la lista blanca**, así que en la práctica esa rama de `useSession` no se alcanza. El usuario llega al muro cuando alguna otra petición devuelve el `403`.
12. `GET /me` expone `mfa.enforced`, pero el *guard* (`router/guard.ts`) **no lo usa**. `REQ-CORE/funcional.md §12.3.5`, último párrafo, exige que `200` con `mfa.enforced = true` produzca el mismo efecto que el `403`. **El código no lo hace** (hallazgo, `§11`): un usuario obligado cuya pantalla de inicio no pida nada fuera de la lista blanca se queda en el inicio en vez de en el muro. Para un tutor sin permisos, que es el caso de `1.14-c`, ese inicio vacío es justamente lo que pasaría.
13. La lista cerrada de rutas con `meta.permissions: []` (`src/navigation/modules.spec.ts`) tiene **7** entradas y lo fija en tres aserciones. `BareLayout` solo lo usa `mfa-enrollment-wall`.
14. No existe `apps/web/src/modules/fam-unit/`: la pantalla del muro será la primera del módulo en la SPA.

---

## 2 · Qué NO decide este ADR

- **Quién está obligado** a aceptar. Lo dice `RN-FAM-UNIT-61`: el usuario con una faceta de tutor viva y al menos un vínculo activo. Solo tutores: la pregunta de `OPEN-FAM-UNIT-12` sobre el alumnado quedó resuelta con la recomendación el 2026-10-26, y el contrato de este ADR no depende de ella.
- **El modelo de datos** de `terms_acceptances` (`datos.md §1.9`), el texto de la pantalla del muro ni la versión exigible (`RN-FAM-UNIT-62`).
- **El MFA del backoffice** (`RequirePlatformMfa`, `ADR-046`). Tiene otro *guard*, otra pila y su excepción va por parámetro de ruta. No entra en el registro (`§9`, alternativa 5).
- **Una futura suplantación de un usuario del centro desde soporte.** Hoy no existe. Si llega, su especificación decide si los muros aplican al usuario suplantado.

---

## 3 · Opciones reales

| | Mecanismo | Muro de MFA |
|---|---|---|
| **A** | **Registro de muros de sesión** en el núcleo: un único *middleware* recorre en orden los muros que cada módulo registra desde su *ServiceProvider* | Pasa a ser un muro registrado, **sin cambiar su comportamiento** |
| **A′** | El mismo registro, pero en `1.14-c` solo contiene el muro de condiciones | `RequireMfaEnrollment` se queda como está, en la posición 10, y el registro va en la 11 |
| **B** | Un segundo *middleware* escrito a mano, `RequireTermsAcceptance`, en el núcleo y junto al de MFA (o la comprobación metida dentro de `RequireMfaEnrollment`) | Sin cambios |
| **C** | Muro solo en la SPA (*guard* + pantalla) | Sin cambios |

La especificación dice que B «acopla dos módulos». **Lo matizo con el código**: `RequireMfaEnrollment` no es de `Auth`, es del núcleo (hecho 1). Lo que B hace es que el **núcleo** importe el `Domain` de `fam_unit`, un módulo **no esencial**. `AR-02` lo permite. `ADR-044 §4.10` fija la dirección contraria: el núcleo expone puntos de extensión y los módulos se enganchan. El acoplamiento existe, pero es otro distinto del que dice la especificación.

C queda **confirmada como inviable**: la API seguiría respondiendo a un tutor sin aceptación a cualquier cliente que no sea la SPA, lo que incumple `INV-010` e `INV-006`.

---

## 4 · Evaluación

| Criterio | A · registro con MFA dentro | A′ · registro sin MFA | B · *middleware* a mano | C · solo SPA |
|---|---|---|---|---|
| **Coste en solitario** | Medio: registro, *middleware* genérico, adaptador de MFA, retirar `RequireMfaEnrollment` y renombrar un alias en un test (`CA-BO-011`) | Bajo-medio: registro y *middleware* nuevo; MFA intacto | **Bajo**: un *middleware* más | Mínimo, y no cumple el requisito |
| **Mantenimiento a 3 años** | Un solo sitio para el orden, las exenciones y la cobertura; el tercer muro (`REQ-PRIV`, caducidad de contraseña, etc.) es una clase y una línea en la lista de orden | **Dos mecanismos** para lo mismo, que conviven indefinidamente; la regla entre muros (`§5.4`) se reparte entre los dos | Cada muro nuevo es un *middleware* más, una línea en el grupo, un alias más en `CA-BO-011` y una importación del núcleo hacia un módulo de negocio. Con dos muros ya hay que resolver el bloqueo mutuo a mano (`§5.4`) | — |
| **Invariantes** | `INV-007` y `ADR-044 §4.10` correctos: el núcleo no conoce `fam_unit`. `INV-010` cumplida. La cobertura de todas las rutas se puede comprobar con un test (`§7`) | Igual que A, pero el test de cobertura tiene que comprobar dos piezas | `INV-010` cumplida; invierte `ADR-044 §4.10` | Incumple `INV-010` |
| **Reversibilidad** | Alta: si el registro falla, se vuelve a `RequireMfaEnrollment` (clase y lista en el historial de Git) y el muro de condiciones pasa a un *middleware* propio, sin migraciones de esquema | Alta | Alta | Alta, e inútil |
| **Riesgo sobre MFA** | Bajo **si** el algoritmo conserva el atajo de exención antes de evaluar (`§5.3`, `§5.6`) | Nulo | Nulo | Nulo |

**Recomiendo A**, por tres razones:

1. **El problema que hay que resolver bien es la interacción entre muros, no el muro de condiciones en sí.** Con dos muros independientes, un usuario con los dos pendientes queda **encerrado**: `POST /auth/mfa-factors` está exento del muro de MFA pero no del de condiciones, y `POST /me/terms-acceptances` al revés. Ninguna de las dos acciones que le sacarían del muro le está permitida. B y A′ obligan a resolverlo a mano: o el muro de condiciones nombra rutas de `Auth`, o hay que evaluar el MFA en rutas donde hoy no se evalúa, lo que dispara el efecto del hecho 4. A lo resuelve **una vez**, con una regla en un solo sitio (`§5.4`).
2. **Migrar el MFA es seguro en `1.14-c`**, porque el algoritmo genérico es el de `RequireMfaEnrollment` escrito para una lista de muros (`§5.6`, tabla de equivalencia). Con el MFA en primera posición, cada petición recorre exactamente el mismo camino que hoy.
3. **A′ deja dos mecanismos para siempre.** El ahorro es el adaptador de MFA (una clase de unas 30 líneas) a cambio de que la regla de precedencia viva repartida en dos piezas.

---

## 5 · Decisión

### 5.1 El contrato: `App\Support\Sessions\SessionWall`

```php
namespace App\Support\Sessions;

use App\Models\User;

interface SessionWall
{
    /** Código estable del muro; es el que ve la SPA en GET /me (§5.10). Ej.: 'mfa-enrollment', 'terms-acceptance'. */
    public function code(): string;

    /** Slug del catálogo de ADR-038 §6.2, sin el prefijo urn:pge:error:. Ej.: 'terms-acceptance-required'. */
    public function errorType(): string;

    /** Código del módulo dueño (descriptor de ADR-045). El muro no se evalúa si ModuleAvailability dice que no está activo. */
    public function moduleCode(): string;

    /** @return list<string> Nombres de ruta exentos. Lista cerrada (§6). */
    public function exemptRouteNames(): array;

    /** ¿Está este usuario detenido por el muro ahora? Sin caché entre peticiones (§5.8). */
    public function blocks(User $user): bool;
}
```

**Ubicación.** El encargo pedía `Core\Domain`. Propongo `App\Support\Sessions` porque los consumidores son el núcleo (un *middleware* de `App\Http` y `UserProfilePresenter`, de `App\Support\Api`), y el precedente que el proyecto ya aplicó a este mismo caso es `ActiveSessionCloser` en ese espacio de nombres (issue #375). Ninguna regla impide `Core\Domain`: las dos ubicaciones cumplen `AR-01`/`AR-02`. Pero `Core` es un módulo, y un contrato de la sesión HTTP que consumen piezas del núcleo encaja mejor en el núcleo, como `ScopeResolverRegistry`. **Requiere aprobación: `OPEN-067-06`.**

### 5.2 El registro: `App\Support\Sessions\SessionWallRegistry`

- *Singleton*, enlazado en un *ServiceProvider* del núcleo, igual que `ScopeResolverRegistry` en `AuthorizationServiceProvider`.
- `register(class-string<SessionWall> $wall)`. **Registra la clase, no una instancia.** El *middleware* la resuelve del contenedor **en cada petición**. Así una implementación que dependa de un servicio `scoped()` (como `EloquentMfaPolicy`) recibe la instancia de esa petición y nunca una capturada en el arranque. En un proceso de vida larga, una instancia capturada con memo por usuario sería una fuga entre peticiones, y potencialmente entre tenants (`INV-001`).
- **Orden fijo y cerrado**: una constante del registro, `['mfa-enrollment', 'terms-acceptance']` (`§6`, L6). Registrar un código que no esté en la lista, o registrar dos veces el mismo, lanza `LogicException` en el arranque. Añadir un muro exige añadir su código a la lista, y eso exige aprobación, igual que `AR-07a`. Para los tests (`§7`, CA-067-04), el constructor acepta otra lista de orden.
- `ordered(): list<class-string<SessionWall>>` en el orden de la constante. Solo incluye los muros registrados.
- Cada módulo registra el suyo desde el `boot()` de su *ServiceProvider*: `AuthServiceProvider` registra el de MFA y `FamUnitServiceProvider` el de condiciones. Se registran siempre: si el módulo está activo lo decide el *middleware* (`§5.9`).

### 5.3 El *middleware*: `App\Http\Middleware\RequireSessionWalls`

Sustituye a `RequireMfaEnrollment` en la **misma posición 10** del grupo `v1`, con el alias `require-session-walls`. `require-mfa-enrollment` desaparece. Algoritmo:

```
user = request->user()
si user no es User → siguiente
ruta = nombre de la ruta
para cada clase W en registry->ordered():
    muro = contenedor->make(W)
    si no ModuleAvailability::isEnabled(muro.moduleCode()) → continuar con el siguiente muro
    si ruta ∈ muro.exemptRouteNames() → siguiente middleware      (atajo: no se evalúa este muro ni los posteriores)
    si muro.blocks(user) → 403 urn:pge:error:<muro.errorType()>
siguiente middleware
```

### 5.4 Semántica entre muros: precedencia con exención heredada

**Regla.** Manda el primer muro, por orden, que no exima la ruta y bloquee al usuario. Si un muro exime la ruta, la ruta pasa **sin evaluar ese muro ni los posteriores**: las exenciones de un muro **se heredan** en los que van detrás de él, y **nunca** en los que van delante.

**No hay encierro.** Con un usuario que tenga los dos muros pendientes:

| Ruta | MFA (1.º) | Condiciones (2.º) | Resultado |
|---|---|---|---|
| `POST /auth/mfa-factors` | exenta → pasa | no se evalúa | Puede darse de alta en MFA |
| `POST /me/terms-acceptances` | no exenta, bloquea | — | `403 mfa-enrollment-required` |
| Cualquier otra | bloquea | — | `403 mfa-enrollment-required` |

Cuando cumple el MFA, `POST /me/terms-acceptances` atraviesa el primer muro (no bloquea) y el segundo la exime. El resto responde `403 terms-acceptance-required`. **Primero MFA, después condiciones**: la aceptación, que es evidencia legal, queda registrada desde una sesión que ya cumple la política de autenticación del centro.

**Consecuencia que hay que aceptar.** Un tutor con **solo** las condiciones pendientes puede llegar a las tres rutas que exime el MFA y que el muro de condiciones no exime: `GET /auth/mfa`, `POST /auth/mfa-enrollments` y `POST /auth/mfa-factors`. Es decir, puede consultar su estado de MFA y darse de alta un factor antes de aceptar. No expone datos del centro: las tres son autoservicio de su propia cuenta, y están en `AR-07a`. Pero **matiza la letra de `CA-FAM-UNIT-070`** («cualquier *endpoint* que no sea de la lista exenta»). La lista exenta **efectiva** del muro de condiciones es la suya (6) más esas 3. Evitarlo obliga a evaluar el MFA en esas rutas, lo que cambia el comportamiento del MFA (hecho 4). **Requiere aprobación: `OPEN-067-01`.**

### 5.5 El error

- `type`: **`urn:pge:error:terms-acceptance-required`**, estado `403`. **Amplía el catálogo cerrado de `ADR-038 §6.2`** (L5). El de MFA, `mfa-enrollment-required`, no cambia.
- `ApiException` gana una fábrica genérica, `sessionWall(string $type)`, que solo usa `RequireSessionWalls`. `mfaEnrollmentRequired()` se retira con su único emisor. El cuerpo lo produce `ProblemResponseFactory` como hoy: `title` y `detail` traducidos por `type` (`errors.title.terms-acceptance-required` y su `detail`, en los cuatro idiomas, `INV-009`).
- **Sin miembros de extensión** en el cuerpo. Propongo **no** incluir el `params.version` que pide `api.md §2.8`: la pantalla del muro obtiene la versión pendiente de `GET /me/terms`, que existe para eso. Un `403` genérico con contenido por muro obligaría a añadir al contrato un método de carga útil para un único consumidor. **Requiere aprobación: `OPEN-067-05`.**

### 5.6 Migración del muro de MFA, sin cambio de comportamiento

`Auth\Infrastructure\MfaEnrollmentWall implements SessionWall`, registrado desde `AuthServiceProvider::boot()`:

- `code()` = `mfa-enrollment`; `errorType()` = `mfa-enrollment-required`; `moduleCode()` = `auth` (esencial: `true` sin consulta).
- `exemptRouteNames()` = **los 7 nombres actuales, sin añadir ni quitar ninguno**.
- `blocks($user)` = `MfaPolicy::resolve($user)->isEnforced()`, con `MfaPolicy` inyectada por el contenedor en cada petición.
- **`MfaPolicy` no se toca.**

| Comportamiento observable de hoy | Tras la migración |
|---|---|
| `403 urn:pge:error:mfa-enrollment-required`, mismo `title`/`detail` | Igual: mismo `type`, misma traducción |
| Posición 10, después de `resolve-locale` | Igual |
| Sin usuario `User` no hace nada | Igual |
| En una ruta exenta **no** llama a `resolve()` (no materializa ni emite `MfaObligationStarted`) | Igual: el MFA es el primer muro y el atajo de exención va antes de `blocks()` |
| En una ruta no exenta llama a `resolve()` exactamente una vez | Igual (el muro de condiciones solo se evalúa si el MFA no bloquea) |
| Lista blanca de 7 | Igual |
| `api/platform/*` sin el muro | Igual (`CA-BO-011` cambia el alias que prohíbe, no lo que comprueba) |
| `auth.saml.acs` sin el muro | Igual |

**Cambios no funcionales**: desaparecen la clase `RequireMfaEnrollment` y el alias, se renombra el alias en `CA-BO-011` y se actualizan los comentarios que citan `RequireMfaEnrollment` (`routes/api.php`, `Auth/Http/routes.php`, `MfaPolicy`, `MfaObligation`, `SessionController`, `GoogleOAuthCallbackService`, `MfaChallengesController`). **Los tests del muro de MFA (`CA-AUTH-129` a `-132`, `GoogleLoginTest`) tienen que pasar sin tocarlos**: es la prueba de la equivalencia (CA-067-05).

**Cambios observables, aditivos y sujetos a aprobación**: el campo nuevo de `GET /me` (`§5.10`, `OPEN-067-02`) y la redirección determinista de la SPA (`§5.11`, `OPEN-067-03`). Si no se aprueban, la migración del API sigue siendo válida sin ellos.

### 5.7 El muro de condiciones

`FamUnit\Infrastructure\TermsAcceptanceWall implements SessionWall`, registrado desde `FamUnitServiceProvider::boot()`:

- `code()` = `terms-acceptance`; `errorType()` = `terms-acceptance-required`; `moduleCode()` = `fam_unit`.
- `exemptRouteNames()` = la lista L3 de `§6` (6 nombres).
- `blocks($user)`: **una sola consulta**, con predicado de tenant explícito además de la RLS (`INV-001`), que devuelve `true` si y solo si se cumplen las tres condiciones:
  1. existe una versión `condiciones_uso` en estado `publicada` (índice único parcial `terms_versions_tenant_kind_published_unique`). Si no existe, nadie está obligado (`RN-FAM-UNIT-63`);
  2. existe una faceta `guardians` viva con `person_id = $user->person_id` y al menos un `guardian_student_links` `activo` y no borrado (`RN-FAM-UNIT-61`). `person_id` sale del propio `User`, sin leer `users` ni `people`;
  3. **no** existe una aceptación exigible en `terms_acceptances` para `$user->id`. Si la versión publicada exige re-aceptación, la de esa versión; si no, cualquiera de tipo `condiciones_uso` (`RN-FAM-UNIT-62`).

  La forma exacta de la consulta y su plan son de `db-reviewer`, con el índice de `datos.md §2` (`terms_acceptances (tenant_id, user_id, accepted_at DESC)`).

### 5.8 Coste por petición y caché

| Petición de… | Hoy | Con este ADR |
|---|---|---|
| Usuario sin sesión | 0 | 0 |
| Cualquier usuario, ruta exenta del MFA | 0 | 0 (atajo en el primer muro) |
| Usuario con MFA bloqueante | Consultas de `resolve()` | Las mismas (el segundo muro no se evalúa) |
| Usuario sin MFA bloqueante, tenant **sin** `fam_unit` | Consultas de `resolve()` | Las mismas + 1 lectura de caché (`modules:fam_unit:enabled`, la misma clave que ya usa `module-enabled`) |
| Usuario sin MFA bloqueante, tenant **con** `fam_unit` | Consultas de `resolve()` | Las mismas + 1 lectura de caché + **1 consulta** (`§5.7`) |
| `GET /me` | — | + 1 consulta si `fam_unit` está activo y se aprueba `§5.10` |

**Sin caché entre peticiones del resultado de `blocks()`**, por ningún muro. Es el mismo criterio que `MfaPolicy` (`REQ-AUTH §C.4.7`) y `ADR-044 §4.7`. Los casos que crean una aceptación pendiente tienen que verse en **la petición siguiente**: publicar una versión que exige re-aceptación, activar el primer vínculo de un tutor o restaurar un vínculo revocado. Una caché por usuario los ocultaría hasta que caducara. Tampoco se cachea la versión publicada: va dentro de la misma consulta, así que no hay nada que invalidar.

La única caché que interviene es la de `ModuleAvailability`, que ya existe y que la contratación invalida (hecho 8): al contratar `fam_unit`, el muro aparece en la petición siguiente.

### 5.9 Módulo desactivado, sesiones de plataforma y peticiones sin sesión

- **Tenant sin `fam_unit` contratado**: el muro de condiciones no se evalúa (`§5.3`) y no hace ninguna consulta de `fam_unit`. Coincide con `funcional.md §9` («el muro de condiciones no se muestra»). Es además **obligatorio para que no haya encierro**: con el módulo desactivado, `GET /me/terms` y `POST /me/terms-acceptances` responden `403 module-disabled`, y un muro activo dejaría al usuario sin salida. Los datos se conservan (`RMOD-003`): si se vuelve a contratar, el muro reaparece para quien tenga una aceptación pendiente.
- **Sesiones de plataforma** (`platform_sessions`, *guard* `platform`): el registro no está en la pila de `platform/v1`, y `CA-BO-011` lo prohíbe allí. Un `PlatformAdmin` no es un `User` y nunca llega a `api/v1` con sesión de centro.
- **Peticiones sin usuario autenticado** (`POST /auth/session`, desafío de MFA, invitaciones): no se evalúa ningún muro, igual que hoy.
- **Colas y consola**: los muros son de la sesión HTTP y no aplican.

### 5.10 `GET /me` expone los muros pendientes

`core.me.show` está exento de los dos muros, así que la SPA necesita otra forma de saber, sin depender de un `403` casual, si tiene que llevar al usuario a un muro. Propongo un campo aditivo en `GET /me`:

```json
"session_walls": ["terms-acceptance"]
```

Contiene los códigos de los muros **activos y bloqueantes**, en el orden del registro, y una lista vacía si no hay ninguno. Lo calcula `UserProfilePresenter` recorriendo el registro con la misma regla de módulo activo, sin el atajo de exención (aquí no hay ruta que eximir). Es un cambio compatible (`ADR-038 §7`). `mfa.enforced` sigue existiendo. **Requiere aprobación: `OPEN-067-02`.**

### 5.11 SPA

- **Registro de muros en los *shells*** (`ADR-053`): un *shell* puede declarar `sessionWalls: [{ code, errorType, route }]`. `auth` declara el de MFA (`mfa-enrollment-wall`) y `fam-unit` el de condiciones (`fam-unit-terms-wall`, ruta `/condiciones-de-uso`, `meta.layout: 'bare'`, `meta.permissions: []`).
- `api/client.ts` y `session/useSession.ts` dejan de comparar con la constante del MFA. Un `403` cuyo `type` sea el de un muro registrado lleva a la ruta de ese muro, y el estado `mfa-required` se generaliza a «muro activo» con su código.
- **El *guard***: si `/me` trae `session_walls` no vacío, solo deja entrar en la ruta del primero. Lleva allí cualquier otra navegación `app`/`bare`. Sin bucle: no vuelve a pedir `/me` al entrar en la ruta del muro.
- **Sustitución parcial de `ADR-053 §6`**: «cualquier `403` que no sea **de un muro registrado** recarga `GET /me`».
- **Efecto sobre el MFA**: con el *guard* nuevo, un usuario con MFA bloqueante aterriza en el muro **justo después de `/me`**, y no en la primera petición que devuelva el `403`. Es lo que ya exige `REQ-CORE §12.3.5` y el código actual no hace (hecho 12). Pero es un cambio observable de la SPA en un muro que el encargo pedía no tocar. **Requiere aprobación: `OPEN-067-03`.** Si se rechaza, el *guard* aplica `session_walls` solo a los muros que no sean el de MFA, y el hallazgo del hecho 12 se trata por su cuenta.

### 5.12 Invariantes

- **`INV-001`**: los muros se evalúan después de `resolve-tenant` (posición 2) y `verify-session-tenant` (7), dentro del `TenantContext` de la petición. La consulta de `§5.7` lleva predicado de tenant y RLS. El registro guarda clases, no instancias (`§5.2`).
- **`INV-002`**: un muro **solo deniega**, nunca autoriza. Que una ruta esté exenta no la autoriza: sigue necesitando su `permission:` o estar en `AR-07a`. Además, toda ruta exenta de un muro **tiene que estar en `AR-07a`** (es de autoservicio), y lo comprueba CA-067-02. Así, mientras el muro esté activo no hay forma de alcanzar datos de negocio.
- **`INV-007`**: el núcleo conoce la interfaz y el registro; `Auth` y `fam_unit` se enganchan desde su propio *ServiceProvider*. Ningún módulo importa a otro.
- **`INV-010`**: la API es la que hace cumplir el muro. La SPA solo decide adónde llevar al usuario.
- **`INV-003`**: la aceptación se audita según `datos.md` de `REQ-FAM-UNIT`. Un `403` del muro no es una escritura y no se audita, igual que hoy el de MFA.

---

## 6 · Ampliaciones de listas cerradas (cada una requiere aprobación expresa)

| ID | Lista | Cambio | De → a | Motivo |
|---|---|---|---|---|
| **L1** | `AR-07a`, rutas de `api/v1` sin `permission:` | + `fam-unit.me-terms.show` (`GET /me/terms`) y + `fam-unit.me-terms-acceptances.store` (`POST /me/terms-acceptances`) | 35 → **37** | Autoservicio por identidad, como `GET /me` (`permisos.md §1` de `REQ-FAM-UNIT`). Llevan `module-enabled:fam_unit` (`AR-07b`) |
| **L2** | Rutas de la SPA con `meta.permissions: []` | + `fam-unit-terms-wall` | 7 → **8** | La alcanza cualquier usuario autenticado con el muro de condiciones pendiente, sin permiso que declarar; mismo motivo que `mfa-enrollment-wall`. Segunda ruta de `BareLayout` |
| **L3** | **Nueva**: rutas exentas del muro de condiciones | `core.me.show`, `fam-unit.me-terms.show`, `fam-unit.me-terms-acceptances.store`, `auth.session.destroy`, `auth.csrf-cookie`, `core.tenant.branding` | — → **6** | La propuesta de `api.md §2.8` **más `auth.csrf-cookie`**: sin ella la SPA no puede renovar el *token* CSRF para el `POST` de aceptación, y el MFA la exime por lo mismo. **No** incluye `core.me.update`: ver `OPEN-067-04`. Lista efectiva con la herencia de `§5.4`: 6 + 3 |
| **L4** | Rutas exentas del muro de MFA | **Sin cambios**. Pasa de `RequireMfaEnrollment::ALLOWED_ROUTE_NAMES` a `MfaEnrollmentWall::exemptRouteNames()` | 7 → 7 | `§5.6` |
| **L5** | Catálogo de `type` de `ADR-038 §6.2` | + `urn:pge:error:terms-acceptance-required` (403) | — | `§5.5` |
| **L6** | **Nueva**: orden de los muros | `['mfa-enrollment', 'terms-acceptance']` | — → **2** | `§5.4` |
| **L7** | Rutas de `api/v1` sin `require-session-walls` | `auth.saml.acs` (la que ya existe, sin cambio) | 1 → 1 | Llega sin sesión por diseño (`ADR-043 §2.1`). Hoy no está escrita como lista; pasa a ser lista cerrada en CA-067-01 |

---

## 7 · Cómo se comprueba que ningún *endpoint* escapa al muro (`INV-015`)

Tests de arquitectura sobre la **tabla de rutas registrada** (patrón de `RouteAuthorizationTest`), más uno de comportamiento:

- **CA-067-01** — Toda ruta cuyo URI empieza por `api/v1` lleva `require-session-walls` en `gatherMiddleware()`, salvo las de L7. Ninguna de `api/platform/*` lo lleva (`CA-BO-011`, alias renombrado). Con control negativo sobre una tabla de prueba.
- **CA-067-02** — Para cada muro registrado: cada nombre exento existe como ruta de `api/v1` (detección de excepciones caducadas, como `CA-056-15`), **está en la lista de `AR-07a`** (`§5.12`) y la lista coincide exactamente con la aprobada (L3, L4), comparada por contenido y no solo por número.
- **CA-067-03** — El orden del registro es exactamente L6, y registrar un código fuera de L6 o duplicado falla.
- **CA-067-04** (barrido) — Se sustituye en el contenedor el registro por uno que solo tiene un muro de prueba que **siempre bloquea** y no exime nada. Con un usuario autenticado y *token* CSRF, **toda** ruta de `api/v1` salvo L7, con parámetros de ruta ficticios, responde `403` con el `type` de ese muro. Una ruta que responda otra cosa hace fallar el test, y se analiza antes de excluirla: no se excluye sin más. Este barrido es la prueba de que el muro va antes que `module-enabled`, `permission:` y el controlador en cualquier ruta, incluidas las que se añadan después.
- **CA-067-05** — Los tests existentes del muro de MFA pasan **sin modificarse**. Además, con un espía sobre `MfaPolicy`, en una ruta de L4 `resolve()` **no** se llama y en una ruta no exenta se llama una sola vez (`§5.6`).
- **CA-067-06** — Precedencia (`§5.4`): con los dos muros pendientes, `POST /auth/mfa-factors` pasa y `POST /me/terms-acceptances` da `403 mfa-enrollment-required`. Tras cumplir el MFA, la aceptación pasa y cualquier otra ruta da `403 terms-acceptance-required`.
- **CA-067-07** — Sin caché (`§5.8`): un tutor sin muro al que se le activa el primer vínculo a mitad de sesión recibe el `403` en la petición siguiente. Lo mismo al publicar una versión que exige re-aceptación (complementa `CA-FAM-UNIT-072`).
- **CA-067-08** — Coste (`§5.8`): con `fam_unit` activo, un docente sin faceta de tutor hace exactamente **una** consulta más que sin el muro. Con `fam_unit` desactivado, **ninguna** consulta a tablas de `fam_unit`, y un tutor pendiente no ve el muro.
- **CA-067-09** (SPA, si se aprueban `OPEN-067-02`/`-03`) — `modules.spec.ts` con 8 entradas (L2). El *guard* lleva a la ruta del primer código de `session_walls`. El cliente redirige ante el `type` de cualquier muro registrado y no recarga `/me` por él (`§5.11`).

Los `CA-FAM-UNIT-070` a `-077` siguen siendo los criterios funcionales del muro de condiciones.

---

## 8 · Consecuencias

**Buenas**

- Un único sitio decide qué rutas atraviesan muros, en qué orden y con qué salidas. El tercer muro cuesta una clase, una línea en L6 y su lista exenta.
- La cobertura de **todas** las rutas pasa de depender de que el grupo esté bien escrito a estar comprobada por test (CA-067-01, CA-067-04). Eso también protege el muro de MFA, que hoy no tiene esa comprobación.
- El núcleo deja de depender de un módulo concreto para el muro. La importación de `Auth\Domain\MfaPolicy` desde `App\Http` desaparece, porque pasa al adaptador dentro de `Auth`.
- Se corrige de paso la desviación de la SPA respecto de `REQ-CORE §12.3.5`, si se aprueba `OPEN-067-03`.

**Malas, o que hay que aceptar**

- Una consulta más por petición para todo usuario de un tenant con `fam_unit` (`§5.8`). Es una búsqueda por índices únicos parciales y está medida por CA-067-08, pero existe.
- La herencia de exenciones (`§5.4`) deja a un tutor con solo las condiciones pendientes llegar a tres rutas de MFA (`OPEN-067-01`).
- Es una refactorización del muro de MFA dentro de una entrega de `fam_unit`. Lo mitigan CA-067-05 y que `MfaPolicy` no se toque.
- `ApiException::mfaEnrollmentRequired()` y el alias `require-mfa-enrollment` desaparecen. Los citan comentarios y documentos de `REQ-AUTH`, `REQ-CORE` y `REQ-BO` que hay que actualizar (`§12`).

---

## 9 · Alternativas descartadas

1. **B · *middleware* a mano por muro.** Con dos muros ya obliga a resolver a mano el bloqueo mutuo, nombrando rutas de `Auth` en el muro de condiciones o evaluando el MFA donde hoy no se evalúa. Además, el núcleo importaría el `Domain` de un módulo no esencial, contra la dirección de `ADR-044 §4.10`. El coste de A es solo algo mayor y no se repite con cada muro nuevo.
2. **A′ · registro sin migrar el MFA.** Dos mecanismos para la misma cosa, de forma indefinida. La regla de precedencia quedaría repartida entre `RequireMfaEnrollment` y el registro, que es exactamente donde se cuelan los errores. La migración es equivalente por construcción (`§5.6`).
3. **C · solo SPA.** Incumple `INV-010` e `INV-006`.
4. **Exenciones independientes** (una ruta pasa si cada muro, o no bloquea, o la exime). Es la semántica más intuitiva y **encierra** al usuario con dos muros (`§5.4`), salvo que se evalúe el MFA en rutas donde hoy no se evalúa (hecho 4).
5. **Meter `RequirePlatformMfa` en el registro.** Es otro *guard*, otro modelo de usuario y otra pila, con la excepción por parámetro de ruta (`ADR-046`, issue #174). Unificarlos acoplaría las dos pilas que `ADR-046` separó a propósito.
6. **Cachear `blocks()` por usuario con invalidación por eventos.** Ahorra una consulta indexada a cambio de que una invalidación que se pierda oculte una aceptación pendiente, que es justo lo que el encargo prohíbe. Además, `fam_unit` no emite eventos (`funcional.md §7.6`, `ADR-048`).
7. **Registrar instancias en lugar de clases.** Es más simple, pero captura servicios `scoped()` en el arranque (`§5.2`).
8. **`params.version` en el `403`** (`api.md §2.8`). Duplica `GET /me/terms` y obliga al contrato a llevar una carga útil por muro (`OPEN-067-05`).

---

## 10 · Preguntas abiertas

| ID | Pregunta | Recomendación | Bloquea |
|---|---|---|---|
| **`OPEN-067-01`** | ¿Se acepta la herencia de exenciones (`§5.4`), con la que un tutor con solo las condiciones pendientes puede usar `GET /auth/mfa`, `POST /auth/mfa-enrollments` y `POST /auth/mfa-factors` antes de aceptar? | **Sí.** Es autoservicio de seguridad de su propia cuenta, no expone datos del centro, y la alternativa cambia el comportamiento del MFA o encierra al usuario. `CA-FAM-UNIT-070` se lee con la lista efectiva (6 + 3) | `1.14-c` |
| **`OPEN-067-02`** | ¿Se añade `session_walls` a `GET /me` (`§5.10`)? | **Sí.** Sin él, que el tutor aterrice en el muro (`CA-FAM-UNIT-076`) depende de que su inicio pida algo fuera de la lista exenta, y un tutor sin permisos no pide nada | `1.14-c` (SPA) |
| **`OPEN-067-03`** | ¿El *guard* aplica `session_walls` también al muro de MFA, y se sustituye la frase de `ADR-053 §6` (`§5.11`)? | **Sí.** Es lo que ya exige `REQ-CORE §12.3.5` y corrige el hallazgo del hecho 12 con el mismo código. Es un cambio observable del muro de MFA en la SPA: hay que aprobarlo expresamente | `1.14-c` (SPA) |
| **`OPEN-067-04`** | ¿`PATCH /me` (`core.me.update`) está exento del muro de condiciones, para que el tutor cambie de idioma antes de leer? | **No en `1.14-c`.** El MFA no lo exime, el texto se sirve en el idioma del usuario con la caída de `ADR-062` y la aceptación registra el idioma mostrado (`RN-FAM-UNIT-65`). Si el piloto lo pide, se amplía L3 con aprobación | No |
| **`OPEN-067-05`** | ¿Se retira el `params.version` del `403` que propone `api.md §2.8` (`§5.5`)? | **Sí.** La pantalla lee `GET /me/terms` | `1.14-c` |
| **`OPEN-067-06`** | ¿El contrato va en `App\Support\Sessions` (propuesta) o en `App\Modules\Core\Domain` (encargo)? | **`App\Support\Sessions`**, por el precedente de `ActiveSessionCloser` (issue #375) y porque sus consumidores son el núcleo. Cualquiera de las dos cumple `AR-01`/`AR-02` | `1.14-c` |

---

## 11 · Hallazgos fuera del ámbito de este ADR (para issue; no se corrigen aquí)

1. **La SPA no cumple `REQ-CORE/funcional.md §12.3.5`, último párrafo** (hecho 12). El *guard* ignora `mfa.enforced` de `GET /me`, y la rama `403 mfa-enrollment-required` de `useSession.fetchMe()` no se alcanza contra la API real, porque `/me` está exento (la prueba `LoginView.session.integration.spec.ts` la simula con un `getMe` que la API nunca devolvería). Contradicción entre código y documentación: severidad **Media** como mínimo (`CLAUDE.md §6.6`). Si se aprueba `OPEN-067-03`, `1.14-c` la cierra.
2. **El comentario de `MfaChallengesController`** dice que el *endpoint* «tiene que estar en la lista blanca de `RequireMfaEnrollment`», y no está. No es un fallo, porque la petición llega sin `User` y el muro no actúa, pero el comentario es falso. Baja.
3. **`docs/adr/README.md` no tiene índice de ADR**, solo la plantilla. El índice es la sección 18 de `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md`. Este ADR se referencia allí.

---

## 12 · Cambios que este ADR pide a otros documentos y al código (al aprobarse; no los hace `architect`)

- **`REQ-FAM-UNIT`**: `funcional.md` §4.10 punto 3, §7.5, §10 (nombre de ruta `fam-unit-terms-wall`), §12.7 (`CA-FAM-UNIT-070` con la lista efectiva de `§5.4`) y §13 (`OPEN-FAM-UNIT-12`: diseño en `ADR-067`). `api.md` §2.8 (exentas = L3, sin `params.version` si se aprueba `OPEN-067-05`). `permisos.md` (L1 con sus nombres de ruta).
- **`REQ-AUTH`**: las referencias a `RequireMfaEnrollment` en `funcional.md §C.4.9` y `api.md §8` (posición 10) pasan a citar `RequireSessionWalls` y `MfaEnrollmentWall`.
- **`REQ-CORE`**: `funcional.md §12.3.4`/`§12.3.5` (`session_walls`, *guard*) y `api.md §3` (`GET /me`).
- **`REQ-BO`**: `CA-BO-011` cita el alias nuevo.
- **`ARCHITECTURE.md`**: el registro de muros de sesión como punto de extensión del núcleo, junto a `ScopeResolverRegistry`.
- **Código** (lo hace `implementer` en `1.14-c`): las piezas de `§5`, los tests de `§7`, las ampliaciones aprobadas de `§6` y la traducción del `type` nuevo en los cuatro idiomas.
