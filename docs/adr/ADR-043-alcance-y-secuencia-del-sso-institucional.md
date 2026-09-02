# ADR-043 · Alcance y secuencia del SSO institucional (`REQ-AUTH-004`): OIDC y aprovisionamiento primero, SAML 2.0 en un paso propio

**Estado**: **ACEPTADA** (2026-09-01, por el usuario, vía `AskUserQuestion`). `PLAN-IMPLEMENTACION.md` y la sección 18 de `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` actualizados en consecuencia.

**Decisiones del usuario sobre las preguntas abiertas de `§8`** (2026-09-01):
- **`§8.1`** (crear vs. emparejar): **solo emparejar**. `1.4b` nunca crea `Person`/`User` nuevos; vincula con una cuenta ya existente en el censo. La creación automática queda fuera de alcance de `1.4b` (no se descarta para el futuro, pero no se implementa aquí — si se retoma, exige resolver antes los cinco puntos de `§8.1`).
- **`§8.2`** (secreto de cliente por tenant): **cifrado en tabla propia**, con la clave de aplicación. Coherente con `ADR-037 §7` (sin gestor externo); el material sensible vive cifrado en la base de datos, con la implicación de que las copias de seguridad también lo contienen cifrado — `spec-writer` debe fijar el mecanismo de cifrado concreto y quién puede leerlo en claro.
- **`§8.3`** (quién configura el IdP): **el administrador del centro**, en autoservicio. `1.4b` incluye pantallas, permisos y validación de metadatos de proveedor por tenant — no es una operación de `1.6`/`REQ-BO`.
- `§8.4` y `§8.5` siguen abiertas, para `spec-writer`/`1.4c` con la posición por defecto que ya fija este ADR (no a las dos).
**Fecha**: 2026-09-01
**Resuelve**: la evaluación previa de impacto que el propio `PLAN-IMPLEMENTACION.md` pide para el paso **1.4b**, etiquetado `[OPUS + SONNET]` con el encargo literal de *«valorar con `architect` el impacto en el modelo de identidad cerrado en 1.1»*
**Se apoya en**: `INV-001` (aislamiento a nivel de framework), `INV-002` (denegar por defecto), `INV-008` (datos de menores: base legal y consentimiento del tutor), `CLAUDE.md §8` (secretos en gestor de secretos), `CLAUDE.md §9` (expand/contract), `RNF-MANT-007` (dependencia externa tras interfaz propia), `ADR-033 §2` (el tenant se resuelve por el host antes de tocar datos), `ADR-034` + `OPEN-13` (mínimo de `people` y base legal por campo), `ADR-037 §7` (secretos por `EnvironmentFile=`, sin gestor externo), `ADR-042` (que declara explícitamente no decidir nada de este paso)
**Afecta a**: el paso **1.4b** y el paso nuevo **1.4c** que esta decisión crea. Es entrada obligatoria de `spec-writer`
**No decide**: la biblioteca de SAML (`§7.3` da la comparación y **no** la conclusión: es una aprobación de dependencia del usuario, con su ADR propio, igual que `OPEN-AUTH-35` → `ADR-042`); si el aprovisionamiento automático crea personas o solo las empareja (`§8.1`, decisión de producto); dónde vive el secreto de cliente por tenant (`§8.2`); ni el modelo de datos, los endpoints ni los permisos, que son de `docs/modulos/REQ-AUTH/*.md`
**Precedente que sigue**: `ADR-031` y `ADR-032` — ADR de **alcance y fase** sobre un módulo aún sin construir, escrito antes de la especificación precisamente para que la especificación no tenga que descubrirlo

