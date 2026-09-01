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