**Ampliación `§10`** (2026-09-02, apertura de `1.4c`): con `1.4b` ya mezclado (PR #149, `8f439d4`), `§10` reevalúa en vivo la comparación de bibliotecas de `§7.3` —que resulta **equivocada en dos de sus cuatro observaciones**—, da la recomendación firme que `§7.3` difirió, y contrasta el diseño de `1.4c` contra el catálogo realmente construido. `§10.4` **matiza `§3.1`**: el catálogo sirve, pero la reutilización **no es aditiva** como el enunciado de `§3.1` daba a entender. Ninguna decisión de `§1`-`§9` se revoca.

---

## Contexto

`REQ-AUTH-004` son cuatro líneas en la sección 5.2 del documento de requisitos:

> - SAML 2.0 para sistemas de identidad institucionales.
> - OIDC para Azure AD / Entra ID, Google Workspace, etc.
> - Mapeo automático de atributos SAML/OIDC a campos de usuario.
> - Just-in-Time provisioning: creación automática de usuarios en el primer login SSO.

Cuatro líneas que en el plan ocupan **un** paso, `1.4b`. El paso anterior, `1.4`, se cerró el 2026-09-01 y su propio `datos.md §E.1` lo describe como *«el paso más pequeño del módulo en datos desde 1.2b»*: **una tabla nueva y una columna**. Este ADR existe porque `1.4b`, tal como está escrito en el plan, no se parece en nada a eso, y conviene decirlo antes de que `spec-writer` intente escribir una sola especificación para todo.

Las cuatro líneas no son cuatro funcionalidades del mismo tamaño. Son **tres subsistemas distintos**:

1. **Un catálogo de proveedores de identidad por tenant**, con metadatos, material criptográfico y su ciclo de vida (caducidad y rotación de certificados). No existe hoy nada parecido: la configuración del proveedor externo de `1.4` es una **variable de entorno global del despliegue** (`AUTH_OAUTH_DRIVER`, `operacion.md §E.2.1`), no una fila por centro.
2. **Dos protocolos**, OIDC y SAML 2.0, que solo comparten el nombre «SSO». `§2` desarrolla por qué la diferencia no es de biblioteca.
3. **El aprovisionamiento automático**, que es la primera vía del producto capaz de crear filas en `people` y `users` sin que un administrador humano intervenga — el camino que `OPEN-AUTH-31` cerró en `1.4` y difirió **explícitamente aquí**.

Y hay un cuarto asunto que el requisito no nombra y que cae de lleno en el paso: **`INV-008`**. Un directorio institucional de un centro educativo no contiene solo adultos. Google Workspace for Education y Entra ID en centros escolares contienen rutinariamente cuentas de **alumnado**. `§4` lo trata como lo que es: el riesgo mayor de este paso, mayor que cualquier decisión de protocolo.

### Lo que se ha verificado en el repositorio, y no supuesto

Todo lo que sigue está leído del repositorio o consultado en vivo el 2026-09-01, no recordado:

| Hecho | Dónde |
|-------|-------|
| `users.password` es `text` **`NOT NULL`** | `2026_08_18_100300_rebuild_users_table.php:44` |
| `users.status` con `CHECK IN ('activo','inactivo','pendiente')`, y **solo `activo` puede iniciar sesión** | misma migración, línea 51; `RN-AUTH-23` |
| `UNIQUE (tenant_id, person_id) WHERE deleted_at IS NULL` — como mucho una cuenta viva por persona | misma migración |
| Columnas de `people`: `given_name`, `family_name_1`, `family_name_2`, `birth_date`, `document_type`, `document_number`, `contact_email`, `contact_phone`, `locale`. **Sin fotografía, sexo, nacionalidad ni dirección postal**, a propósito | `ADR-034 §1`, `OPEN-13` |
| `user_identities` tiene `UNIQUE (tenant_id, provider, subject)` y `UNIQUE (tenant_id, user_id, provider)`, ambos parciales | `docs/modulos/REQ-AUTH/datos.md §E.2` |
| `ExternalIdentityProvider` es una interfaz **sin parámetros**, de un solo proveedor, que lee configuración global | `ADR-042 §4.3` |
| `AUTH_OAUTH_DRIVER` es una variable de entorno del despliegue con tres valores (`none`/`google`/`fake`) | `docs/modulos/REQ-AUTH/operacion.md §E.2.1` |
| `config/session.php`: `same_site` = **`lax`** por defecto (`SESSION_SAME_SITE`) | `apps/api/config/session.php:202` |
| `Socialite::buildProvider($clase, ['client_id','client_secret','redirect'])` **existe** en la versión instalada | `apps/api/vendor/laravel/socialite/src/SocialiteManager.php:240` |
| `Two\AbstractProvider::getAuthUrl()` y `getTokenUrl()` son **abstractas de instancia** | `apps/api/vendor/laravel/socialite/src/Two/AbstractProvider.php:131,138` |
| Los secretos de producción se entregan por `EnvironmentFile=`, **sin gestor externo**, decisión razonada | `ADR-037 §7` |
| `identity_providers` es un nombre **reservado y sin ocupar** para este paso | `docs/modulos/REQ-AUTH/datos.md §E.1.1` |

---

## 1 · Opciones reales

No las teóricas. Son cuatro, y solo la primera es la que hay hoy en el plan.

1. **Un solo paso `1.4b`** con las cuatro líneas del requisito.
2. **Dividir por protocolo**: `1.4b` = catálogo por tenant + OIDC + mapeo + aprovisionamiento; `1.4c` = SAML 2.0 sobre el catálogo ya construido.
3. **Dividir por capa**: `1.4b` = catálogo y aprovisionamiento con un solo protocolo mínimo; `1.4c` = «los protocolos de verdad», OIDC completo y SAML.
4. **Delegar SAML en un intermediario externo** (Keycloak, Authentik como contenedor propio): el producto habla solo OIDC y el intermediario traduce SAML hacia la institución. `REQ-AUTH-004` quedaría cubierto con **un** protocolo en nuestro código.

La opción 4 no es una boutade y se evalúa en serio en `§7.2`: es la única que elimina de nuestro código la superficie de firma XML entera, que es donde `§2.3` demuestra que está el riesgo.

---

## 2 · Por qué OIDC y SAML no son «el mismo paso con otra biblioteca»

Es el núcleo de este ADR. Si fueran dos adaptadores del mismo flujo, dividir sería burocracia.

### 2.1 SAML rompe el mecanismo con el que `1.4` sostiene el `state` y el tenant

`1.4` decidió, y lo escribió como logro (`datos.md §E.1`), que **el `state` de OAuth y el verificador PKCE viven en el *payload* de la sesión del servidor y no en base de datos**. Pudo hacerlo porque el *callback* de OIDC es una **navegación `GET` de nivel superior**, y `same_site = lax` sí envía la cookie en ese caso — `ADR-042 §6` incluso anotó que `SESSION_SAME_SITE=strict` lo rompería.

**Con SAML esa propiedad desaparece, y no hace falta endurecer nada para perderla.** El *binding* HTTP-POST de SAML 2.0 entrega la aserción como un **formulario `POST` entre sitios** desde el IdP hacia nuestro ACS URL. `SameSite=Lax` **no** envía la cookie en un `POST` entre sitios: solo en navegaciones `GET` de nivel superior. Es decir:

> El ACS de SAML aterriza en la aplicación **sin la cookie de sesión**, con la configuración actual y por defecto.

Consecuencias encadenadas, todas verificables contra lo ya escrito:

- No hay sesión donde guardar la petición, así que la correlación petición↔respuesta (`InResponseTo`) exige **almacenamiento en servidor**: exactamente la tabla `oauth_authorization_requests` que `OPEN-AUTH-30` eligió la opción A para no crear.
- **Pero aquí esa objeción no aplica igual**, y hay que decirlo porque cambia el resultado: lo que hacía inaceptable aquella tabla era que iba **fuera del sistema de tenancy, sin `tenant_id` y con RLS imposible** (`datos.md §E.1`). Con un ACS URL propio por tenant, el tenant queda resuelto por el host **antes** de tocar datos (`ADR-033 §2`), así que la tabla equivalente de SAML **sí puede llevar `tenant_id` y RLS ordinaria**. No es la misma tabla ni el mismo problema.
- El resto de la cadena de *middleware* de sesión y CSRF que `1.4` reutilizó tal cual **no se reutiliza**: una petición sin cookie no pasa por donde pasaba la otra.

Esto solo no bastaría para dividir el paso. Sumado a lo que viene, sí.

### 2.2 SAML no comparte ni la dependencia, ni el envoltorio, ni el objeto de valor

`ADR-042 §4.3` fijó `ExternalIdentityProvider` con dos verbos y un `ExternalIdentity` con siete propiedades, y escribió a propósito que la interfaz **sirve a un solo proveedor** y que `1.4b` decidirá si hace falta un registro. Al mirarlo con SAML delante:

- **OIDC institucional cabe en esa forma casi tal cual.** Lo que le falta es que el proveedor se construya con configuración **de este tenant** en vez de global; y eso ya es posible sin dependencia nueva, porque `buildProvider()` existe y `getAuthUrl()`/`getTokenUrl()` son abstractas de instancia (verificado, `§Contexto`). Un proveedor OIDC genérico parametrizado por emisor es una clase nuestra sobre `AbstractProvider`, no un paquete más.
- **SAML no cabe.** No hay `client_secret` sino un par de claves; no hay canje de código sino una aserción firmada; no hay `sub` garantizado sino un `NameID` con formato negociable; no hay `email_verified` en absoluto — y `email_verified` es literalmente la propiedad sobre la que `ADR-042` construyó su argumento entero.

La consecuencia es concreta: `ExternalIdentity` **no** puede ser el mismo objeto de valor para los dos sin convertirse en una bolsa de campos opcionales, que es el fallo que `ADR-041 §1.4` describió como *«una interfaz que la mitad de sus implementaciones no puede cumplir es peor que dos interfaces»*.

### 2.3 El perfil de riesgo es de otro orden, y hay datos

`CLAUDE.md §1` obliga a comprobar mantenimiento, licencia y cadencia. Para SAML hay que mirar además **el historial de avisos de seguridad**, porque es lo que distingue a esta familia de bibliotecas de cualquier otra del proyecto. Consultado en vivo contra `packagist.org/api/security-advisories` el 2026-09-01:

| Biblioteca | Avisos históricos, y de qué van |
|------------|--------------------------------|
| `onelogin/php-saml` (hoy `SAML-Toolkits/php-saml`) | **3**, el más reciente `CVE-2025-66475`, que afecta a `>=4.0.0,<4.3.1` — es decir, **de este ciclo**. Los otros dos: *«An error during signature verification can be treated as a successful verification»* y *«Response Wrapping attacks resulting in a malicious user gaining unauthorized access»* |
| `simplesamlphp/saml2` | **9**, entre ellos cuatro con título literal *«Incorrect signature validation»*/*«Incorrect signature verification»*, un XXE, y uno que afecta a `>=6.0.0,<6.2.1` descrito como *«cross-IdP authentication bypass»* |
| `robrichards/xmlseclibs` (dependencia común de las dos anteriores) | **4**, uno de ellos *«Critical signature bypass»* y otro un *bypass* de canonicalización de libxml2 |
| `litesaml/lightsaml` | **0** — con la advertencia de `§7.3`: es un *fork* reciente de un proyecto abandonado, y cero avisos con poca adopción no es lo mismo que cero avisos con mucha |

Ninguna versión vigente está afectada: todo lo listado está corregido. **Ese no es el punto.** El punto es el **patrón**: en SAML, el modo de fallo característico es *«la firma no se valida y el sistema cree que sí»*, se ha materializado repetidamente en **todas** las implementaciones PHP, y sigue apareciendo en 2025-2026. Comparado con esto, `laravel/socialite` acumula dos avisos, **ambos de 2015** (`ADR-042 §2`).

Lo que eso significa para este proyecto en concreto, que es lo que importa: con **un solo desarrollador** (`CLAUDE.md §2`) y un horizonte de tres años, adoptar SAML no es adoptar una biblioteca, es adquirir **la obligación permanente de seguir sus avisos y parchear rápido**, sobre el componente que decide quién entra en un sistema con datos de menores. Eso hay que aceptarlo con los ojos abiertos y con tiempo dedicado a ello, no de refilón dentro de un paso que además tiene que inventar un catálogo por tenant y el aprovisionamiento automático.

### 2.4 El ciclo de vida del certificado es un subsistema, no una columna

El certificado de firma de un IdP **caduca**, típicamente entre uno y tres años, y se rota. Un diseño con una columna `certificate` produce, el día del vencimiento, **una caída total del acceso de ese centro sin ningún aviso previo**, con un mensaje que no apunta al certificado. Hacerlo bien exige varios certificados válidos simultáneamente (ventana de rotación) y vigilancia de vencimiento con antelación. Eso es una tabla hija, un aviso operativo y una tarea programada. OIDC no tiene este problema: el JWKS del emisor se descubre y rota solo.

### 2.5 Lo que sí comparten, y por eso el orden importa

Comparten **todo lo que no es el protocolo**: el catálogo por tenant, el mapeo de atributos, el aprovisionamiento, el re-tecleado de `user_identities` (`§3`), la resolución de tenant por host, la política de MFA y la auditoría. Es decir: **el paso que va primero paga la infraestructura y el segundo añade un adaptador**. Esa asimetría es la que decide el orden en `§3`.

---

## 3 · Decisión

### 3.1 `REQ-AUTH-004` se implementa en dos pasos, no en uno

**Nada se retira del alcance del requisito.** Sigue siendo MUST de fase 1, entero. Se divide en:

| Paso | Contenido | Modelo |
|------|-----------|--------|
| **1.4b · SSO institucional (OIDC) y aprovisionamiento** | Catálogo `identity_providers` por tenant; OIDC genérico por tenant (Entra ID, Google Workspace y cualquier emisor conforme); mapeo de atributos; aprovisionamiento en primer acceso; re-tecleado de `user_identities` | **Construye el modelo** |
| **1.4c · SSO institucional (SAML 2.0)** | Adaptador SAML sobre el catálogo ya existente: metadatos, ACS, certificados y su rotación, correlación de petición en servidor | **Añade un protocolo, no un modelo** |

### 3.2 El corte va por protocolo, y ese es el criterio

Se corta donde cambian **cuatro cosas a la vez** y solo ahí: el mecanismo de sesión (`§2.1`), la dependencia y su envoltorio (`§2.2`), el perfil de riesgo (`§2.3`) y el ciclo de vida del material criptográfico (`§2.4`). Cualquier otro corte deja las cuatro repartidas entre los dos pasos.

### 3.3 OIDC va primero, y no es por ser el fácil

Tres razones, en orden de peso:

1. **Paga la infraestructura común** (`§2.5`). Si SAML fuera primero, `1.4c` heredaría el catálogo igual, pero lo habría diseñado quien tenía delante el protocolo **menos** parecido a lo que ya existe — y el catálogo tiene que servir a los dos.
2. **Es continuidad verificada, no un salto.** `1.4` acaba de cerrarse con OIDC funcionando de extremo a extremo, con envoltorio, proveedor simulado y verificación en navegador real. La distancia entre eso y OIDC institucional es *configuración por tenant* más *aprovisionamiento*: dos problemas nuestros, sin protocolo nuevo y **sin dependencia nueva** (`§2.2`).
3. **Concentra el riesgo real donde está.** El riesgo grande de `1.4b` no es OIDC: es `INV-008` y el aprovisionamiento (`§4`). Meterlo en el mismo paso que la superficie de firma XML garantiza que uno de los dos se revise peor.

### 3.4 Fuera del alcance de los dos pasos, explícitamente

Se enumeran para que no reaparezcan como «obvios» durante la especificación. **Ninguno está en el literal del requisito.**

| Fuera | Por qué |
|-------|---------|
| **Single Logout (SLO)**, SAML u OIDC | No lo pide el requisito. En SAML es el mecanismo con peor interoperabilidad real del estándar. Cerrar sesión en nuestro lado ya funciona desde `1.2b`, incluido el cierre remoto |
| **SCIM y sincronización de directorio** | No lo pide el requisito. El censo del centro es de `REQ-ALUM`/`REQ-RRHH`, no del IdP. Sincronizar un directorio es un módulo, no una línea |
| **SSO iniciado por el IdP** (aserción no solicitada) | Lo decide `1.4c` con su argumento delante (`§8.4`). Por defecto **no**: sin petición previa no hay `InResponseTo` que correlacionar ni protección contra repetición |
| **Que el segundo factor del IdP exima del nuestro** | `funcional.md §C.12` lo dejó pendiente «para 1.4b». Se mantiene abierto (`§8.5`) y **la posición por defecto es que no exime** (`INV-002`) |
| **Convertir el SSO en la única puerta de entrada** | `RN-AUTH-96` garantiza hoy que nadie depende de un tercero para entrar. Retirarlo es una decisión de disponibilidad con su propio ADR, no un efecto lateral de este paso |

### 3.5 Qué queda fijado como restricción de diseño para `spec-writer`

No es modelo de datos —eso es de `datos.md`— pero sí son límites que la especificación no puede cruzar sin volver aquí:

1. **El catálogo de proveedores es una tabla de tenant**, con `tenant_id`, RLS `ENABLE`+`FORCE` y política estándar (`ADR-033 §6`), como cualquier otra. No hay excepción de tenancy en este paso (`§2.1` explica por qué SAML tampoco la necesita).
2. **`user_identities` se re-teclea por proveedor concreto, no por protocolo** (`§3.6`).
3. **El mapeo de atributos escribe sobre una lista blanca cerrada de campos destino**, nunca sobre un destino libre (`§4.3`).
4. **El aprovisionamiento nunca concede roles por sí mismo** (`§4.4`).
5. **Ningún certificado, clave privada ni secreto de cliente aparece en `audit_logs`**, ni siquiera redactado por patrón: se declara a mano, como `datos.md §E.2` tuvo que hacer con `subject` (`config('audit.secret_attribute_patterns')` no cubre `certificate` ni `metadata`).

### 3.6 `user_identities`: la tabla sirve, la clave no

Es la respuesta concreta a *«¿sirve `UserIdentity` tal cual?»*, y es **medio sí**.

La **forma** sirve: columnas, política de auditoría, `link_method`, `public_id`, endpoints de autoservicio y camino de supresión valen igual para una identidad institucional. Ampliar el `CHECK` de `provider` y el de `link_method` es aditivo, y `datos.md §E.2` ya lo dejó previsto por escrito.

La **clave de unicidad no sirve**, y esto no es una preferencia sino un error de corrección:

```
UNIQUE (tenant_id, provider, subject) WHERE deleted_at IS NULL
UNIQUE (tenant_id, user_id, provider) WHERE deleted_at IS NULL
```

Ambas suponen que `provider` **identifica al emisor**. Con `provider = 'google'` es cierto: hay un solo Google. Con `provider = 'oidc'` o `'saml'` es **falso**, por dos motivos independientes:

- **Un centro puede tener más de un IdP institucional a la vez.** Es el caso normal, no el raro: una migración de ADFS a Entra ID convive meses con los dos. Con la clave actual, el segundo IdP del centro no se puede dar de alta.
- **`subject` solo es único dentro de su emisor.** El `sub` de OIDC lo es por emisor, no globalmente; y un `NameID` de SAML puede ser `jdoe` o un correo, que no son únicos ni por asomo. Dos IdP distintos pueden emitir legítimamente el mismo `subject` para **dos personas distintas**, y con la clave actual el segundo quedaría vinculado al usuario del primero. Eso es apropiación de cuenta por colisión de configuración.

Por tanto: `user_identities` necesita **una clave foránea al proveedor concreto** y sus dos únicos re-tecleados sobre ella. Es un cambio de forma real, y por `CLAUDE.md §9` se hace en expand/contract: columna nueva *nullable* (las filas de Google de `1.4` no tienen catálogo detrás), índices nuevos, y retirada de los antiguos dos versiones después. **Lo decide `datos.md`; lo que este ADR fija es que la clave actual no puede sobrevivir sin cambio.**

Un matiz que la especificación tiene que resolver y no puede dejar implícito: el `CHECK (link_method <> 'fusion_automatica' OR email_verified_at_link)` —descrito en `datos.md §E.2` como *«la restricción más importante de la tabla»*— **está construido sobre un *claim* de OIDC que SAML no tiene**. La confianza en una aserción institucional no viene de un `email_verified`: viene de que el centro configuró ese IdP como suyo. Es un argumento distinto y hay que escribirlo como tal, no dejar que el `CHECK` se rellene con un `true` de conveniencia que vacíe la garantía sin que se note.

---

## 4 · El asunto grave: aprovisionamiento automático e `INV-008`

Se separa del resto porque es el mayor riesgo del paso, y no es de protocolo.

### 4.1 El directorio de un centro contiene menores

`OPEN-AUTH-31` se resolvió en `1.4` con tres argumentos, y el segundo fue que el alta automática *«fabrica `people` duplicadas»*. Ese argumento **sigue vigente aquí y es más fuerte**, porque el censo contra el que se duplicaría es mayor. Pero se le suma uno que en `1.4` no aplicaba, porque allí el proveedor era Google genérico y aquí es el directorio del centro:

> Google Workspace for Education y Entra ID en centros escolares **contienen cuentas de alumnado**. Un aprovisionamiento automático que confíe en la aserción crea una `Person` de un menor **sin base legal registrada y sin consentimiento del tutor**. Eso es `INV-008` incumplido, no rozado.

Agravantes verificados, no supuestos:

- **`people.birth_date` es *nullable*** (`ADR-034 §1`). Si el IdP no manda fecha de nacimiento —y casi ninguno lo hace— **el sistema no sabe que acaba de crear el registro de un menor**. No hay siquiera una comprobación posible en el momento del alta.
- **El emparejamiento contra el censo no puede hacerse por documento**: una aserción SAML/OIDC no lleva DNI/NIE. Solo queda el correo, que es un identificador débil para decidir *«esta persona ya está en el censo»*.

### 4.2 De ahí sale la recomendación, y es un «no» acotado

Hay **dos cosas distintas** metidas en la expresión «Just-in-Time provisioning», y el requisito no las distingue:

- **Emparejar** (*JIT linking*): el primer acceso SSO vincula la identidad externa con una `Person`/`User` **que ya existe** en el censo, sin que nadie tenga que canjear una invitación. Es lo que resuelve el problema real de un centro con 80 docentes: nadie gestiona 80 invitaciones.
- **Crear** (*JIT creation*): el primer acceso SSO **crea** la `Person` y el `User`. Es lo que dispara `§4.1`.

**Recomendación: `1.4b` entrega el emparejamiento, y la creación queda condicionada a que el usuario la apruebe explícitamente con `§8.1` delante.** Con dos observaciones honestas, porque esto es acotar un requisito MUST:

1. El emparejamiento cubre el valor de producto que el requisito persigue —«que el personal entre con las credenciales del centro sin gestión de altas»— y no toca `INV-008`, porque la persona ya está en el censo con su base legal.
2. **No es diferir por segunda vez lo mismo.** `OPEN-AUTH-31` difirió la creación *«a `REQ-AUTH-004`, donde el proveedor de identidad es el directorio del propio centro»*, y ese razonamiento sigue siendo bueno. Lo que este ADR aporta es que **el directorio del propio centro también contiene alumnado**, un hecho que en aquella decisión no se pesó. Si el usuario quiere la creación, `§8.1` dice qué hace falta para que sea defendible; no se está pidiendo abandonarla.

### 4.3 Qué puede rellenar el mapeo de atributos, hoy

Pregunta literal del encargo. Contra el estado real de `ADR-034`/`OPEN-13`:

| Campo de `people` | ¿Puede rellenarlo el IdP? |
|-------------------|---------------------------|
| `given_name` | **Sí** |
| `family_name_1`, `family_name_2` | **Sí, pero nunca partiendo una cadena.** `ADR-042 §4.6` ya prohibió la heurística de división, y su argumento («García de la Torre») no ha cambiado. Si el IdP manda un solo apellido, va entero a `family_name_1` y `family_name_2` queda `NULL` |
| `contact_email` | **Sí** |
| `locale` | **Sí**, si el valor está en `{es-ES,en,de,fr}`; si no, el del centro |
| `contact_phone` | **Con reservas**: sale del directorio y suele ser el teléfono corporativo, no el personal. Aceptable, pero no es un campo que el SSO tenga que resolver |
| `birth_date` | **No en el alta.** Rara vez viene, y si viniera es el dato que determina si hay un menor delante: no debe entrar como atributo mapeado más, sino con la decisión de `§4.1` tomada |
| `document_type` / `document_number` | **No.** Identificador oficial con unicidad garantizada por índice. Un mapeo mal configurado provocaría colisiones de unicidad contra el censo real |
| **Fotografía** | **No existe la columna, y no por olvido.** `ADR-034 §1` la dejó fuera y `OPEN-AUTH-37` cerró que no se guarda. `OPEN-13` sigue sin catálogo de bases legales. **El requisito no puede cumplirse en esta parte y hay que decirlo, no fabricar la columna por la puerta de atrás** |

**Y de ahí la restricción de `§3.5` punto 3**: el mapeo configurable por el administrador es una **lista blanca cerrada de destinos**, no un destino libre. Un mapeo libre permite a un administrador de centro dirigir un atributo arbitrario del IdP hacia `document_number` o `birth_date`; es una superficie de protección de datos abierta a cambio de flexibilidad sobre **cuatro o cinco campos**. Complejidad sin beneficio proporcional: no.

### 4.4 Si el proveedor no manda un atributo obligatorio

Solo hay un atributo sin el cual no se puede hacer nada: el **identificador estable** (`sub` / `NameID`). Sin él, el acceso se rechaza; no hay alternativa que no sea identificar por correo, y eso ya está descartado en `1.4` (`ADR-042 §3`, trampa 3) porque un correo se reasigna.

Para el resto, la regla que se propone es **fallo en cerrado, y el fallo no es inventar**: si falta un atributo necesario para el emparejamiento, el acceso termina sin vincular y sin crear, con la misma respuesta genérica e indistinguible que `1.4` ya usa para `federado_sin_vinculo` (`funcional.md §E.4.6`) — porque distinguir «no tienes cuenta» de «tu IdP no manda el atributo» convierte el botón de SSO en un comprobador de altas del centro. La causa concreta va a la telemetría y al aviso operativo, no a la respuesta.

### 4.5 Aprovisionamiento y `INV-002`

Pregunta literal del encargo, y la respuesta es la posición conservadora:

- **El usuario aprovisionado nace con cero roles.** El sistema ya deniega por defecto, así que una cuenta sin roles autentica y no puede hacer nada. **No existe rol por defecto**, y proponer uno sería inventar un requisito (`CLAUDE.md §11`). Los 16 roles de tenant se siembran en `tenant:provision-defaults` y se asignan por acto administrativo.
- **`users.status`**: el `CHECK` actual admite `activo`, `inactivo`, `pendiente`, y `RN-AUTH-23` deja entrar solo a `activo`. Si el aprovisionamiento crea con `pendiente`, la persona **no puede entrar en el mismo acceso que la creó**, que es justo lo contrario de lo que pide un SSO. La especificación tiene que resolver esa tensión de forma explícita y no de refilón; es un `CHECK` que se amplía por expand si hace falta un estado nuevo.
- **Nunca aprovisionar en un rol con acceso a datos de categoría especial.** `RPERM-012` ya exige que esos permisos no estén en ningún rol por defecto, y el aprovisionamiento no puede ser el agujero por el que se concedan.

### 4.6 `users.password` es `NOT NULL`, y eso bloquea la creación

Hecho verificado. Toda cuenta fija contraseña al canjear su invitación (`RN-AUTH-20`), y `funcional.md §E.4.5` se apoya en ello por escrito: *«Ese estado no se puede alcanzar en 1.4: (...) `users.password` es `NOT NULL`, y no hay forma de crear un usuario sin ella»*. Un usuario creado por SSO no tiene contraseña. Las dos salidas reales:

| Salida | Coste | Lo que rompe |
|--------|-------|--------------|
| **`password` pasa a *nullable*** | Migración expand, y revisar cada punto que asume que hay contraseña (guarda de `§E.4.5`, cambio de contraseña, restablecimiento, `PasswordBroker` propio) | Rompe **`RN-AUTH-96`** («Google nunca es la única puerta»): aparece por primera vez un usuario que **solo** puede entrar por un tercero. Hay que decidirlo, no descubrirlo |
| **Contraseña ficticia no utilizable** | Cero migración | Peor: deja `RN-AUTH-96` **formalmente cierta y materialmente falsa**, y no hay forma de distinguir en los datos un usuario con contraseña real de uno con relleno. El día que el IdP del centro caiga, nadie puede saber quién se queda fuera |

**Recomendación: *nullable* explícito, si la creación se aprueba.** El argumento no es de elegancia: es que la segunda opción convierte una propiedad de disponibilidad en una mentira silenciosa, y este proyecto ya rechazó ese patrón cuando `ADR-042 §4.4` prohibió el `(bool)` sobre `email_verified` por la misma razón. **Si se aprueba solo el emparejamiento (`§4.2`), este problema no existe** y `users` no se toca — que es un argumento más a favor de esa recomendación.

---

## 5 · Resolución de tenant

Respuesta a la cuarta pregunta del encargo. **El patrón de `1.4` (opción A, URI propia por tenant) sigue siendo aplicable a los dos protocolos, y en SSO institucional mejora.**

### 5.1 El techo de escala de `1.4` no se hereda

`operacion.md §E.12.2` punto 2 dejó anotado el límite duro de `1.4`: *«hay un tope de URIs registradas por cliente OAuth (...) anotar como límite duro de número de centros con este diseño»*, y lo señaló como el disparador de una migración a la opción B.

**Ese tope no existe en SSO institucional**, y conviene decirlo porque cambia el cálculo: en `1.4` todos los centros comparten **nuestro** cliente OAuth en **nuestra** consola de Google, y por eso las URIs se acumulan en un solo sitio con un tope. En `REQ-AUTH-004` cada centro registra la URI **en su propio IdP**: no hay acumulación, no hay tope común y no hay disparador de migración a la opción B. La objeción que hizo dudar en `1.4` desaparece aquí.

### 5.2 A cambio, el trabajo manual cambia de manos

`operacion.md §E.12.2` punto 1 avisa de que el alta de un centro deja de ser un clic. En SSO institucional el paso manual **lo hace el administrador del centro en su IdP**, no nosotros, lo cual escala pero introduce una necesidad nueva: el producto tiene que **publicar los datos que ese administrador necesita** (nuestro identificador de entidad, ACS URL o `redirect_uri`, certificado si lo hay, atributos esperados). Es una pantalla y, en SAML, un documento de metadatos por tenant. Es trabajo de `1.4b`/`1.4c` y **es la razón por la que el catálogo por tenant tiene que existir antes que cualquier protocolo**.

### 5.3 Google Workspace **no** es un proveedor nuevo

Confirmación explícita, porque el encargo lo pregunta y porque colapsa una de las cuatro líneas del requisito: *«Google Workspace»* en `REQ-AUTH-004` es **el mismo `accounts.google.com` que ya integra `1.4`**, con el mismo cliente y el mismo flujo. Lo único que le falta para cumplir el requisito son dos cosas que ya están identificadas y **ninguna es una integración nueva**:

1. El aprovisionamiento (`§4`).
2. La restricción por dominio de Workspace, el *claim* `hd`, que **ya está abierta como `OPEN-AUTH-33`** y que `funcional.md` describe como la parte con peso de seguridad: sin ella *«un docente puede vincular su Gmail personal a su cuenta del centro»*.

**`OPEN-AUTH-33` deja de ser una pregunta opcional de `1.4` y pasa a ser trabajo obligatorio de `1.4b`**: no se puede afirmar que se cubre «OIDC para Google Workspace» permitiendo que entre cualquier Gmail.

---

## 6 · Motivo

**Dividir, y no por prudencia genérica.** El criterio de `§3.2` es verificable: en la frontera OIDC/SAML cambian a la vez el mecanismo de sesión (`§2.1`, `SameSite=Lax` no acompaña un `POST` entre sitios), la dependencia y su envoltorio (`§2.2`, `ExternalIdentity` no puede describir las dos), el perfil de riesgo (`§2.3`, nueve avisos de validación de firma frente a dos de 2015) y el ciclo del material criptográfico (`§2.4`). Un paso cuyo enunciado cabe en cuatro líneas pero que cruza esas cuatro fronteras produce una especificación que nadie puede revisar entera con criterio, y este proyecto ya tiene el precedente registrado de lo que pasa cuando un paso es demasiado grande para revisarlo de una vez (`CLAUDE.md §3`, el `implementer` que recortó un endpoint del alcance aprobado en `1.3` sin que se notara hasta la revisión).

**OIDC primero, porque paga la infraestructura y concentra el riesgo donde de verdad está.** El riesgo mayor de `REQ-AUTH-004` no es SAML: es crear personas automáticamente en un sistema con datos de menores (`§4`). Ese riesgo vive en el catálogo, el mapeo y el aprovisionamiento, que son de `1.4b` y no tienen nada que ver con el protocolo. Ponerlos en el mismo paso que la superficie de firma XML garantiza que uno de los dos se revisa peor.

**Y no dividir más de dos.** La opción 3 (`§1`) cortaba por capa y suena más limpia; se descarta en `§7.1` porque dejaría un `1.4b` sin ningún protocolo entero, es decir **un paso que no se puede verificar en navegador real con un IdP de verdad**, que es exactamente el modo de cierre que este proyecto usa desde `1.2` y que `1.4` ya tuvo que dar por parcialmente pendiente (`funcional.md §E.14`). Un paso que no se puede probar de extremo a extremo no está terminado, solo escrito.

**La reversibilidad manda, y aquí es asimétrica.** Dividir un paso y luego arrepentirse cuesta un renglón del plan. No dividirlo y arrepentirse a mitad de implementación cuesta partir una especificación aprobada, con migraciones ya escritas sobre `users` y `user_identities`, es decir sobre las dos tablas del núcleo de identidad. Ante costes de error tan distintos, se elige el barato (`CLAUDE.md §0`, «prioriza lo reversible sobre lo óptimo»).

---

## 7 · Alternativas descartadas y por qué

### 7.1 Un solo paso `1.4b` con las cuatro líneas — descartada

Es lo que dice el plan hoy. Se descarta por `§2` entero: no es un paso grande, son tres subsistemas con un enunciado corto. Produciría, como mínimo: dos o tres tablas nuevas, cambio de clave en `user_identities`, posible cambio de nulabilidad en `users.password`, una dependencia nueva de alto riesgo, gestión de certificados con su rotación, secretos por tenant sin sitio donde vivir (`§8.2`) y la primera vía automática de creación de personas. Compárese con `1.4`, cuyo `datos.md` presume de **una tabla y una columna**.

### 7.2 Delegar SAML en un intermediario externo (Keycloak/Authentik) — descartada, y es la que más cuesta descartar

**Es la opción que más reduce nuestro riesgo**: elimina de nuestro código la validación de firma XML entera, es decir la superficie donde `§2.3` demuestra que están los fallos históricos, y nos deja hablando un solo protocolo.

Se descarta por consistencia con una decisión ya tomada y por el mismo argumento, no por gusto: `ADR-037 §7.1` rechazó un gestor de secretos externo por *«desproporcionado con **un** host y un operador; añade una dependencia de arranque»*, y `ADR-037 §10` lo generalizó — *«un mecanismo elegante que se degrada por falta de mantenimiento es peor que uno aburrido que se sigue ejecutando igual el día 1.000»*. Un intermediario de identidad es bastante más pesado que un gestor de secretos: es un servicio con su propia base de datos, su propio ciclo de actualizaciones, su propio modelo de configuración por tenant y **una dependencia de arranque en el camino del login**. Aplicar aquí un criterio distinto del que se aplicó a Vault sería incoherente.

**Queda registrada como la alternativa a reconsiderar en `1.4c`**, y con un disparador escrito: si al especificar SAML el coste de mantener la biblioteca elegida —seguimiento de avisos y parcheo rápido (`§2.3`)— se juzga inasumible para un operador en solitario, esta opción vuelve a la mesa con su propio ADR. Es precisamente la clase de puerta que conviene dejar barata de abrir.

### 7.3 Elegir hoy la biblioteca de SAML — descartada por procedimiento, con los datos ya recogidos

**Este ADR no aprueba ninguna dependencia**, y es deliberado: `CLAUDE.md §1` y el precedente de `OPEN-AUTH-35` → `ADR-042` establecen que la dependencia la aprueba el usuario en la fase de decisiones abiertas de la especificación, y el ADR formaliza la comprobación. Adelantarlo aquí saltaría ese procedimiento, y además `1.4c` está a un paso de distancia: **los datos de hoy no serán los datos del día de la decisión**, y en esta familia de bibliotecas eso importa más que en ninguna otra (`§2.3`).

Lo que sí se deja hecho, verificado en vivo contra `packagist.org`, `repo.packagist.org` y `api.github.com` el **2026-09-01**, para que `1.4c` no parta de cero:

| | `SAML-Toolkits/php-saml` (`onelogin/php-saml`) | `litesaml/lightsaml` | `simplesamlphp/saml2` |
|---|---|---|---|
| **Última versión** | 4.3.2 · 2026-05-07 | **5.1.0 · 2026-07-09** | **v6.3.0 · 2026-08-09** |
| **Último *push*** | 2026-08-07 | 2026-07-09 | **2026-08-22** |
| **Licencia** | **MIT** | **MIT** | **LGPL-2.1-or-later** |
| **Descargas/mes** | **1.310.567** | 296.996 | 270.326 |
| **Estrellas / *issues* abiertas** | 1.331 / 58 | 107 / **2** | 305 / 12 |
| **PHP declarado** | `>=7.3` | **`^8.4`** | `^8.2` |
| **Avisos históricos** | 3 (uno de este ciclo, `CVE-2025-66475`) | **0** | **9** |
| **Archivado / abandonado** | No (repositorio **movido** de `onelogin/` a `SAML-Toolkits/`) | No | No |

Cuatro observaciones que `1.4c` no debería tener que redescubrir:

1. **`simplesamlphp/saml2` es LGPL-2.1-or-later.** Es el único candidato que no es MIT, y el proyecto no se distribuye como software libre. La LGPL en PHP —donde no existe el enlazado dinámico que la licencia presupone— es una zona gris que conviene no pisar sin decisión consciente. **Es un motivo de licencia, no de calidad**: técnicamente es el más activo de los tres.
2. **`litesaml/lightsaml` encaja mejor que ninguno en el entorno** (`php ^8.4`, exactamente nuestra restricción) y no arrastra avisos. Pero es un *fork* de 2026 de `lightsaml/lightsaml`, **abandonado desde 2022** y marcado como tal en Packagist; 107 estrellas propias. Cero avisos con poca adopción no es lo mismo que cero avisos con mucha, y el factor autobús está sin comprobar.
3. **`onelogin/php-saml` es el más desplegado y el que más escrutinio recibe**, y su repositorio **cambió de organización** (`onelogin/` → `SAML-Toolkits/`, redirección 301 verificada), cosa que conviene saber antes de leer su historial. Declara `php >=7.3`, que en 2026 es un indicio de base de código antigua. Depende de `robrichards/xmlseclibs ^3.1.5`, que **acaba de publicar 4.0.0 el 2026-08-22** tras años en la rama 3: un cambio de mayor en la dependencia criptográfica compartida por todo el ecosistema, exactamente el tipo de dato de gobierno que `ADR-042 §2` anotó sobre `phpseclib`.
4. **Los envoltorios de Laravel se descartan de antemano**: `aacotroneo/laravel-saml2` (última versión **2019**), su *fork* `24slides/laravel-saml2` (marcado abandonado en Packagist, redirigido a `scaler-tech/laravel-saml2`, 46 *issues* abiertas). Dos saltos de abandono encadenados, y además serían un envoltorio de terceros que tendríamos que envolver a su vez para cumplir `RNF-MANT-007`.

**Límite explícito de esta comparación**: es de metadatos, no de código. `ADR-042` pudo afirmar lo que afirmó porque **leyó las fuentes** de Socialite y encontró las tres trampas de su `§3`. Aquí no se ha leído ninguna de estas tres bibliotecas. **No hay base para una recomendación firme y no se da**; lo que hay es una comparación de mantenimiento y licencia que estrecha el campo a dos candidatos MIT.

### 7.4 Interfaz genérica que sirva a OIDC y SAML a la vez — descartada

Sería la continuación natural de `ExternalIdentityProvider`, y es el error que `ADR-041 §1.4` y `ADR-042 §4.3` ya nombraron: *«una interfaz que la mitad de sus implementaciones no puede cumplir es peor que dos interfaces»*. `§2.2` lo concreta: `ExternalIdentity` lleva `emailVerified` como booleano de primera clase, y SAML no tiene ese concepto. `1.4b` decidirá si `ExternalIdentityProvider` se generaliza a un registro por tenant; **`1.4c` decidirá si SAML entra por ahí o por una interfaz propia, con su implementación delante y no antes**.

### 7.5 Aprovechar el paso para cerrar `OPEN-13` — descartada

Tentador, porque `OPEN-13` (la lista definitiva de columnas de `people` y su base legal por campo) es lo que bloquea la fotografía y lo que asoma otra vez en `§4.3`. Se descarta: `OPEN-13` **tiene dueño y no es este módulo** (`REQ-PRIV-006`), es una decisión de protección de datos y no de arquitectura, y resolverla de refilón dentro de un paso de autenticación es exactamente la puerta de atrás que `ADR-034 §1` y `funcional.md §E.13` han cerrado dos veces. Lo que sí hace este ADR es dejar escrito que **`REQ-AUTH-004` no puede cumplir la parte de fotografía de «mapeo de atributos a campos de usuario» mientras `OPEN-13` siga abierta**, y que eso es un requisito bloqueado, no un olvido de implementación.

---

## 8 · Preguntas abiertas

Las cinco son para el usuario, en la fase de decisiones abiertas de la especificación, igual que `OPEN-AUTH-30`/`31`/`35` en `1.4`. **La primera y la segunda son bloqueantes**; las otras tres no lo son para empezar, pero sí para cerrar.

### 8.1 · ¿El aprovisionamiento **crea** personas, o solo **empareja**? — BLOQUEANTE

Es la pregunta central del paso y la sucesora directa de `OPEN-AUTH-31`. `§4.2` recomienda **emparejar en `1.4b`** y condicionar la creación.

**Si la respuesta es «también crea», esto es lo que hace falta para que sea defendible**, y no es una lista de deseos: (a) una respuesta a `INV-008` que funcione **sin conocer la fecha de nacimiento** (`§4.1`), (b) el conmutador por tenant apagado por defecto, (c) el criterio con el que el centro declara qué población de su directorio es aprovisionable, (d) la decisión sobre `users.password` de `§4.6`, y (e) la regla de deduplicación contra el censo sabiendo que no hay documento con el que cruzar. **Sin (a), la recomendación de `architect` es no implementarlo**, y decirlo así en la especificación en vez de entregarlo a medias.

### 8.2 · ¿Dónde vive el secreto de cliente de cada tenant? — BLOQUEANTE

Es el conflicto más limpio del paso, entre dos cosas escritas y vigentes:

- `CLAUDE.md §8`: *«Secretos fuera del código, en gestor de secretos»*.
- `ADR-037 §7`: el mecanismo del proyecto es `EnvironmentFile=`, **por despliegue**, y se rechazó a conciencia un gestor externo.

Un `client_secret` de OIDC **por centro**, configurado por el propio centro, **no cabe en un `EnvironmentFile=`**: cambiaría con cada alta de tenant y exigiría reiniciar el servicio. Las salidas reales son tres, y ninguna es gratis: cifrado en la propia tabla con la clave de aplicación (simple, coherente con `ADR-037`, pero mete material sensible en la base de datos y en las copias de seguridad); reabrir `ADR-037 §7` para introducir un gestor externo (caro, y con un ADR sustitutivo por delante); o **evitar el secreto**, exigiendo un flujo que no lo use.

Merece atención que la tercera existe de verdad y no es un truco: **SAML no tiene `client_secret`** —usa certificados, y el privado es nuestro y uno solo, no uno por tenant—, y OIDC admite autenticación de cliente por clave privada. Es decir, **es posible que la respuesta correcta a esta pregunta reduzca el problema en lugar de resolverlo**. Merece pensarse antes de elegir dónde guardar algo.

### 8.3 · ¿Quién configura el IdP: el administrador del centro o el operador de la plataforma?

Determina si hay pantallas, permisos y validación de metadatos en `1.4b`, o si es una operación de `REQ-BO` (paso 1.6) y este paso solo consume la configuración. **Cambia el alcance de forma apreciable** y conviene decidirlo antes de escribir la especificación, no durante. Nótese que la respuesta interactúa con `§8.2`: si configura el operador, el secreto puede seguir siendo material de despliegue.

### 8.4 · ¿Se acepta SSO iniciado por el IdP? — de `1.4c`

Muchos centros esperan lanzar desde el portal del IdP. Una aserción no solicitada **no tiene petición previa que correlacionar**: sin `InResponseTo` no hay protección contra repetición ni contra CSRF de inicio de sesión. La posición por defecto de `§3.4` es **no**, y aceptarlo exige argumentarlo. Se anota aquí y no en `1.4c` porque **condiciona el modelo de `1.4b`**: si se va a aceptar, la tabla de correlación de peticiones se diseña distinta.

### 8.5 · ¿El segundo factor del IdP exime del nuestro?

Heredada de `funcional.md §C.12`, que la difirió literalmente «a 1.4b». La posición por defecto es **no exime** (`INV-002`, denegar por defecto), y es lo que `§3.4` recoge. Merece decidirse a conciencia y no por omisión: un centro con Entra ID y MFA obligatorio para todo su personal va a preguntar por qué se le pide un segundo factor dos veces, y la respuesta «porque no lo decidimos» no es buena. Es un ADR corto si la respuesta cambia, porque toca `MfaPolicy`, que es de `1.3`.

---

## 9 · Consecuencias

**A favor**

- `spec-writer` recibe un alcance que **cabe en una especificación revisable**, con las restricciones de `§3.5` fijadas y las preguntas de `§8` separadas de ellas.
- El riesgo mayor del requisito (`INV-008`, `§4`) queda identificado **antes** de la especificación y no durante la revisión de seguridad, que es donde se detectó el hallazgo equivalente en `1.3`.
- El error de clave de `user_identities` (`§3.6`) se corrige mientras la tabla tiene **cero filas institucionales**. Encontrado después, sería una migración de re-tecleado con vínculos reales dentro.
- `1.4c` empieza con el catálogo, el mapeo, el aprovisionamiento y la auditoría ya construidos y **probados en producción**: solo añade un adaptador.
- La comparación de bibliotecas de `§7.3` queda hecha y fechada, con su límite dicho (`no se ha leído el código`), así que `1.4c` la actualiza en lugar de rehacerla.

**En contra, y se asume**

- **`REQ-AUTH-004`, requisito MUST de fase 1, tarda dos pasos en estar completo.** Un centro que necesite SAML no lo tiene al cerrar `1.4b`. Es el coste directo de esta decisión y no se disimula: lo que se compra es que la parte que sí llega esté bien.
- **Aparece un paso nuevo en el plan** (`1.4c`), con su especificación, su revisión independiente y su cierre. Más ceremonia total que un paso único.
- **`§4.2` acota, de hecho, una línea del requisito** (*«creación automática de usuarios en el primer login SSO»*) a la espera de `§8.1`. Es una recomendación de `architect`, **no una decisión tomada**, y si el usuario decide lo contrario se implementa con los cinco requisitos de `§8.1` delante y la discrepancia registrada (`CLAUDE.md §0`).
- **Este ADR no aprueba ninguna dependencia**, así que `1.4c` arrancará con una decisión abierta de las que bloquean, igual que `1.4` arrancó con `OPEN-AUTH-35`.

**Reversibilidad**: **alta, y es el argumento de `§6`.** Revertir esta decisión es fusionar dos renglones del plan antes de que exista una sola línea de especificación. No crea código, no crea esquema, no crea dependencias. Lo único que sobreviviría a su reversión son los hallazgos técnicos —la clave de `user_identities` (`§3.6`), `SameSite` en el ACS (`§2.1`), `users.password` (`§4.6`), la desaparición del tope de URIs (`§5.1`)—, que son ciertos con independencia de cómo se organicen los pasos.

---

## 10 · Decisión sobre biblioteca SAML y diseño de `1.4c`

**Fecha**: 2026-09-02. **Estado**: análisis de `architect` para la apertura de `1.4c`. Las ocho decisiones de `§10.9` fueron llevadas al usuario el 2026-09-02 y **todas resueltas siguiendo la recomendación de `architect`**.

`§7.3` dejó una comparación fechada el 2026-09-01 y escribió su propio límite: *«es de metadatos, no de código (…) no hay base para una recomendación firme y no se da»*. Esta sección hace lo que faltaba: reverifica los metadatos, **lee las fuentes** de los tres candidatos como `ADR-042` leyó las de Socialite, y contrasta el diseño contra el catálogo que `1.4b` construyó de verdad, no contra el que `§3` imaginó.

### 10.1 · La reverificación cambia el resultado: `§7.3` estaba equivocada en dos observaciones

Consultado en vivo contra `packagist.org`, `repo.packagist.org`, `api.github.com/repos`, `api.github.com/advisories` y los tarballs de las tres versiones el **2026-09-02**:

| | `SAML-Toolkits/php-saml` | `litesaml/lightsaml` | `simplesamlphp/saml2` |
|---|---|---|---|
| **Última versión** | 4.3.2 · 2026-05-07 (y **3.8.2 · 2026-05-11**, rama antigua viva) | 5.1.0 · 2026-07-09 | v6.3.0 · 2026-08-09 (y `v7.0.0-rc1`, php `^8.5`) |
| **Último *commit*** | 2026-08-06 | 2026-07-09 | 2026-08-18 |
| ***Commits* últimos 12 meses** | **16** | **26** | **≥100** (tope de la consulta) |
| **Autores humanos en esos *commits*** | **1** (`pitbulk`, 15 de 16) | **1** (`william-suppo`, 23 de 26) | **3** (`tvdijen` 84, `ioigoume` 8, `monkeyiq` 2) |
| **Licencia** | MIT | MIT | **LGPL-2.1-or-later** |
| **Descargas/mes** | 1.323.998 | **293.569** | 266.770 |
| **PHP declarado** | `>=7.3` | `^8.4` | `^8.2` |
| **Avisos en Packagist / GHSA** | 3 / 3 | **0 / 0** | 9 / 9 |
| **Núcleo criptográfico** | `robrichards/xmlseclibs ^3.1.5` (2,17 M descargas/mes, 88 M totales) | `robrichards/xmlseclibs ^3.1.5` | **`simplesamlphp/xml-security ~2.3`** (v6 abandonó xmlseclibs) |

Cuatro correcciones y hallazgos que `§7.3` no tenía, y dos de ellos invierten su lectura:

**1. El «0 avisos» de `litesaml/lightsaml` no significa lo que `§7.3` supuso, y es descalificante.** `§7.3` lo leyó con la reserva de *«cero avisos con poca adopción no es lo mismo que cero avisos con mucha»*. La reserva era la equivocada. Las **notas de publicación del propio proyecto** dicen esto de la versión 5.0.1 (2026-06-29):

> *«LightSAML 5.0.0 was vulnerable to an XML Signature Wrapping (XSW) attack allowing an attacker who has captured one genuine signed assertion to have LightSAML accept a fully attacker-authored assertion as IdP-signed, leading to **authentication bypass and privilege escalation**.»*

Y 5.1.0 (2026-07-09) publica bajo el epígrafe `### Security` otras dos: *«Reject Responses with duplicate assertion IDs»* y *«Reject assertions with missing or empty ID»*. Es decir: **tres correcciones de seguridad en once días, una de ellas un salto de autenticación completo, y ninguna de las tres tiene aviso en Packagist ni en la base de datos de GitHub** (verificado: `api.github.com/advisories?ecosystem=composer&affects=litesaml%2Flightsaml` devuelve **0**). El «0» de la tabla no mide la ausencia de vulnerabilidades: mide que el proyecto **no publica avisos**.

Eso choca de frente con un control que este proyecto tiene escrito: `CLAUDE.md §8`, *«cada PR pasa escaneo de dependencias»*. Sobre esta biblioteca, ese control **no funciona**: un despliegue anclado en 5.0.0 habría pasado todos los escaneos en verde mientras aceptaba aserciones falsificadas. No es un defecto del código de `lightsaml` —lo corrigieron rápido y bien—, es un defecto de **gobierno de la dependencia**, y es exactamente el eje sobre el que `§2.3` dijo que se decide esta familia.

**2. La adopción de `lightsaml` no es baja, y su *fork* no es «de 2026».** `§7.3` dijo *«fork de 2026 de un proyecto abandonado desde 2022»* y *«107 estrellas propias»*. Verificado: el repositorio `litesaml/lightsaml` se creó el **2022-05-27**, dos días antes del último *release* del original (`lightsaml/lightsaml` 2.3.5, 2022-05-29), y lleva **cuatro años** publicando. Y sus 293.569 descargas/mes **superan** a las de `simplesamlphp/saml2`; más de la mitad llegan por `socialiteproviders/saml2` (156.734/mes), que `§7.3` no vio. La observación 2 de `§7.3` debe darse por retirada: `lightsaml` se descarta, pero **no por lo que `§7.3` decía**.

**3. `simplesamlphp/saml2` v6 cambió de núcleo criptográfico, y eso empeora su perfil, no lo mejora.** `§7.3` lo llamó *«técnicamente el más activo de los tres»*, y en cadencia lo es. Pero la rama v6 **ya no depende de `robrichards/xmlseclibs`**: sustituye la firma XML por `simplesamlphp/xml-security`, una biblioteca con **3 estrellas** en GitHub y 70 de sus 85 *commits* del último año firmados por la misma persona (`tvdijen`) que firma 84 de los 100 de `saml2`. Se cambia la pieza de XML-DSig más golpeada de PHP (88 millones de descargas acumuladas, cuatro avisos históricos **encontrados y publicados**) por una escrita en casa, joven y con escrutinio externo casi nulo, en la capa donde `§2.3` demostró que están todos los fallos. Sumado a la LGPL de la observación 1 de `§7.3`, que sigue vigente.

**4. Y su propio `README.md` desaconseja usarla para lo que queremos hacer**, en mayúsculas y en la segunda línea:

> *«DO NOT USE THIS LIBRARY UNLESS YOU ARE INTIMATELY FAMILIAR WITH THE SAML2 SPECIFICATION. If you are not familiar with the SAML2 specification and are simply looking to connect your application using SAML2, you should probably use SimpleSAMLphp.»*

Es una biblioteca de **modelo de mensajes**, no un SP. Quien la usa escribe la secuencia de validación entera. Su propio equipo dirige al resto hacia la aplicación completa, que es la alternativa de `§7.2` con otro nombre. Tomarse en serio esa advertencia es tomarse en serio a sus autores.

**5. `xmlseclibs 4.0.0` salió el 2026-08-22** —confirmado— y `php-saml` y `lightsaml` siguen anclados en `^3.1.5`. Es una deuda de dependencia a vigilar, no un fallo: 3.1.5 (2026-03-13) corrige `CVE-2026-32313`, y la rama 3 sigue recibiendo mantenimiento del mismo autor (24 de 29 *commits* del último año).

### 10.2 · Lo que aparece al leer el código, que es lo que `§7.3` no pudo hacer

**`litesaml/lightsaml` 5.1.0 — `src/Action/Assertion/Inbound/AssertionSignatureValidatorAction.php`.** Tras resolver la credencial, la acción hace esto:

```php
$credential = $this->signatureValidator->validate(...);
if ($credential instanceof CredentialInterface) { /* … log OK … */ }
else {
    $this->logger->warning('Assertion signature verification was not performed', …);
}
```

Cuando la verificación **no se ha realizado**, se registra un *warning* y `doExecute()` **retorna con normalidad**: la aserción sigue viva por el resto de la tubería. El contrato lo confirma en `AbstractSignatureReader::validateMulti()`, documentado literalmente como *«Returns credential that validated the signature or **null if validation was not performed**»*. La rama que devuelve `null` es estrecha en el *binding* HTTP-POST —exige un lector de firma sin objeto `XMLSecurityDSig`— y **no se afirma aquí que sea explotable hoy**; lo que se afirma es que **el fallo está construido en abierto**: la vía «no se pudo verificar» y la vía «se verificó bien» desembocan en el mismo `return`. Es, palabra por palabra, el modo de fallo que `§2.3` describió como *«la firma no se valida y el sistema cree que sí»*, y convive con el XSW real corregido dos versiones antes.

**`SAML-Toolkits/php-saml` 4.3.2 — `src/Saml2/Settings.php` y `src/Saml2/Response.php`.** Tiene su propia trampa, y hay que decirla porque es la biblioteca que se recomienda:

- `wantMessagesSigned` por defecto **`false`** (línea 377) y `wantAssertionsSigned` por defecto **`false`** (línea 380). Sin tocarlos, `Response::isValid()` **acepta una respuesta sin firmar**: las comprobaciones de las líneas 387 y 394 solo se ejecutan si esos indicadores están activos.
- `rejectUnsolicitedResponsesWithInResponseTo` por defecto **`false`** (línea 405).
- A favor: `$_strict = true` **por defecto** (línea 46) — al contrario de lo que suele repetirse —, `wantXMLValidation = true`, y `Response::isValid($requestId)` recibe el identificador de la petición **como parámetro**, comparándolo contra `InResponseTo` y contra `SubjectConfirmationData/@InResponseTo`.

La diferencia con `lightsaml` no es que `php-saml` sea seguro y el otro no. Es **dónde vive el riesgo y cuánto cuesta cerrarlo**: en `php-saml` son **tres booleanos en un único *array* de configuración**, que nuestro envoltorio fija a `true` y un test de `1.4c` verifica por reflexión; en `lightsaml` es una tubería de acciones que hay que montar entera y en la que la rama insegura es un `return` silencioso dentro de una clase de la biblioteca. Lo primero es auditable de un vistazo por un operador en solitario dentro de tres años; lo segundo no.

**Acoplamiento a superglobales de `php-saml`, y por qué no bloquea.** `Auth::processResponse()` lee `$_POST['SAMLResponse']` directamente, y `Utils::getSelfURL()` lee `$_SERVER` con estado estático. En una aplicación tras Traefik (`ADR-028`) eso sería un riesgo real en la validación de `Destination`. **Tiene salida limpia y de primera clase**: la superficie de seguridad completa está en `new Response($settings, $xmlBase64)` + `$response->isValid($requestId)`, que reciben el mensaje y el identificador **por parámetro**. El envoltorio de `RNF-MANT-007` puede por tanto **no usar `Auth` en absoluto** en el camino de entrada, alimentar el mensaje desde `$request->input('SAMLResponse')` de Laravel, y fijar la URL propia con `Utils::setBaseURL()` a partir del *host* de tenant ya resuelto por `ResolveTenant`. Con eso, `Destination` se compara contra un valor que ponemos nosotros y no contra `$_SERVER`. Es una restricción de diseño de `1.4c`, no un impedimento.

### 10.3 · Recomendación de biblioteca: `SAML-Toolkits/php-saml`

**Recomendación firme: `onelogin/php-saml` (repositorio `SAML-Toolkits/php-saml`), serie 4.x, MIT, envuelta tras interfaz propia según `RNF-MANT-007` y usada solo por su API de bajo nivel (`Settings` + `Response`), nunca por `Auth`.**

El argumento no es que sea la mejor biblioteca SAML de PHP. Es que es **la única de las tres cuyo riesgo residual se puede gestionar por una persona sola durante tres años**, que es el criterio que `§2.3` fijó y `ADR-037 §10` generalizó (*«un mecanismo aburrido que se sigue ejecutando igual el día 1.000»*):

1. **Publica avisos.** Tres en Packagist y tres en GHSA, el más reciente de 2025-12. Es el único de los tres candidatos sobre el que `CLAUDE.md §8` («cada PR pasa escaneo de dependencias») **hace lo que promete**. Frente a `lightsaml`, donde un salto de autenticación pasó por el escáner sin ruido (`§10.1` punto 1), esto no es un matiz: es la diferencia entre tener un control y creer que se tiene.
2. **Superficie pequeña y auditable.** 6.779 líneas en 12 ficheros, con la validación entera concentrada en `Response::isValid()`. `lightsaml` son 289 ficheros y `simplesamlphp/saml2` 328. Con un solo desarrollador, poder leer entero el fichero que decide quién entra es una propiedad de mantenimiento, no una preferencia estética.
3. **Su trampa es conocida, acotada y verificable por test** (`§10.2`): tres booleanos. La de `lightsaml` es estructural.
4. **Máximo escrutinio externo.** 1,32 M descargas/mes y 50 M acumuladas, sobre `xmlseclibs`, la implementación de XML-DSig más ejercitada del ecosistema. En criptografía, el código que más gente ataca es el que menos sorpresas guarda; los tres avisos de `php-saml` y los cuatro de `xmlseclibs` son la prueba de que se mira, no de que sea peor.
5. **MIT.** Sin la zona gris de la LGPL en PHP que `§7.3` observación 1 documentó y que sigue vigente.

**Lo que se acepta al recomendarla, sin disimular:**

- **Factor autobús 1.** 15 de 16 *commits* del último año son de `pitbulk`. Es el peor dato de la biblioteca. Se mitiga por dos vías: la licencia MIT y las 6.779 líneas hacen el *fork* viable de verdad si el mantenedor desaparece; y `§10.9` decisión 8 anota el disparador para volver a `§7.2`.
- **16 *commits* en doce meses y `php >=7.3` declarado** son señales de base de código en modo conservación, no de proyecto en crecimiento. En una biblioteca de protocolo cerrado como SAML 2.0, «pocos cambios» es una señal ambivalente, no negativa; pero obliga a lo del punto siguiente.
- **Obligación permanente de seguimiento**, que `§2.3` ya exigió y que aquí se concreta: suscripción a los avisos de `onelogin/php-saml` **y** de `robrichards/xmlseclibs`, y compromiso de parcheo rápido. `xmlseclibs 4.0.0` (2026-08-22) queda en vigilancia: el día que `php-saml` mueva su `^3.1.5`, es una actualización a revisar, no a aplicar en automático.

**Implementar el núcleo SAML a mano queda descartado, y conviene decir por qué explícitamente**, porque el encargo lo pedía como posible conclusión: la validación de firma XML con canonicalización, el rechazo de XSW, XXE y de las transformadas XPath maliciosas son precisamente donde han fallado **todas** las implementaciones PHP con años de escrutinio (`§2.3`, y `§10.1` punto 1 añade una más). Escribirlo aquí sería sustituir un riesgo gestionable —una dependencia de 6.779 líneas que publica avisos— por uno sin gestionar y sin nadie mirándolo. No.

### 10.4 · El catálogo de `1.4b` sirve, pero la reutilización **no es aditiva**: matiz a `§3.1`

`§3.1` describió `1.4c` como *«adaptador SAML sobre el catálogo ya existente (…) añade un protocolo, no un modelo»*, y `§9` remató que *«solo añade un adaptador»*. **Con `identity_providers` construido delante, esa frase es demasiado optimista y hay que corregirla antes de que `spec-writer` la herede.**

Leída la migración `2026_09_01_100300_create_identity_providers_table.php`, el catálogo no es un catálogo de proveedores de identidad: es un catálogo **de proveedores OIDC**. Su propio *docblock* lo dice (*«Ninguna columna de `protocol` (SAML es 1.4c…)»*). Estas columnas son `NOT NULL` y **una fila SAML no puede rellenar ninguna con un valor verdadero**:

| Columna | Por qué no la puede rellenar una fila SAML |
|---|---|
| `discovery_url` | SAML no tiene documento de descubrimiento; tiene metadatos XML, que no son lo mismo ni se refrescan igual |
| `token_endpoint` | **No existe** en el perfil Web Browser SSO. No hay canje de código ni canal trasero: la aserción llega firmada en el `POST` |
| `client_id` | No existe. El identificador es nuestro `entityId` de SP, que es **nuestro** y no del proveedor |
| `scopes` | No existe, y el `CHECK (scopes @> '["openid"]')` obliga literalmente al valor `openid` |
| `discovery_fetched_at` | Consecuencia de la primera |
| `email_claim` | El `CHECK IN ('email','preferred_username','upn')` prohíbe los nombres de atributo SAML, que son URN (`urn:oid:0.9.2342.19200300.100.1.3`) |

Rellenarlas con valores de conveniencia para que la fila entre es exactamente el patrón que **este mismo ADR rechazó dos veces**: en `§4.6` (*«deja `RN-AUTH-96` formalmente cierta y materialmente falsa»*) y en `§3.6` (*«un `true` de conveniencia que vacíe la garantía sin que se note»*). No se hace.

**Pero la decisión de `§3.1` se mantiene, y por una razón que `§3.1` no dio y que resulta ser la buena.** Lo que hace inviable una tabla `saml_identity_providers` paralela no son las columnas: es que `1.4b` creó

```sql
FOREIGN KEY (tenant_id, identity_provider_id) REFERENCES identity_providers (tenant_id, id)
```

sobre `user_identities`, con cuatro índices únicos parciales y seis `CHECK` colgando de que esa columna esté o no informada. Un catálogo SAML aparte obligaría a una segunda columna FK *nullable* con un `CHECK` de exclusión mutua, o a una referencia polimórfica **sin clave foránea** — que es integridad referencial renunciada en la tabla que decide quién es quién. Inaceptable. **La pieza reutilizable es la clave foránea, no el conjunto de columnas.**

**Forma recomendada (decisión 2 de `§10.9`): discriminador en el padre + tabla hija 1:1 por protocolo, sin mover datos.**

1. `identity_providers` recibe `protocol text NOT NULL DEFAULT 'oidc'` con `CHECK IN ('oidc','saml')`. Aditivo puro; toda fila existente es OIDC.
2. Las seis columnas OIDC de arriba pasan de `NOT NULL` a *nullable*, **y su obligatoriedad se reexpresa como `CHECK` condicionado al protocolo** — `CHECK (protocol <> 'oidc' OR token_endpoint IS NOT NULL)`, y así con las seis. La garantía no se pierde: cambia de sitio. Los tres `CHECK` de valor (`scopes`, `email_claim`, `claims_source`) se prefijan igual con `protocol <> 'oidc' OR …`.
3. Lo específico de SAML va a **`saml_identity_provider_settings`**, 1:1 con el padre, donde sus propias columnas sí son `NOT NULL` de verdad: `idp_entity_id`, `sso_service_url`, `sso_binding` (`CHECK IN ('redirect','post')`), `name_id_format`, `sign_authn_requests`, y los nombres de atributo esperados para correo, nombre y apellidos.
4. Lo que **sí** se reutiliza tal cual, y no es poco: `tenant_id` + RLS + política estándar, `public_id`, `display_name`, `allowed_email_domains`, `provisioning_mode`, `is_enabled`, `deleted_at`, la clase de auditoría `Full` de `ADR-035 §8`, los cuatro permisos `proveedor_identidad.*`, las pantallas de autoservicio y el endpoint anónimo `GET /auth/identity-providers` que pinta los botones de la pantalla de acceso.

Se descarta **mover** las columnas OIDC a una hija `oidc_identity_provider_settings` simétrica, que sería la forma «correcta» de libro: exige reescribir una tabla viva y el código de `1.4b` que lleva días desplegado, a cambio de cero invariantes ganadas. `CLAUDE.md §0`: lo reversible antes que lo óptimo.

**El coste honesto, entonces:** `1.4c` no es «un adaptador». Es un adaptador **más** una migración expand/contract sobre una tabla viva (nulabilidad de seis columnas y reescritura de nueve `CHECK`), **más** dos tablas nuevas (`§10.6` certificados, `§10.7` correlación), **más** una dependencia de alto riesgo. Sigue siendo bastante menor que `1.4b`, y la decisión de dividir de `§3` sigue siendo la correcta — pero el enunciado de `§3.1`/`§9` se corrige aquí y no se deja que llegue intacto a la especificación.

### 10.5 · El ACS y el mecanismo de sesión: dos fallos, no uno, y una excepción de CSRF que solo es segura acompañada

`§2.1` predijo que el ACS aterrizaría sin cookie. Verificado contra `routes/api.php` y `bootstrap/app.php`, el problema es **doble**, y conviene separarlo porque tiene dos remedios distintos:

1. **No llega la cookie.** `config/session.php:202` fija `same_site = 'lax'`. Un `POST` entre sitios no la lleva. `start-session` (posición 5 de la cadena de `/api/v1`) crea entonces una sesión **nueva y vacía**. Predicho por `§2.1`, confirmado.
2. **`ValidateCsrfToken` rechaza la petición.** El *callback* de OIDC de `1.4b` (`GET /auth/oauth/oidc/callback`) esquiva esto por accidente feliz: Laravel exime `GET`. **El ACS es `POST` y no lo exime.** Con la cadena actual devolvería `419` antes de mirar la aserción. `bootstrap/app.php` **no tiene hoy ninguna lista de exenciones de CSRF**, y esa propiedad —que no exista la lista— es valiosa en sí misma.

**Recomendación (decisión 3 de `§10.9`): un grupo de rutas propio para el ACS, con pila declarada explícitamente y sin `csrf`, en vez de una lista global de exenciones.**

```
resolve-tenant → encrypt-cookies → add-queued-cookies → start-session → verify-session-tenant
```

Es decir, la cadena de `/api/v1` **menos `csrf`, `session-idle-timeout`, `resolve-locale` y `require-mfa-enrollment`**, ninguno de los cuales tiene sentido sobre una petición que por diseño llega sin sesión. `verify-session-tenant` se mantiene: sobre sesión vacía no hace nada, y si por lo que fuera llegara una sesión, `RN-AUTH-31` debe seguir aplicando. Se prefiere a `validateCsrfTokens(except: […])` por dos motivos: una lista global admite comodines y crece sin que nadie la revise, mientras que un grupo nombrado se autodocumenta y su alcance es exactamente las rutas que contiene; y porque `api.md §8` fija el orden de la cadena advirtiendo que *«un intercambio de dos posiciones aquí es un fallo de seguridad silencioso»* — una pila declarada aparte se lee y se compara, una exención global no aparece al leer la ruta.

**El riesgo de la excepción, dicho sin suavizar: un `POST` sin CSRF que establece sesión autenticada es un vector de *login CSRF*.** Un atacante hace que el navegador de la víctima envíe al ACS una aserción legítima **de la cuenta del atacante**, y la víctima queda con sesión iniciada en la cuenta ajena sin notarlo — operando y subiendo datos a una cuenta que el atacante controla y luego lee.

**Lo que lo cierra es exactamente la correlación en servidor de `§10.7` y el «no» a `§8.4`.** Si el ACS solo acepta una aserción cuyo `InResponseTo` case con una fila **viva, no consumida y no caducada** que nosotros mismos emitimos en ese navegador, la aserción del atacante no tiene contra qué casar y se rechaza. Esto convierte `§8.4` de «preferencia por prudencia» en **precondición de seguridad de la excepción de CSRF**: aceptar SSO iniciado por el IdP significa aceptar un `POST` sin CSRF y sin petición previa que correlacionar, es decir, *login CSRF* sin mitigación. `§10.9` decisión 4 lo recoge.

**Se descarta `SESSION_SAME_SITE=none`.** Resolvería el punto 1 y empeoraría la postura CSRF de **toda** la aplicación para arreglar una ruta. `ADR-042 §6` ya anotó que este ajuste es sensible en el sentido contrario.

**Cómo se establece la sesión, y por qué funciona.** `SameSite` restringe el **envío** de una cookie, no su **fijación**: la respuesta al `POST` entre sitios sí puede fijar la cookie de sesión. La secuencia recomendada es la que ya usa `1.4` en su *callback*: ACS valida → `session()->regenerate()` (obligatorio, es el punto de fijación de sesión) → autentica → **`302` a una URL propia del tenant** (`/entrar/sso?resultado=…`). Esa redirección es una navegación `GET` de nivel superior hacia nuestro propio origen, que es justo el caso que `Lax` sí acompaña, con lo que la SPA recibe la petición ya con la cookie nueva. Es el mismo mecanismo de salida de `RN-AUTH-93` (`302` con código de una lista cerrada, nunca `problem+json`), reutilizado. **Si la verificación en navegador real de `1.4c` mostrara que algún navegador no envía la cookie en esa redirección**, la salida de reserva es un vale opaco de un solo uso y vida corta en la URL de redirección, que la SPA canjea por sesión con un `POST` normal y con CSRF. Se documenta como reserva y **no se implementa por adelantado**: es complejidad real a cambio de un problema que puede no existir, y averiguarlo cuesta una prueba.

### 10.6 · Certificados del IdP: tabla hija, no columna — `§2.4` confirmado y concretado

`§2.4` lo anticipó sin verlo. Con el autoservicio de `1.4b` construido, la forma concreta es:

- **`identity_provider_certificates`**: tabla hija de tenant, `identity_provider_id`, `certificate` (PEM), `not_before`, `not_after` (**extraídos del propio certificado al subirlo**, no tecleados), `is_active`, `public_id`. **Varias filas activas a la vez** — es el requisito que obliga a la tabla: durante una rotación el IdP firma con la nueva mientras algunas aserciones en vuelo llevan la vieja. La validación intenta contra todas las activas y vigentes.
- **Quién lo sube**: el administrador del centro, mismo autoservicio y mismos permisos `proveedor_identidad.*` que `1.4b` (decisión ya tomada en `§8.3`; SAML no la reabre).
- **Validación en el momento de subir**: que sea un X.509 analizable, que `not_after` esté en el futuro, y que la clave sea de un tamaño aceptable. Un certificado ya caducado se rechaza al subirlo, no al usarlo.
- **Aviso de vencimiento**: tarea programada diaria, con precedente directo en `auth:refresh-oidc-discovery` que `1.4b` ya registró en `routes/console.php`. Avisos escalonados y una marca visible en la pantalla del proveedor.
- **`CLAUDE.md §8` y `§3.5` punto 5**: ni el PEM ni ninguna huella entran en `audit_logs`; se declara a mano, como `datos.md §E.2` tuvo que hacer con `subject`. Nótese que un certificado del IdP es **material público** (es una clave pública), a diferencia del `client_secret` de `1.4b`: no necesita cifrado en reposo. Es un dato de configuración, no un secreto, y tratarlo como secreto solo añadiría ceremonia.

**Impacto en `RNF-DISP` cuando uno caduca en uso**: el SSO de **ese** centro deja de funcionar, con un fallo que sin la tarea de aviso no apunta al certificado. **No es una caída de acceso**, y la razón es `RN-AUTH-96`: la contraseña local nunca deja de ser una puerta válida. Merece anotarse porque es el segundo argumento independiente en contra de tocar `users.password` (`§4.6`): mientras `RN-AUTH-96` se sostenga, la caducidad de un certificado es una degradación; el día que deje de sostenerse, es una caída total de un centro por un descuido de calendario.

### 10.7 · Correlación en servidor: `saml_auth_requests`, y qué protege cada pieza

`§2.1` señaló que hacía falta y que, a diferencia de la tabla que `OPEN-AUTH-30` rechazó en `1.4`, **esta sí lleva `tenant_id` y RLS ordinaria** porque el tenant se resuelve por el *host* antes de tocar datos (`ADR-033 §2`). Sigue siendo cierto. Forma concreta:

**`saml_auth_requests`** (tabla de tenant, RLS `ENABLE`+`FORCE`, política estándar de `ADR-033 §6`): `identity_provider_id` (FK), `request_id` (el `ID` del `AuthnRequest` que emitimos; único por tenant), `intent` (`CHECK IN ('login','link')`), `linking_user_id` *nullable*, `created_at`, `expires_at`, `consumed_at` *nullable*.

Qué protege cada columna, que es lo que hay que justificar y no dar por obvio:

- **`request_id` + `consumed_at`**: la fila es de **un solo uso**. Case el `InResponseTo`, se marca consumida en la **misma transacción** en que se valida (`UPDATE … WHERE consumed_at IS NULL` y comprobar la fila afectada, no leer-luego-escribir: dos ACS simultáneos con la misma aserción no pueden ganar los dos). Cierra la repetición contra una misma petición y, con `§10.5`, el *login CSRF*.
- **`expires_at`**: ventana corta, del orden de cinco minutos, coherente con `state_ttl_minutes` que `1.4b` ya tiene configurado para OIDC. Acota la ventana de robo del `RelayState`.
- **`intent` + `linking_user_id`**: es la pieza que `1.4b` no necesitó. En OIDC, vincular una identidad exige sesión autenticada al iniciar el flujo y la sesión sigue ahí en el *callback*. **En SAML el ACS no tiene sesión** (`§10.5`), así que el usuario a vincular se captura **al emitir la petición**, cuando sí hay sesión, y viaja en la fila. Sin esto, el `intent=link` de SAML es irrealizable.

**Repetición de la aserción, que la fila anterior no cubre**: una misma aserción podría reenviarse contra **otra** petición viva. Hace falta registrar el `ID` de cada aserción consumida junto a su `NotOnOrAfter`, con índice único por tenant, y rechazar la repetida mientras siga dentro de su ventana de validez. Puede vivir en `saml_auth_requests` o en tabla propia — es de `datos.md`. Lo que este ADR fija es que **hacen falta las dos protecciones**, no una: la fila de un solo uso y el registro de aserciones consumidas cubren ataques distintos. Ambas tablas necesitan purga programada, con el precedente de `2026_08_31_100100_add_purge_indexes_to_mfa_tables.php` e issues #118/#119 sobre cómo hacerla sin bloquear.

**Y una restricción de diseño que se deriva de todo lo anterior y que es fácil equivocar:** el ACS es **por proveedor**, con el `public_id` del proveedor en la propia ruta (`…/auth/saml/{public_id}/acs`). El motivo no es de comodidad: es que **la clave con la que se verifica una firma nunca puede elegirse a partir del contenido del mensaje que aún no se ha verificado**. Con el proveedor en la ruta, el conjunto de certificados admisibles y el `entityId` de emisor esperado quedan fijados **antes** de tocar el XML; el `Issuer` que venga dentro se compara contra ese valor y, si no coincide, se rechaza. Resolver el proveedor leyendo el `Issuer` de la aserción sería dejar que el atacante elija con qué llave se le comprueba.

**`entityId` de SP: por tenant, y esto no es una pregunta.** Si todos los centros compartieran `entityId`, una aserción emitida legítimamente por el IdP del centro A —con `Audience` = ese `entityId` compartido— sería textualmente válida para el centro B. Es fuga entre tenants por diseño, `INV-001`, severidad crítica de `CLAUDE.md §5`. `entityId` y ACS URL se derivan del *host* del tenant, que ya es el mecanismo de `ADR-033 §2` y el que `§5.1` confirmó que aquí **no** tiene el tope de URIs de `1.4`, porque cada centro registra la suya en su propio IdP. Con `Destination`, `Audience` y ruta del ACS todos por tenant, quedan tres barreras independientes.

### 10.8 · Reutilización de `ExternalIdentity` y `ExternalIdentityFailure`: distinto veredicto para cada uno

Leídos los dos ficheros tal como quedaron en `1.4b`, la respuesta no es la misma:

**`ExternalIdentity` — no se reutiliza, y no hace falta forzarlo.** Es un `final readonly` de siete propiedades con `public bool $emailVerified` de primera clase, y su *docblock* dice *«firma copiada literalmente del ADR»* (`ADR-042 §4.3`). Tres salidas:

- **(a) Reutilizarlo pasando `emailVerified: true`.** Es el `true` de conveniencia que `§3.6` prohibió por su nombre. **No.**
- **(b) Un objeto de valor propio para SAML**, sin ese campo, tras una interfaz propia — que es lo que `§7.4` dejó dicho que decidiría `1.4c` *«con su implementación delante»*. **Recomendada.**
- **(c) Convertir `emailVerified` en un enum de confianza** (`ProviderVerified` / `InstitutionalAssertion` / `Unverified`) compartido por los dos protocolos. Es más limpio conceptualmente y ningún implementador tendría que mentir, pero toca un objeto de valor cuya firma `ADR-042` congeló, y con ella el código de `1.4` y `1.4b` ya desplegado, a cambio de cero garantías nuevas.

**Y lo que decide entre (b) y (c) es un hallazgo que `§3.6` no podía tener**: `§3.6` temía que el `CHECK (link_method <> 'fusion_automatica' OR email_verified_at_link)` se rellenara con un `true` de conveniencia. **Ese temor ya no aplica, porque `1.4b` lo cerró estructuralmente.** La migración `2026_09_01_100500` añadió `CHECK (link_method <> 'fusion_automatica' OR identity_provider_id IS NULL)`: la fusión automática es **imposible por esquema** para cualquier proveedor catalogado. Un vínculo SAML usará `emparejamiento_sso`, que a su vez exige `identity_provider_id IS NOT NULL` y **no está sujeto al `CHECK` de correo verificado**. Es decir: **SAML nunca consume `emailVerified` para nada.** Un objeto de valor que no lo lleve no pierde ninguna garantía; es (b), y es además la opción reversible — si más adelante se quiere la abstracción común de (c), se hace entonces con dos implementaciones reales delante en vez de una imaginada.

**`ExternalIdentityFailure` — sí se reutiliza, ampliándolo.** Es un enum de resultados **de cara a la persona**, no de mecánica de protocolo, y esa es la razón: `funcional.md §F.7.1` lo traduce a una lista cerrada de códigos de salida del `302`, y tener dos listas para el mismo botón «entrar con el sistema del centro» sería peor producto y peor auditoría. El mapeo:

| Caso | SAML |
|---|---|
| `ConsentDenied` | Se reutiliza: `Status` `AuthnFailed` / `RequestDenied` del IdP |
| `InvalidState` | Se reutiliza tal cual: `InResponseTo` sin fila viva, consumida o caducada (`§10.7`) |
| `DomainNotAllowed` | Se reutiliza sin cambios: `allowed_email_domains` es del padre y es protocolo-agnóstico |
| `ProviderUnreachable` | **Sin uso en SAML**: no hay canal trasero en el perfil `POST`. No estorba |
| `IdTokenInvalid` | **No sirve**: nombra un artefacto de OIDC. Hace falta un caso hermano —firma inválida, `Audience`/`Destination`/`Issuer` que no casan, ventana temporal fuera, aserción repetida— agrupado bajo el mismo código de salida `error_proveedor` por la razón que `1.4b` ya escribió: *«distinguirlos no ayuda a quien está delante, y sí ayudaría a quien esté probando qué validaciones tenemos»*. El detalle de cuál de las validaciones falló va al registro de aplicación |

Añadir casos a un enum de PHP es aditivo y **seguro de verificar**: todo `match` exhaustivo sobre él deja de compilar hasta cubrirlos, así que el análisis estático localiza cada punto de consumo. No hay ventana de olvido silencioso.

### 10.9 · Decisiones que corresponden al usuario

`§8` dejó cinco preguntas; el usuario resolvió tres el 2026-09-01. De las dos que quedaban, `§8.4` es de este paso y `§8.5` sigue viva. `§10` añade cuatro más que el análisis estructural ha destapado. Las ocho, **decididas por el usuario el 2026-09-02, todas siguiendo la recomendación de `architect`**:

| # | Decisión | Decisión tomada |
|---|---|---|
| **1** | **Biblioteca SAML** (equivalente a `OPEN-AUTH-35`→`ADR-042`). Candidatos: `SAML-Toolkits/php-saml` · `litesaml/lightsaml` · `simplesamlphp/saml2` · intermediario externo (`§7.2`) · a mano | **`SAML-Toolkits/php-saml` 4.x** (`§10.3`) |
| **2** | **Forma del catálogo**: discriminador `protocol` + hija `saml_identity_provider_settings` · todo *nullable* en el padre · separación completa en hijas por protocolo · tabla SAML paralela | **Discriminador + hija 1:1**, sin mover las columnas OIDC (`§10.4`) |
| **3** | **Excepción de CSRF para el ACS**: grupo de rutas propio sin `csrf` · lista global `validateCsrfTokens(except:)` · `SESSION_SAME_SITE=none` | **Grupo de rutas propio** (`§10.5`) |
| **4** | **`§8.4` · ¿SSO iniciado por el IdP?** | **No.** Precondición de seguridad de la decisión 3: aceptarlo sería un `POST` sin CSRF y sin nada que correlacionar (`§10.5`) |
| **5** | **Objeto de valor de la identidad SAML**: VO propio · enum de confianza compartido | **VO propio** (`§10.8` salida b) |
| **6** | **Clave de firma del SP**: ¿firmamos los `AuthnRequest`? ¿una clave de plataforma o una por tenant? | **Una sola clave de plataforma**, firma **opcional por proveedor** (`sign_authn_requests`), apagado por defecto |
| **7** | **`§8.5` · ¿el segundo factor del IdP exime del nuestro?** Heredada de `funcional.md §C.12` | **No exime** (`INV-002`) |
| **8** | **¿Se dispara el gatillo de `§7.2`** (intermediario Keycloak/Authentik) a la vista de los datos de `§10.1`? | **No** — dependencia directa, con seguimiento continuo del gobierno de `php-saml` |

**Confirmado y no es pregunta** (se declara aquí para que no reaparezca como ambigüedad en `spec-writer`, tal como pedía el encargo): **la política de «solo emparejamiento» de `§8.1` aplica a SAML sin ninguna diferencia.** Es la misma invariante `INV-008` y el mismo hecho de `§4.1` —el directorio institucional contiene alumnado y la aserción no trae fecha de nacimiento—, y el protocolo no cambia ni una cosa ni la otra. **Además ya está impuesto por el motor y no solo por política**: `identity_providers.provisioning_mode` lleva `CHECK IN ('desactivado','emparejamiento')`, de modo que una fila SAML **hereda la restricción por construcción**. `1.4c` no toca ese `CHECK`. Crear `Person`/`User` desde SAML no es algo que `1.4c` decida no hacer: es algo que la base de datos no permite.

### 10.10 · Consecuencias de `§10`

**A favor**

- La comparación de `§7.3` queda **corregida, no solo actualizada**: sus observaciones 2 y 3 se retiran con datos, y el criterio que decide («¿publica avisos esta dependencia?») queda escrito y es verificable por cualquiera dentro de tres años.
- El diseño de `1.4c` entra en `spec-writer` con las cuatro roturas de `§2` resueltas en concreto y no en abstracto: `§10.5` la sesión, `§10.7` la correlación, `§10.6` los certificados, `§10.8` el objeto de valor.
- Se descubre **antes** de la especificación que `§3.1` prometía más reutilización de la que hay (`§10.4`). Encontrado durante la implementación, habría sido una especificación aprobada con una migración imposible dentro.
- Dos temores anteriores se cierran con hallazgos, no con matices: el `CHECK` de correo verificado de `§3.6` ya no es un problema porque `1.4b` lo cerró por esquema, y `§8.2` se reduce en vez de resolverse porque SAML no tiene secreto de cliente.

**En contra, y se asume**

- **Se adopta la dependencia con el peor factor autobús de las tres** (`§10.3`), a sabiendas. El argumento es que MIT + 6.779 líneas hacen el *fork* una salida real; no es una salida gratis.
- **`1.4c` es mayor de lo que `§3.1` anunció.** Se corrige el enunciado, no se recorta el alcance.
- **La excepción de CSRF del ACS existe y es real**, aunque quede acotada a una ruta y mitigada por `§10.7`. Es la primera de la aplicación, y de aprobarse hay que dejarla escrita en `SECURITY.md` — con el precedente de los issues #111-#114 sobre documentación raíz que se quedó atrás, esto **no** es una tarea posterior.
- **La lectura de código de `§10.2` es parcial**, y hay que decirlo con la misma franqueza con que `§7.3` dijo su límite: se han leído la validación de firma de `lightsaml`, los valores por defecto y el acoplamiento a superglobales de `php-saml`, y el `README`, las dependencias y la estructura de `simplesamlphp/saml2`. **No se ha auditado `Response::isValid()` de `php-saml` línea a línea**, ni `xmlseclibs`. La recomendación se apoya en gobierno, superficie y escrutinio externo — no en una auditoría criptográfica, que este proyecto no está en condiciones de hacer y que sería deshonesto simular.

**Reversibilidad**: **media, y es asimétrica por partes.** La elección de biblioteca es la pieza reversible: envuelta tras interfaz propia (`RNF-MANT-007`) y usada solo por `Settings`+`Response`, sustituirla es reescribir un adaptador, no el paso. El discriminador de `§10.4` es reversible con expand/contract mientras no haya filas SAML. Lo caro de deshacer, una vez desplegado, son las dos tablas nuevas con filas dentro y la excepción de CSRF una vez que haya centros dependiendo del ACS — por eso `§10.9` lleva ocho decisiones al usuario **antes** de que `spec-writer` escriba una línea, y no después.
