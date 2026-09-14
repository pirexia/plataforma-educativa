# REQ-BO · API

> Todo lo que sigue se ajusta a **`ADR-038`**: envoltura (§3), paginación por página o por cursor según el criterio objetivo (§4), sintaxis de filtrado y orden (§5), formato de error RFC 9457 con `type` como URN (§6), reglas de versionado (§7) e idempotencia (§8). Y a **`ADR-046 §4`**, que decide la separación de superficie y fija la pila de *middleware* de §1.1.

## 0. Prefijo, *host* y una advertencia

**Prefijo**: `/api/platform/v1/…`, servido **únicamente bajo el *host* de plataforma**. Grupo de rutas **hermano de `/api/v1`** en `routes/api.php`, con **su propia pila de *middleware* declarada de forma explícita y completa** (§1.1), igual que hoy hace el grupo del ACS de SAML.

Tres precisiones que no son cosméticas:

1. **El *host* concreto no se escribe en ninguna parte.** `OPEN-08` (dominio de la plataforma) sigue abierta; el *host* se resuelve por la variable `BACKOFFICE_HOST` (`operacion.md §2`), nunca por literal, y **no puede ser un subdominio de `TENANCY_BASE_DOMAIN`** (`RN-BO-49`).
2. **El prefijo ya no depende de ninguna pregunta abierta.** `ADR-046 §4.1` decide la **Opción A**: mismo monolito y mismo despliegue de API, con *guard*, grupo de rutas y SPA propios. El segmento `platform` es lo que separa los dos grupos y la señal legible, en cualquier log, de que esa ruta no lleva `ResolveTenant`.
3. **Declarar un grupo de rutas fuera del tenant no es una excepción nueva**: `/api/health` y `/api/_sso-simulator/*` ya lo son. Es el patrón vigente (`ADR-046 §1.1`).

> **Ninguna ruta de este documento lleva `resolve-tenant`, `verify-session-tenant` ni `require-mfa-enrollment`, y ninguna ruta de tenant lleva el *guard* `platform`.** Son propiedades verificadas por un test de arquitectura que recorre **`Route::getRoutes()`** —la verdad efectiva, no el texto de los ficheros—: `CA-BO-011`, `CA-BO-013`, `CA-BO-014` y `CA-BO-015`.

---

## 1. Autenticación

| Aspecto | Decisión |
|---------|----------|
| Mecanismo | Cookie de sesión `httpOnly`, `Secure`, `SameSite`, con CSRF (`ADR-025`). **Ningún token en el navegador**, igual que en el producto |
| *Guard* | `platform`, sobre el *provider* `platform_admins` y el modelo `PlatformAdmin`, **sin `tenant_id`**. Independiente de `web`, que no cambia (`ADR-046 §4.1`) |
| Cookie | **Nombre propio**, distinto del de la cookie del producto, y ***host-only***, sin dominio principal (`ADR-033 §2`): no puede viajar entre el *host* de plataforma y el de ningún centro, ni al revés |
| Almacén de sesión | **`platform_sessions`**, tabla de plataforma propia con `REVOKE ALL … FROM plataforma_app` (`ADR-046 §5`, `datos.md §2.6`). **No es `sessions`**, y el motivo no es que aquella tenga una columna de tenant —no la tiene— sino que `plataforma_app` la lee y en el *driver* `database` su clave primaria **es** el identificador de sesión |
| Selección del almacén | Por **grupo de rutas**, con un *middleware* que fija la configuración de sesión de plataforma **antes** de `start-session`. Mismo patrón que `TenantContext::applyCachePrefix()` |
| Vida | Corta y configurable, **más corta que la del tenant** (`RN-BO-09`, `BO_SESSION_LIFETIME`) |
| Segundo factor | **Obligatorio siempre** (`RN-BO-05`). Sin factor confirmado, la sesión sólo alcanza `/mfa/*`. **Es MFA de plataforma, no `require-mfa-enrollment`**, que es el del tenant |

### 1.1 La pila de *middleware* del grupo, completa y en orden

`ADR-046 §4.1` exige que esta pila se declare **explícita y completa** en `routes/api.php`, y `§4.5` la convierte en la aserción 2 del test de arquitectura: **toda** ruta bajo `/api/platform/*` la lleva entera y en este orden. Se comprueba **por presencia, no por ausencia** — denegar por defecto es `INV-002`, y una pila incompleta en una sola ruta es la forma de fallo que el test existe para impedir (`CA-BO-013`).

| # | *Middleware* | Qué hace, y por qué va aquí y no antes ni después |
|---|---|---|
| 1 | `RequirePlatformHost` | *Host* distinto de `BACKOFFICE_HOST` ⇒ **`404`**, antes de sesión y de credenciales. No se revela que la superficie existe (`RN-BO-48`, `CA-BO-016`) |
| 2 | `EnforcePlatformIpAllowlist` | Dirección de origen fuera de la lista blanca activa ⇒ `403` genérico, **auditado**, antes de tocar la base de datos de usuarios. Lista vacía ⇒ **denegar** (`RN-BO-06`, `RN-BO-07`) |
| 3 | Cookies (cifrado y cola de cookies) | Estándar del framework. Va después de las dos barreras: una petición que no debe llegar no merece que se le descifre nada |
| 4 | **Sesión de plataforma** | Fija conexión, tabla `platform_sessions`, nombre de cookie y vida **antes** de `start-session`, y arranca la sesión |
| 5 | CSRF | `ADR-025`. Después de la sesión, porque necesita el *token* de sesión |
| 6 | Caducidad de sesión | Vida corta de `RN-BO-09`; y la ventana de reautenticación de `RN-BO-08` para las operaciones de §4 |
| 7 | Idioma | Resuelve el idioma del administrador (`platform_admins.locale`), uno de los cuatro de `ADR-021` (`INV-009`) |
| 8 | **MFA de plataforma** | Sin factor confirmado, sólo `/mfa/*`. **Sin gracia y sin exención** (`RN-BO-05`, `CA-BO-005`, `CA-BO-006`) |
| 9 | **Capacidad** | La de cada ruta, según `permisos.md §3` y `§4`. Denegar por defecto (`RN-BO-03`) |

**Lo que esta pila deliberadamente no lleva**, y es la aserción 1 del test (`CA-BO-011`): **`resolve-tenant`, `verify-session-tenant` y `require-mfa-enrollment`**. Los tres son del tenant; el backoffice tiene sus equivalentes propios en los puestos 1, 4 y 8, y su MFA es incondicional.

**Y una barrera más que no está en esta tabla porque no es de la aplicación**: Traefik enruta por `Host()` y aplica su propio *middleware* `ipallowlist` sobre los *routers* del backoffice (`operacion.md §0`). Los puestos 1 y 2 **no la sustituyen** ni al revés: la configuración del proxy no la cubre la suite de tests y la aplicación sí, y por eso las dos capas son obligatorias (`funcional.md §3.4`).

**El orden de esta tabla no es el orden que Laravel ejecuta por sí solo.** `Illuminate\Routing\SortedMiddleware` reordena la pila final según `$middlewarePriority` del framework, no según el orden en que la ruta los declara — hallazgo real de la implementación de `1.6`, detalle completo y la corrección exacta (`bootstrap/app.php`, `prependToPriorityList()`) en `datos.md §2.6.3`. Cualquier *middleware* nuevo que se añada a esta pila, o al grupo global `api`, debe revisar esa nota antes de asumir que basta con declararlo en el sitio correcto de `routes.php`.

#### 1.1.1 Una tensión con la letra de `ADR-046 §4.5`, resuelta por el usuario (`OPEN-BO-13`, 2026-09-08: lectura (a))

`ADR-046 §4.5`, aserción 2, dice que **toda** ruta bajo `/api/platform/*` lleva la pila completa **incluidos los puestos 8 y 9** —MFA de plataforma y capacidad—. **Aplicado al pie de la letra, eso es imposible en cuatro rutas de §2.1**, y conviene decirlo antes de que lo descubra quien escriba el test:

| Ruta | Por qué no puede llevar el puesto 8 o el 9 |
|---|---|
| `GET /csrf-cookie` | No hay sujeto todavía. Ni MFA que exigir ni capacidad que comprobar |
| `POST /auth/session` | Ídem: es la ruta que **crea** el sujeto |
| `POST /auth/session/mfa` | Es la que **resuelve** el segundo factor; exigir el puesto 8 antes de ella es una dependencia circular |
| `POST /mfa/factors` · `POST /mfa/factors/{public_id}/confirm` · `GET /mfa/recovery-codes` | Son, por diseño, las **únicas** alcanzables sin factor confirmado (`RN-BO-05`). El puesto 8 tiene que dejarlas pasar |

Dos lecturas posibles, ninguna la decidía `spec-writer` porque las dos tocan la letra de un ADR vigente (`CLAUDE.md §11`). **El usuario decidió la (a) el 2026-09-08**, la recomendada:

- **(a) — adoptada.** Los puestos 8 y 9 **están presentes en todas** las rutas, y son ellos los que conocen su propia excepción: el de MFA deja pasar `/mfa/*` y las de sesión, y el de capacidad admite «autorizada por identidad del portador», como `GET /me` y como `GET /api/v1/feature-flags` (§2.14). La aserción 2 se cumple **literalmente** y no hay lista de excepciones que mantener.
- ~~(b) Lista blanca cerrada y nombrada de rutas de pre-autenticación~~ — descartada: bajo (b) existe una lista que alguien puede ampliar; bajo (a) no.

`CA-BO-013` (`funcional.md §13`) se escribe contra la lectura (a): comprueba que los puestos 8 y 9 están presentes en toda ruta de `/api/platform/*` sin excepción, y que cada uno de los dos resuelve su propia excepción por identidad del sujeto o de la ruta, no por una lista externa.

---

## 2. Endpoints

### 2.1 Sesión y segundo factor

| Verbo · Ruta | Capacidad | Notas |
|---|---|---|
| `GET /csrf-cookie` | — | Sin sesión. **Con límite de tasa desde el primer día**: su ausencia fue un hallazgo Alta en 1.2 (issue [#74](https://github.com/pirexia/plataforma-educativa/issues/74)) y no se repite |
| `POST /auth/session` | — | Credenciales. `200` con `mfa_required: true` si falta el segundo paso; nunca emite sesión plena antes del factor |
| `POST /auth/session/mfa` | — | Resuelve el desafío. Emite la sesión plena |
| `DELETE /auth/session` | Identidad | Cierre de sesión |
| `POST /auth/reauthenticate` | Identidad | Contraseña **y** segundo factor. Marca la sesión como reautenticada (§4) |
| `GET /me` | Identidad | El administrador y sus roles. **Sin permiso, por identidad del portador**, igual que `GET /me` en `REQ-CORE` |
| `POST /mfa/factors` · `POST /mfa/factors/{public_id}/confirm` · `GET /mfa/recovery-codes` | Identidad | Alta y confirmación del segundo factor. Únicos endpoints alcanzables sin factor confirmado |
| `POST /admin-invitation-redemptions` | — | **Añadido por el issue [#173](https://github.com/pirexia/plataforma-educativa/issues/173)**: `CreateAdminCommand` no emitía ninguna invitación pese a documentarlo, dejando el chasis inutilizable de extremo a extremo. Anónimo, autorizado por posesión del token — mismo criterio que `POST /api/v1/auth/invitation-redemptions` de `REQ-AUTH`. Fija la contraseña; **no** abre sesión (mismo criterio que `RN-AUTH-21`). `410` si el token no es válido, caducado, revocado o ya canjeado. **No existe todavía un `CA-BO` numerado para este endpoint** — hueco real de la especificación detectado al resolver el issue, pendiente de que `funcional.md` lo incorpore |

**Errores de `POST /auth/session`**: `401` credenciales inválidas (mensaje **idéntico** para usuario inexistente y contraseña incorrecta), `403` IP no permitida, `429` límite de tasa con `Retry-After`.

**Sobre el issue [#173](https://github.com/pirexia/plataforma-educativa/issues/173)**: `POST /admins` y `bo:create-admin` (`operacion.md §5` paso 4) comparten ahora un único punto de alta (`PlatformAdminManagementService::create()`) que crea el administrador **y** despacha, en cola, una invitación real (`SendPlatformAdminInvitationEmail`, precedente de forma `Core\Infrastructure\Jobs\SendInvitationEmail`) — token de un solo uso, hash SHA-256 en `platform_admin_invitations` (tabla de plataforma nueva, `datos.md §2.8`), canjeable en el endpoint de arriba. `docs/modulos/REQ-BO/operacion.md §5` ya documentaba este flujo; lo que no existía era la implementación.

### 2.2 Administradores de plataforma (`REQ-BO-007`)

| Verbo · Ruta | Capacidad | Notas |
|---|---|---|
| `GET /admins` | `admin.leer` | Página. Filtros `status`, `role`, `q` |
| `POST /admins` | `admin.crear` | Sensible. Crea sin contraseña utilizable e invita |
| `GET /admins/{public_id}` | `admin.leer` | |
| `PATCH /admins/{public_id}` | `admin.actualizar` | Nombre, correo, idioma |
| `PUT /admins/{public_id}/roles` | `admin.rol.gestionar` | Conjunto completo. `409` si el actor es el propio sujeto (`RN-BO-10`) o si deja la plataforma sin `superadministrador` (`RN-BO-11`) |
| `POST /admins/{public_id}/status` | `admin.actualizar` | Suspender o reactivar. Mismos `409` |
| `DELETE /admins/{public_id}` | `admin.eliminar` | Sensible. Borrado lógico. Mismos `409` |
| `DELETE /admins/{public_id}/mfa` | `admin.mfa.restablecer` | Sensible. **Sólo `superadministrador`, nunca autoservicio, nunca por correo** (`CA-BO-012`) |

### 2.3 Lista blanca de IP (`REQ-BO-007`)

| Verbo · Ruta | Capacidad |
|---|---|
| `GET /ip-allowlist` | `ip_allowlist.leer` |
| `POST /ip-allowlist` | `ip_allowlist.gestionar` · **sensible** |
| `DELETE /ip-allowlist/{public_id}` | `ip_allowlist.gestionar` · **sensible** |

> **`DELETE` no comprueba si el solicitante se está dejando fuera a sí mismo, y es deliberado.** Un guardarraíl que compare la IP de la petición con la entrada a borrar daría falsa seguridad —una red corporativa tiene varias salidas y el operador puede estar en otra— y complicaría la retirada legítima de un rango caducado. La red real es que un bloqueo total se resuelve por consola en el servidor (`RN-BO-07`, `operacion.md §5`), y eso está documentado en vez de simulado.

### 2.4 Inventario y ciclo de vida de tenants (`REQ-BO-001`)

| Verbo · Ruta | Capacidad | Notas |
|---|---|---|
| `GET /tenants` | `tenant.leer` | Página. §3.1 |
| `POST /tenants` | `tenant.crear` | Sensible. `Idempotency-Key` **obligatoria** (`ADR-038 §8.1`, criterios 2 y 3: envía la invitación del primer administrador y ejecuta un proceso por lotes). `201`. **`422` con `bo.tenant.slug_reserved` si el `slug` coincide con la etiqueta del *host* de plataforma** (`RN-BO-49`, `CA-BO-017`) |
| `GET /tenants/{public_id}` | `tenant.leer` | Ficha: estado, configuración, módulos contratados, historial de estados |
| `PATCH /tenants/{public_id}` | `tenant.actualizar` | Nombre y `suspension_message`. **`slug` va aparte** (§2.5) |
| `POST /tenants/{public_id}/transitions` | Según destino (§ `permisos.md`) | **Único** camino para cambiar de estado. Cuerpo: `to_status`, `reason`, y `confirmation_name` cuando `to_status = 'eliminado'` |
| `GET /tenants/{public_id}/lifecycle-events` | `tenant.leer` | Cursor. Tabla *append-only* |
| `POST /tenants/{public_id}/clone` | `tenant.crear` | Sensible. `Idempotency-Key` obligatoria. Copia configuración, roles, concesiones y suscripciones; **nunca personas** (`RN-BO-21`) |

**Un solo *endpoint* de transición, no seis rutas de verbo** (`/suspend`, `/reactivate`, `/close`…). Motivo: `RN-BO-12` es una máquina de estados y una sola puerta hace que la validación de transiciones se escriba **una vez**. Con seis rutas, la séptima que alguien añada se olvidará de comprobar el estado de origen — y el motivo obligatorio, y la invalidación de caché de `RN-BO-14`.

**Respuestas de `POST /tenants/{public_id}/transitions`**

| Caso | Respuesta |
|---|---|
| Transición simple (suspender, reactivar, dar de baja, rescatar) | `200` con el tenant actualizado |
| `to_status = 'eliminado'` | **`202`** con la `dual_authorization` creada en estado `pendiente`. **No se ha eliminado nada** |
| Transición no permitida (`RN-BO-12`) | `409` `urn:pge:error:conflict` |
| Motivo ausente o vacío (`RN-BO-13`) | `422` |
| `confirmation_name` distinto del nombre exacto (`RN-BO-18`) | `422`, y **no se crea ninguna solicitud** (`CA-BO-065`) |
| Sesión sin reautenticación viva | `403` `urn:pge:error:reauthentication-required` |

#### 2.4.1 `POST /tenants` · cuerpo, respuesta y las dos fases (`1.6b`)

```jsonc
{
  "name": "Colegio Ejemplo",              // obligatorio
  "slug": "colegio-ejemplo",              // obligatorio, etiqueta DNS, único entre vivos
  "reason": "Alta comercial — contrato 2026/27",   // obligatorio, no vacío (RN-BO-13)
  "settings": {
    "default_locale": "es-ES",            // obligatorio, contenido en active_locales
    "active_locales": ["es-ES", "en"],    // obligatorio, subconjunto de ADR-021
    "timezone": "Europe/Madrid",          // obligatorio, IANA
    "currency": "EUR",                    // obligatorio, ISO 4217
    "autonomous_community": "MD"          // obligatorio, catálogo de REQ-CORE
  },
  "administrator": {
    "email": "direccion@example.com",     // obligatorio
    "given_name": "Nombre",               // obligatorio
    "family_name": "Apellido"             // obligatorio
  }
}
```

**No hay campos `domain`, `plan`, `stages` ni `regime`**, y no es un olvido: los cuatro los nombra `REQ-BO-001` y ninguno tiene dato hoy (`funcional.md §5.3.1`). Añadirlos cuando existan es un cambio compatible (`ADR-038 §7.2`); aceptarlos ahora y descartarlos en silencio es la peor de las tres opciones.

> **El bloque `settings` y el bloque `administrator` viajan tal cual a `REQ-CORE`**, como los objetos de valor `TenantInitialSettings` y `TenantAdministrator` del contrato `TenantProvisioner` (`ADR-048 §4.2`). **`REQ-BO` es quien produce el `422`**: campos obligatorios, `autonomous_community` requerida aquí aunque la columna sea anulable, `slug` libre y no reservado, formato de correo (`funcional.md §5.3.2`). `REQ-CORE` vuelve a comprobar **coherencia** —idioma por defecto contenido en los activos, zona IANA, moneda `^[A-Z]{3}$`, CCAA del catálogo— y ante un valor inválido lanza `InvalidArgumentException` **sin clave de traducción**: llegar ahí significa que este *endpoint* no validó, y eso es un defecto de programación, no un error del operador (`ADR-048 §4.7`). El catálogo de CCAA se importa de `Core\Domain\AutonomousCommunity::CODES`, que es superficie pública; **no se duplican diecinueve códigos en `REQ-BO`**.

**Respuesta `201`**, con el recurso en `en_alta` y un bloque que dice en qué fase está:

```jsonc
{
  "data": {
    "public_id": "01J…",
    "slug": "colegio-ejemplo",
    "name": "Colegio Ejemplo",
    "status": "en_alta",
    "provisioning": { "state": "en_curso", "started_at": "2026-09-11T10:00:00Z" },
    "suspended_at": null, "suspension_message": null,
    "grace_period_ends_at": null, "grace_period_expired_at": null,
    "created_at": "2026-09-11T10:00:00Z"
  }
}
```

`provisioning.state` ∈ {`en_curso`, `completado`, `fallido`}. **Es un enumerado de respuesta y se documenta como extensible** (`ADR-038 §7.3`). Se deriva del estado del trabajo y de la última entrada de auditoría del tenant; **no hay una columna `provisioning_state`** y no se añade (`ADR-034 OPEN-13`): con `status = 'en_alta'` más la existencia o no de `tenant.aprovisionamiento_fallido` está todo dicho.

**`201` y no `202`**, aunque el trabajo pesado vaya en cola: el recurso **existe** y tiene URL. Un `202` diría que puede que no llegue a existir, y no es el caso — lo que puede fallar es su configuración, y eso lo dice `provisioning.state`.

#### 2.4.2 `POST /tenants/{public_id}/transitions` · la matriz completa

Cuerpo: `to_status` (obligatorio), `reason` (obligatorio, no vacío), `confirmation_name` (sólo con `to_status = 'eliminado'`).

| Origen → destino | Capacidad | ¿Sensible? | ¿Doble autorización? | Respuesta | Efectos, además de `tenants.status`, la fila de `tenant_lifecycle_events` y la de `admin_action_logs` |
|---|---|:---:|:---:|---|---|
| `en_alta` → `activo` | **Ninguna: no es alcanzable por API** | — | — | `409` | La produce el trabajo de aprovisionamiento (`RN-BO-52`). `reason` es una clave de catálogo (`RN-BO-54`) |
| `activo` → `suspendido` | `tenant.suspender` | No | No | `200` | `suspended_at = now()`, `suspension_message` del cuerpo si viene; invalida la caché (`RN-BO-51`) |
| `suspendido` → `activo` | `tenant.suspender` | No | No | `200` | `suspended_at` y `suspension_message` a nulo (`RN-BO-58`) |
| `activo` → `en_baja` | `tenant.baja` | **Sí** | **No** (`RN-BO-62`, `OPEN-BO-14`) | `200` | `grace_period_ends_at = now() + 90 días` |
| `en_baja` → `activo` (rescate) | **`tenant.baja`**, no `tenant.suspender` | No | No | `200` | `grace_period_ends_at` y `grace_period_expired_at` a nulo |
| `en_baja` → `eliminado` | `tenant.eliminar` | **Sí** | **Sí** | **`202`** con la solicitud pendiente | Al **aprobarse**: `deleted_at`, `dual_authorization_id` en el evento, revocación de sesiones en cola (`RN-BO-55`) |
| Cualquier otra | — | — | — | `409` `bo.tenant.invalid_transition` | Nada (`RN-BO-12`) |

**El rescate se autoriza con `tenant.baja` y no con `tenant.suspender`**, y merece la frase: quien no puede dar de baja un centro tampoco debe poder deshacer la baja que decidió otro. Emparejar cada operación con su inversa bajo la misma capacidad es lo que evita que dos roles se pisen en una máquina de estados.

**La suspensión admite `suspension_message` en el mismo cuerpo.** Es el único dato que la transición escribe además del estado, y separarlo en un `PATCH` posterior dejaría una ventana —corta, pero real— en la que el centro ve el mensaje por defecto en vez del que el operador acaba de redactar.

#### 2.4.3 `POST /tenants/{public_id}/clone` · cuerpo y respuesta

Mismo cuerpo que `POST /tenants` **menos `settings`** —que se copian del origen (`funcional.md §5.6.2`)— y **más** el `reason`. La respuesta es la misma de §2.4.1, con un bloque que dice qué se ha copiado y qué no:

```jsonc
"cloned_from": {
  "tenant_public_id": "01J…",
  "copied":     ["settings.operativos", "roles", "role_permissions", "module_subscriptions"],
  "not_copied": ["settings.fiscales", "settings.marca", "personas", "usuarios",
                 "invitaciones", "auditoria", "estado", "early_adopter"]
}
```

> **`not_copied` no es decoración.** Un operador que clona espera que el clon sea igual al origen, y lo que no se copia **es justamente lo que no espera** (`CA-BO-123`). Devolverlo convierte una sorpresa en un dato; omitirlo convierte la decisión de §5.6.2 en un fallo aparente que alguien «arreglará» copiando también la identidad fiscal.

**`422` con `bo.tenant.clone_source_invalid`** si el origen está `eliminado` o `en_alta` (`RN-BO-59`).

**Que el cuerpo no traiga `settings` no significa que los copie este módulo.** `tenant_settings`, `roles` y `permission_role` son de `REQ-CORE` y los copia `REQ-CORE`, por `TenantProvisioner::provisionFromTemplate()` (`ADR-048 §4.5`, `funcional.md §5.6.2`). El bloque `administrator` del cuerpo sí viaja a ese contrato, igual que en el alta. Lo único que copia el trabajo del backoffice es `module_subscriptions`, del que es único escritor (`ADR-045 §4.1`) — y es también lo único de `copied` que no sale de `REQ-CORE`.

### 2.5 Cambio de `slug`

`POST /tenants/{public_id}/slug` — capacidad `tenant.actualizar`, **sensible**.

Ruta propia y no un campo de `PATCH`, porque no es un cambio de dato: **cambia el nombre DNS con el que el centro entra**, invalida `tenant-resolution:{slug}` de las dos claves —la vieja y la nueva—, deja fuera a quien tenga la URL antigua guardada y afecta al certificado. Un campo dentro de un `PATCH` genérico lo haría parecer equivalente a cambiar el nombre para mostrar, y no lo es.

**Valida lo mismo que el alta** (`funcional.md §5.3.2`), y en particular: `422` si el `slug` coincide con la etiqueta del *host* de plataforma (`RN-BO-49`, `CA-BO-017`), con la clave `bo.tenant.slug_reserved` en `errors`. Es la defensa en profundidad de `ADR-046 §4.4`, y va **en las dos puertas** —alta y cambio— porque una sola dejaría la otra abierta.

**Tres cosas más que `1.6b` fija sobre esta ruta**, y las tres se olvidan si no están escritas: exige **`reason`** como cualquier otra escritura de ciclo de vida; invalida **las dos** claves de caché, la vieja y la nueva, después del `COMMIT` (`RN-BO-51`, `CA-BO-114`); y se audita con **`tenant.slug_cambiado`**, valor propio del vocabulario y no `tenant.actualizado` (`datos.md §4.2.1`). **No** escribe fila en `tenant_lifecycle_events`: no ha habido transición de estado.

### 2.6 Módulos por tenant (`REQ-BO-002`, `ADR-045`)

| Verbo · Ruta | Capacidad | Notas |
|---|---|---|
| `GET /modules` | `modulo.leer` | Catálogo con `depends_on`, `essential` y `phase` **leídos del descriptor** |
| `GET /tenants/{public_id}/modules` | `modulo.leer` | Los tres estados de `ADR-045 §4.7` por módulo, con `enabled_at`, `disabled_at` y `reason` |
| `POST /tenants/{public_id}/modules/preview` | `modulo.leer` | **Vista previa de impacto**, sin escribir nada. §2.7 |
| `PUT /tenants/{public_id}/modules/{module_code}` | `modulo.contratar` | Contratar o descontratar. Cuerpo: `enabled`, `reason`, `cascade` |
| `POST /module-rollouts/preview` | `modulo.leer` | Vista previa de una operación masiva. §2.7 |
| `POST /module-rollouts` | `modulo.contratar_masivo` | Operación masiva. `Idempotency-Key` **obligatoria**. En cola (`INV-012`). `202` |

**`PUT` y no `PATCH`** para el conmutador: el cuerpo declara el estado deseado completo de esa celda, y `ADR-038` reserva `PATCH` para la modificación parcial de un recurso. Contratar dos veces con el mismo cuerpo es idempotente y no emite evento (`CA-BO-044`), que es la semántica que se espera de `PUT`.

**Códigos de este grupo**

| Caso | Respuesta |
|---|---|
| Contratar sin dependencias y sin `cascade: true` | `409`, `params.missing_dependencies` con los códigos que faltan |
| Descontratar una dependencia de módulos contratados sin `cascade: true` | `409`, `params.dependent_modules` con los arrastrados (`CA-BO-040`) |
| Descontratar un módulo **esencial** | `422`, `params.module_code` (`CA-BO-041`) |
| Módulo `retired_at` | `422` |
| Motivo ausente | `422` (`RN-BO-24`) |
| Desactivación **masiva** | `202` con la `dual_authorization` pendiente (`CA-BO-066`) |

**Un solo código de error para el módulo no contratado**, en el lado del tenant: `urn:pge:error:module-disabled`. `ADR-045 §4.10` es explícito — con una sola potestad hay una sola causa, y el mensaje no revela plan, precio ni condiciones comerciales.

### 2.7 Vista previa de impacto

Los dos *endpoints* de vista previa **no escriben nada** y devuelven lo que `REQ-BO-002` enumera:

```json
{
  "affected_tenants": 1,
  "modules_to_contract": ["comedor", "fin"],
  "modules_to_decontract": [],
  "cascaded_dependencies": ["fin"],
  "blocked": [{ "module_code": "core", "reason_code": "bo.module.essential" }],
  "impact": {
    "users_affected": 128,
    "screens_removed": ["comedor.menus", "comedor.reservas"],
    "integrations_disabled": []
  }
}
```

> **`impact` es la parte más fácil de falsear de toda la API.** `users_affected` sólo se puede calcular sobre lo que existe: en 1.6 son los usuarios del tenant con algún permiso del módulo. `screens_removed` e `integrations_disabled` salen del **descriptor declarado por el módulo**, no de una lista escrita en el backoffice — si un módulo no las declara, el campo va **vacío y así se muestra**, nunca relleno con un valor inventado. Una vista previa que exagera el impacto se ignora a la tercera vez.

### 2.8 Doble autorización (`REQ-BO-007`)

| Verbo · Ruta | Capacidad | Notas |
|---|---|---|
| `GET /dual-authorizations` | `autorizacion.leer` | Página. Filtros `status`, `action`, `requested_by` |
| `GET /dual-authorizations/{public_id}` | `autorizacion.leer` | Incluye el `payload` congelado, para que quien aprueba vea **exactamente** qué aprueba |
| `POST /dual-authorizations/{public_id}/approval` | Según `action` · **sensible** | Aprueba **y ejecuta**. `409` si caducada, ya resuelta, o si el aprobador es el solicitante |
| `POST /dual-authorizations/{public_id}/rejection` | Ídem | Rechaza con motivo |

**No hay `DELETE`.** Una solicitud no se retira: se rechaza, con motivo y con actor. Borrar la prueba de que alguien pidió eliminar un centro sería justo lo contrario de lo que `REQ-BO-007` pide.

**El `409` del aprobador que es el solicitante lo produce la restricción de base de datos** (`datos.md §3.1`), traducida a error de API. El controlador puede comprobarlo antes para dar un mensaje mejor, pero **la garantía no vive ahí** (`CA-BO-062`).

### 2.9 Auditoría de plataforma (`REQ-BO-007`)

| Verbo · Ruta | Capacidad | Notas |
|---|---|---|
| `GET /admin-action-logs` | `auditoria_plataforma.leer` | **Cursor**. §3.2 |
| `GET /tenants/{public_id}/admin-action-logs` | `tenant.leer` | Lo ocurrido a un centro concreto |

**Y un endpoint que vive en la aplicación de los centros, no aquí**:

| Verbo · Ruta | Dónde | Permiso |
|---|---|---|
| `GET /api/v1/platform-actions` | **Aplicación del tenant** (`REQ-CORE`) | `auditoria.leer` |

Es el requisito de `REQ-BO-007` de que el registro sea «consultable por el propio centro en lo que le afecte». Devuelve **sólo** las filas con el `affected_tenant_id` del centro, garantizado por la política `tenant_visibility` de `ADR-047 §4.3` y no por el `where` del controlador (`CA-BO-022`). Se declara aquí porque el dato es de este módulo, pero **la ruta y el permiso son de `REQ-CORE`**: `INV-007`, y además un usuario de tenant no debe conocer siquiera la existencia del *host* del backoffice.

> **Su proyección no la decide el controlador: la decide el `GRANT`.** `plataforma_app` tiene concedido `SELECT` sobre **seis columnas enumeradas** de `admin_action_logs` —`public_id`, `occurred_at`, `action`, `affected_tenant_id`, `subject_type`, `subject_public_id`— y sobre ninguna más (`datos.md §4.3`). El recurso de este *endpoint* **es exactamente ese conjunto**, y un `SELECT *` o cualquier intento de devolver `reason`, `actor_platform_admin_id`, `ip_address`, `user_agent`, `context` o `changes` **falla con error de privilegios del motor**, no con una respuesta de más (`CA-BO-099`). Es deliberado que falle ruidosamente: la superficie es el `GRANT`, no el *resource* (`ADR-047 §4.4`).
>
> **Consecuencia sobre el cursor de este *endpoint*, que hay que verificar y no suponer**: el listado de plataforma de §3.2 desempata por `id` porque corre con `plataforma_platform`; **aquí `id` no está concedido**, así que el desempate estricto que `ADR-038 §4.4` exige tiene que apoyarse en `public_id`, que sí lo está. Son dos consultas sobre la misma tabla con dos superficies distintas y **no se copia una en la otra**. `datos.md §4.5` deja anotado que el índice que sirve a esta segunda hay que confirmarlo antes de escribir la migración — es el mismo trabajo de `db-reviewer` que `ADR-047 §7` reserva para la lista de columnas.

> **No hay exportación.** Ni aquí ni en el lado del tenant, y es una decisión: un CSV del registro completo de acciones del proveedor es un mapa de la operación de la plataforma, y `REQ-PERM/permisos.md §2.1` ya sentó el criterio con `rol.exportar` y `permiso_efectivo.exportar`. Si algún día hace falta, es un requisito nuevo con su permiso y su propia auditoría de exportación.

### 2.10 Salud y métricas (`REQ-BO-004`, `REQ-BO-006`, reducidos)

| Verbo · Ruta | Capacidad | Notas |
|---|---|---|
| `GET /tenants/{public_id}/health` | `salud.leer` | Sólo lo observable (`funcional.md §5.9`). Los campos sin fuente **no aparecen**, no van a cero |
| `GET /tenants/{public_id}/failed-jobs` | `salud.leer` | Cursor. Filtrados por el `tenant_id` del *payload* (`ADR-033 §8`) |
| `POST /tenants/{public_id}/failed-jobs/{uuid}/retry` | `job.reintentar` | Auditado (`REQ-SUP-004`) |
| `GET /metrics/platform` | `metrica.leer` | Tenants por estado, altas y bajas del período |
| `GET /metrics/module-adoption` | `metrica.leer` | Cuántos centros tienen contratado cada módulo |

> **Regla de honestidad, y va en la especificación porque es lo primero que se pierde**: un campo cuya fuente no existe en 1.6 **se omite de la respuesta**. No se devuelve `0`, no se devuelve `null`, no se devuelve `"n/d"`. Un cero indistinguible de «no medido» es peor que la ausencia, y los enumerados de respuesta son extensibles (`ADR-038 §7.3`), así que añadir el campo cuando exista el dato es un cambio compatible.

### 2.11 *Feature flags* (`REQ-BO-005` puntos 1-2 · sub-paso `1.6e`)

Entra por decisión del usuario del 2026-09-08. Diseño en `funcional.md §5.11`, esquema en `datos.md §9`.

| Verbo · Ruta | Capacidad | Notas |
|---|---|---|
| `GET /feature-flags` | `flag.leer` | Página. Catálogo materializado, con `module_code`, `rollout_unit`, `status`, `rules_version` y un resumen de sus reglas. Filtros `module_code`, `status`, `retired` (booleano), `q` |
| `GET /feature-flags/{key}` | `flag.leer` | El *flag* y **todas** sus reglas |
| `PUT /feature-flags/{key}/state` | `flag.gestionar` | `status` ∈ {`activo`, `forced_off`} más `reason`. Es el interruptor de emergencia. §2.12 |
| `PUT /feature-flags/{key}/rules` | `flag.gestionar` · **sensible** | **El conjunto completo** de reglas del *flag*, en una escritura atómica, con `reason`. §2.12 |
| `POST /feature-flags/{key}/rules/preview` | `flag.leer` | Vista previa: a cuántos centros expondría el conjunto propuesto, **sin escribir nada**. §2.13 |
| `GET /tenants/{public_id}/feature-flags` | `flag.leer` | Qué *flags* están expuestos en ese centro **y por qué regla**. §2.13 |
| `PUT /tenants/{public_id}/early-adopter` | `tenant.actualizar` | Designar o retirar, con `reason`. Escribe `tenants.early_adopter_since` (`RN-BO-46`) |

**Sobre `{key}` en la ruta**: `datos.md §11` y `OPEN-BO-11` (`funcional.md §14`). Si esa pregunta se resuelve en contra, **las cuatro rutas que llevan `{key}`** pasan a `{public_id}` y nada más cambia. Las dos de `/tenants/{public_id}/…` ya usan el ULID del centro y no se ven afectadas.

**Por qué la designación de *early adopter* se autoriza con `tenant.actualizar` y no con `flag.gestionar`**: es un atributo **del centro**, escrito en `tenants`, no una regla de despliegue. Colgarlo de `flag.gestionar` significaría que una capacidad sobre *flags* permite modificar la ficha de un centro, que es una frontera que no conviene difuminar. En la práctica no cambia quién puede hacerlo —`operaciones` y `superadministrador` tienen ambas—, y por eso mismo es gratis elegir la correcta.

**Códigos de este grupo**

| Caso | Respuesta |
|---|---|
| Clave de *flag* inexistente en el catálogo | `404` |
| *Flag* con `retired_at`, en cualquier escritura | `422`, `bo.flag.retired` (`CA-BO-091`) |
| Regla con el eje y la columna descuadrados (un `percentage` con `affected_tenant_id`, por ejemplo) | `422`, `bo.flag.invalid_rule` |
| Dos reglas del mismo eje sobre el mismo objetivo en el conjunto enviado | `422`, `bo.flag.duplicate_rule` |
| `percentage` fuera de `0..100` | `422`, `bo.flag.invalid_rule` |
| Motivo ausente o vacío | `422` (`RN-BO-43`) |
| Intento de escribir `rollout_unit` | `422`: no es un campo de la API. Lo declara el código (`RN-BO-36`) |

**No hay `POST /feature-flags` ni `DELETE`.** Un *flag* se crea escribiendo el código que lo consulta y se retira dejando de declararlo; el catálogo lo materializa `platform:sync-registry` (`RN-BO-34`, `CA-BO-090`). Un *endpoint* de creación produciría *flags* huérfanos que ningún código lee y que nadie se atreve a borrar — y es exactamente el fallo que `ADR-034 §5` evitó al no dejar que los módulos se dieran de alta desde la aplicación.

### 2.12 Dos decisiones sobre las escrituras de *flag* que hay que defender

**1 · Las reglas se escriben en bloque, con `PUT`, y no una a una.**
La precedencia de `funcional.md §5.11.5` es una propiedad **del conjunto**, no de cada regla. Con `POST`/`DELETE` por regla, un operador que quiere «subir al 40 % **y** excluir al centro que tuvo la incidencia» tiene que hacer dos llamadas, y entre las dos existe un instante real en el que ese centro está expuesto. Con un `PUT` del conjunto completo, la transición es atómica, `rules_version` se incrementa una vez y no hay ventana. El coste es que el cliente tiene que enviar lo que no cambia; es un coste que se paga con gusto por eliminar un estado intermedio incorrecto.

**2 · Apagar no es una operación sensible; encender sí.**
`PUT /feature-flags/{key}/rules` exige reautenticación (§4). `PUT …/state` **no la exige cuando el destino es `forced_off`**, y **sí cuando es volver a `activo`**.

> Es la única asimetría de este tipo en toda la API y por eso lleva su párrafo. `forced_off` es el freno de emergencia: se usa cuando una funcionalidad recién desplegada está rompiendo colegios, a menudo de madrugada y a menudo por quien está de guardia. **Poner fricción en el freno es cómo se aprende a no usarlo** — o peor, cómo se acaba compartiendo credenciales para llegar antes. Apagar va siempre en la dirección segura, es reversible y queda auditado con su actor y su motivo. Volver a encender es la dirección peligrosa, y ahí la fricción sí está bien puesta.

**No hay doble autorización en ninguna operación de *flags***, y también conviene decir por qué no: `REQ-BO-007` la exige para «eliminar un tenant, purgar datos o desactivar módulos en masa» —operaciones **destructivas**—, y ninguna escritura de *flag* destruye nada ni es irreversible: se deshace con otra escritura, en segundos, sin desplegar (`RNF-MANT-005`). Exigir dos personas para tocar un porcentaje convertiría el despliegue progresivo en algo que nadie usa, y el resultado sería desplegar de golpe, que es justo lo que `RARQ-DEP-010` quiere evitar.

### 2.13 Las dos lecturas que de verdad se usan al depurar

`POST /feature-flags/{key}/rules/preview` responde al «¿a cuántos afecto?» **antes** de escribir:

```json
{
  "rollout_unit": "tenant",
  "exposed_tenants": 23,
  "total_tenants": 210,
  "newly_exposed": ["01J...", "01J..."],
  "newly_hidden": [],
  "by_rule": { "global": 0, "early_adopters": 8, "percentage": 15, "tenant": 1 },
  "role_filter": ["docente"]
}
```

`GET /tenants/{public_id}/feature-flags` responde a la pregunta inversa, que es la que llega por soporte —«este centro ve algo que no debería»—:

```json
{
  "data": [
    { "key": "comedor.reserva_v2", "enabled": true, "matched_by": "early_adopters" },
    { "key": "eval.boletin_v3",   "enabled": false, "matched_by": "none" },
    { "key": "acad.horario_beta", "enabled": true, "matched_by": "percentage" }
  ]
}
```

> **`matched_by` es la mitad del valor de este *endpoint***. Sin él, un operador ve que un centro tiene un *flag* encendido y no puede saber si es por regla nominal, por cohorte o porque cayó dentro del porcentaje — y acabará adivinando. Es el equivalente, para *flags*, de lo que `REQ-PERM` resolvió con la explicación de permiso efectivo: **decir el resultado sin decir la causa obliga a reproducir el cálculo a mano**.
>
> `newly_exposed` y `newly_hidden` listan `public_id` de centros y **se truncan**: por encima de un umbral se devuelve sólo el recuento. Un operador no lee doscientos identificadores, y devolverlos convierte una vista previa en un volcado del inventario.

### 2.14 Y un *endpoint* que vive en la aplicación de los centros

Igual que `GET /api/v1/platform-actions` en §2.9, el dato es de este módulo pero la ruta no:

| Verbo · Ruta | Dónde | Permiso |
|---|---|---|
| `GET /api/v1/feature-flags` | **Aplicación del tenant** (`REQ-CORE`) | **Ninguno**: por identidad del portador, como `GET /me` |

Es lo que la SPA necesita para saber qué renderizar. Tres propiedades, y las tres son decisiones:

1. **Devuelve sólo las claves que evalúan verdadero** para el usuario que pregunta. No devuelve las apagadas, ni las que están en despliegue parcial y no le han tocado (`CA-BO-097`).
2. **Por eso no hace falta permiso, y por eso no filtra nada**: la lista completa de *flags* del producto es el mapa de todo lo que estamos construyendo y a qué ritmo lo estamos soltando — información competitiva y, en manos de quien busca superficie, información útil. Un cliente que no recibe una clave la trata como apagada, que es el valor por defecto de todos modos (`RN-BO-35`), así que omitirlas no rompe nada.
3. **Se autoriza por identidad, no por capacidad**, porque la respuesta depende del propio sujeto: sus roles determinan el filtro de `funcional.md §5.11.5` paso 4. Pedir un permiso para consultar lo que a uno mismo le aplica sería el error que `REQ-CORE` ya evitó con `GET /me`.

**No hay ningún *endpoint* de tenant que escriba *flags*.** Un centro no se autoconcede una funcionalidad en despliegue, y `datos.md §9.6` lo garantiza con `REVOKE` en el motor, no sólo con la ausencia de la ruta.

---

## 3. Paginación, filtrado y ordenación

### 3.1 Qué usa cada listado

Aplicando el criterio objetivo de `ADR-038 §4.2` —**origen de las filas**, no cardinalidad estimada:

| Listado | Modo | Por qué |
|---|---|---|
| `GET /tenants` | **Página** | Catálogo de entidades: las filas nacen de un alta administrativa humana |
| `GET /admins` | **Página** | Ídem |
| `GET /ip-allowlist` | **Página** | Ídem |
| `GET /dual-authorizations` | **Página** | Ídem: cada fila es una decisión humana |
| `GET /feature-flags` | **Página** | Catálogo, y de los más pequeños: sus filas nacen de una declaración en el código, no de la actividad |
| `GET /tenants/{id}/feature-flags` | **Sin paginar** | Es el conjunto completo de *flags* evaluados para un centro — decenas de claves, no un listado. Paginarlo obligaría a recorrer páginas para responder a una pregunta que se responde de una vez, y `ADR-038 §4.2` no exige paginar lo que es un cálculo y no una tabla |
| `GET /admin-action-logs` | **Cursor** | Flujo de eventos, tabla *append-only*. La regla operativa de `ADR-038 §4.2` es literal: «si la tabla es *append-only*, es cursor» |
| `GET /tenants/{id}/lifecycle-events` | **Cursor** | Ídem |
| `GET /tenants/{id}/failed-jobs` | **Cursor** | Ídem |

### 3.2 El cursor de `admin_action_logs` tiene una particularidad

`ADR-038 §4.4` exige orden total estricto y que el cursor cifrado transporte, entre otras cosas, el `tenant_id` del emisor. **Aquí no hay tenant**: el emisor es un `platform_admin`. El campo `t` del cursor lleva, en su lugar, el `platform_admin_id`, con la misma finalidad —que un cursor emitido por otra sesión no valga— y el mismo `422` al no cuadrar. Se dice explícitamente porque es una desviación de la letra de `ADR-038` que conserva su motivo, y una revisión futura debe encontrarla escrita en vez de leerla como un descuido.

Orden: `(occurred_at DESC, id DESC)`, con el índice de `datos.md §4.5`.

### 3.3 Filtros

Sintaxis de `ADR-038 §5.2` sin excepción: parámetros planos, valores múltiples separados por comas (`status=activo,suspendido`), rangos con `_from`/`_to` inclusivos, texto libre siempre en **`q`**, booleanos `true`/`false`, parámetro desconocido **ignorado**, parámetro conocido con valor inválido **`422`**.

| Listado | Filtros | Orden admitido |
|---|---|---|
| `GET /tenants` | `status`, `module_code`, `autonomous_community`, `q` (nombre y `slug`), y **dos que añade `1.6b`**: `grace_expired` (booleano, `tenants.grace_period_expired_at IS NOT NULL`) y `provisioning` (∈ `en_curso`, `fallido`) | `name`, `-name`, `created_at`, `-created_at`, `status` |
| `GET /admins` | `status`, `role`, `q` | `name`, `-name`, `-created_at` |
| `GET /admin-action-logs` | `action`, `actor_platform_admin_id`, `affected_tenant_id`, `occurred_at_from`, `occurred_at_to` | Fijo: `-occurred_at` |
| `GET /dual-authorizations` | `status`, `action`, `requested_by` | `-requested_at`, `expires_at` |
| `GET /feature-flags` | `module_code`, `status`, `retired` (booleano), `q` (clave y textos) | `key`, `-key`, `-updated_at` |

**`plan`, `student_count` y `regime` no son filtros de `GET /tenants`** (`funcional.md §1.3`): no existe el dato. Añadirlos cuando exista es un cambio compatible (`ADR-038 §7.2`).

`autonomous_community` se lee de la configuración del centro que fija `REQ-CORE`; **no se duplica una columna en `tenants`** para poder filtrar. Si el `JOIN` resultara caro con doscientos centros —que no lo será—, la solución es un índice, no desnormalizar.

---

## 4. Operaciones sensibles: reautenticación

Exigen sesión reautenticada dentro de la ventana (`RN-BO-08`). La lista es **cerrada y está aquí**, no repartida por los controladores, para que añadir una operación destructiva obligue a tocar este documento:

`POST /tenants` · `POST /tenants/{id}/clone` · `POST /tenants/{id}/slug` · `POST /tenants/{id}/transitions` cuando `to_status ∈ {en_baja, eliminado}` · `POST /admins` · `DELETE /admins/{id}` · `DELETE /admins/{id}/mfa` · `POST` y `DELETE /ip-allowlist` · `POST /module-rollouts` · `POST /dual-authorizations/{id}/approval` y `/rejection` · `PUT /feature-flags/{key}/rules` · `PUT /feature-flags/{key}/state` **sólo cuando el destino es `activo`** (§2.12).

**`1.6b` no añade ninguna operación a esta lista, y conviene decir por qué no añade la que parece faltar**: el **rescate** (`en_baja` → `activo`) **no es sensible**, igual que no lo es la reactivación de una suspensión. Las dos van en la dirección de **devolver** el servicio, las dos son reversibles con una llamada más, y la reautenticación existe para lo que cuesta deshacer. Lo que sí está en la lista es la baja, que es la dirección contraria.

Fallo: `403` con `type` **propio** — `urn:pge:error:reauthentication-required` — porque la interfaz tiene que distinguir «vuelve a identificarte» de «no tienes permiso» **sin analizar texto**, exactamente por el motivo con el que `ADR-038 §6.2` separó `module-disabled` de `forbidden`.

---

## 5. Códigos de error

Además de los de `ADR-038 §6.2`, este módulo añade **dos** al catálogo cerrado:

| `type` | Estado | Cuándo |
|---|---|---|
| `urn:pge:error:reauthentication-required` | 403 | Operación sensible sin reautenticación viva |
| `urn:pge:error:ip-not-allowed` | 403 | Dirección de origen fuera de la lista blanca |

**No se añade ningún código para la doble autorización**, y se dice para que nadie lo eche en falta: una solicitud pendiente **no es un error**. Es un `202` con el recurso `dual_authorization` en el cuerpo, que es lo que la interfaz necesita para enseñar «pendiente de un segundo administrador» y enlazar la solicitud.

Los `403` de este módulo **no revelan por qué en `detail`** cuando la causa es la lista blanca: `title` y `detail` genéricos, y el motivo real sólo en `admin_action_logs`. Un mensaje que confirma «tu IP no está permitida» le dice a quien prueba desde fuera que ha encontrado el *host* correcto.

**Y hay un caso que ni siquiera llega a `403`**: una petición cuyo `Host` no es `BACKOFFICE_HOST` recibe **`404`**, indistinguible de una ruta inexistente, desde `RequirePlatformHost` y **antes** de la sesión y de las credenciales (`RN-BO-48`, `CA-BO-016`). No lleva `type` propio a propósito: **cualquier código de error específico sería la confirmación de que la superficie existe**, que es exactamente lo que ese `404` evita. Mismo criterio que `ResolveTenant` con un *host* desconocido (`ADR-033 §2`) y que `RN-BO-15` con los centros.

**Códigos de `errors` propios** (clave, mensaje traducido y `params`, según `ADR-038 §6.3`):

`bo.tenant.invalid_transition` · `bo.tenant.reason_required` · `bo.tenant.name_mismatch` · `bo.tenant.slug_taken` · **`bo.tenant.slug_reserved`** · **`bo.tenant.clone_source_invalid`** · `bo.tenant.last_superadmin` · `bo.module.essential` · `bo.module.missing_dependencies` · `bo.module.dependent_modules` · `bo.module.retired` · `bo.dual_auth.same_actor` · `bo.dual_auth.expired` · `bo.dual_auth.already_resolved` · `bo.dual_auth.payload_mismatch` · `bo.admin.self_modification` · `bo.ip.allowlist_empty` · `bo.flag.retired` · `bo.flag.invalid_rule` · `bo.flag.duplicate_rule`

Los **veinte** —diecisiete del chasis, del ciclo de vida y de los módulos, más tres de *feature flags*—, en `es-ES`, `en`, `de` y `fr` (`INV-009`, `CA-BO-073`).

**`bo.tenant.clone_source_invalid` lo añade `1.6b`**: el origen de una clonación está `eliminado` o `en_alta` (`RN-BO-59`). Es distinto de `bo.tenant.invalid_transition` porque no hay ninguna transición en juego, y distinto de un `404` porque el tenant existe y el operador lo está viendo en su inventario.

**Y `1.6b` no añade ningún `type` nuevo al catálogo cerrado**, ni siquiera para el aprovisionamiento fallido: un tenant en `en_alta` con `provisioning.state = "fallido"` es un **estado del recurso**, que la ficha devuelve (§2.4.1), no un error de una petición. Convertirlo en un `urn:pge:error:` obligaría a que consultar un tenant a medio aprovisionar fuera un fallo, y no lo es.

**Textos de catálogo que `1.6b` añade fuera de `errors`**, y que también son los cuatro idiomas: los **cuatro mensajes de `503`** —`en_alta`, `suspendido` sin mensaje propio, `en_baja` y `eliminado` (`funcional.md §5.4.1`)— y las **claves de `reason` de las transiciones automáticas** (`RN-BO-54`). Ninguno es un literal en el código (`INV-009`).

**`bo.tenant.slug_reserved` lo añade `ADR-046 §4.4`** y es distinto de `bo.tenant.slug_taken`: aquel dice «ese nombre ya es de otro centro», éste dice «ese nombre está reservado por la plataforma». Mezclarlos daría un mensaje que sugiere al operador buscar el centro que lo ocupa, y no hay ninguno. **Su `detail` no nombra el *host* de plataforma**, por el mismo criterio con el que los `403` de la lista blanca no dicen su causa (§5).

**No se añade ningún `type` nuevo al catálogo cerrado por los *flags***: los tres casos son errores de validación de un cuerpo, y `422` con su clave en `errors` es exactamente lo que `ADR-038 §6.3` prevé para eso. Un `urn:pge:error:` propio se reserva para lo que la interfaz debe **distinguir sin analizar texto**, como `reauthentication-required` o `module-disabled`, y aquí no hay nada de eso: un formulario de reglas mal enviado se trata igual que cualquier otro formulario mal enviado.

---

## 6. Idempotencia (`ADR-038 §8.1`)

**Obligatoria** en: `POST /tenants` (envía la invitación del primer administrador **y** ejecuta un proceso por lotes: criterios 2 y 3), `POST /tenants/{id}/clone` (criterio 3) y `POST /module-rollouts` (criterios 2 y 3).

**No obligatoria** en `PUT /tenants/{id}/modules/{code}`: el índice único `(tenant_id, module_code) WHERE deleted_at IS NULL` hace la operación naturalmente idempotente, y `ADR-038 §8.1` dice explícitamente que la idempotencia se pone donde falta protección, no donde queda bonita.

**No obligatoria** en `POST /dual-authorizations/{id}/approval`: el índice único parcial y el `CHECK` de coherencia de estado (`datos.md §3.2`) convierten el reintento en `409`, que es el resultado correcto.

**No obligatoria** en ninguna escritura de *flags*: `PUT …/rules` y `PUT …/state` declaran el estado deseado completo y son idempotentes por construcción — reenviar el mismo cuerpo deja el mismo resultado. Lo único que un reintento produce de más es un incremento de `rules_version` y una entrada de auditoría, y ninguna de las dos cosas es un efecto que haya que evitar: la primera sólo invalida caché de sobra, y la segunda es el registro fiel de que alguien envió la operación dos veces.

---

## 7. Eventos de dominio emitidos

| Evento | Emisor | Cuándo | Consumidor en 1.6 |
|---|---|---|---|
| `ModuleContracted` | **`REQ-CORE`** | `enabled` pasa a `true` | Ninguno (`ADR-045 §4.8`). En **1.19**, `REQ-COM-003` |
| `ModuleDecontracted` | **`REQ-CORE`** | `enabled` pasa a `false` | Ídem |
| `TenantSuspended`, `TenantReactivated`, `TenantMarkedForClosure` | `REQ-BO` | Transición correspondiente | Ninguno. Se declaran para `REQ-BKP` (1.26) y `REQ-COM` (1.19) |

Los dos primeros **no los emite este módulo**, y no es un detalle de implementación: `RMOD-010` y `ADR-045 §4.8` fijan que todo evento del ciclo de vida de un módulo pertenece a `REQ-CORE`. `CA-BO-038` lo verifica descontratando un módulo y comprobando que el evento sale igualmente, **aunque el módulo quede apagado**.

**Y `1.6b` no añade ninguna fila a esta tabla: ni el alta ni la clonación emiten evento de dominio** (`ADR-048 §4.6`). Es una omisión deliberada y no un olvido. El hecho «este tenant quedó aprovisionado» **ya se registra dos veces** —la fila `en_alta` → `activo` de `tenant_lifecycle_events` y la de `admin_action_logs`—, las dos escritas por el mismo trabajo y en el mismo instante; un `TenantProvisioned` sería una tercera fuente de verdad del mismo hecho, y en cuanto alguien la escuchara habría dos caminos por los que enterarse y uno se quedaría atrás. **No contradice el párrafo anterior sobre `TenantSuspended` y compañía**: aquellas son transiciones que decide un operador y cuyo momento sólo conoce el servicio de transición; el aprovisionamiento no lo decide nadie, es la consecuencia de un alta que ya quedó escrita. Cuando `REQ-ONB` (1.24) necesite engancharse, el evento se añade en `REQ-CORE` —nunca aquí (`ADR-045 §4.8`)— con una línea y sin ADR.

**Las escrituras de *feature flags* no emiten ningún evento de dominio, y aquí sí es una decisión distinta de la que se tomó con los tenants.** `TenantSuspended` y compañía se declaran aunque hoy no tengan consumidor, porque `REQ-BKP` y `REQ-COM` los necesitarán y añadirlos después obligaría a tocar el camino de escritura otra vez. Con los *flags* no ocurre eso: **el consumidor de un *flag* es el evaluador, y el evaluador lee el estado vigente, no la transición**. Ningún módulo de fase 1 ni de fase 2 necesita reaccionar al hecho de que una regla cambió — necesita saber el valor **ahora**, que es justo lo que `FeatureFlagEvaluator` responde. Declarar un evento sin consumidor posible sería inventar una extensión, no anticiparla.

---

## 8. Webhooks

**Ninguno.** Ningún requisito de `REQ-BO` pide notificación saliente a terceros, y `ADR-038 §2` deja los webhooks explícitamente sin decidir. No se inventa un mecanismo sin consumidor.

---

## 9. OpenAPI

Todos los *endpoints* de §2 se documentan en `openapi.yaml` (`CLAUDE.md §10`), **en un documento o sección separada de la API del producto**: son dos superficies con dos autenticaciones distintas, y mezclarlas produce un cliente generado que cree poder llamar a `/tenants` con la cookie de un centro.

Cada listado declara su `enum` cerrado de `sort` y sus filtros uno a uno (`ADR-038 §5.3`). Todo enumerado de respuesta se documenta como **extensible** (`ADR-038 §7.3`).

**Los dos *endpoints* que viven en la aplicación del tenant** —`GET /api/v1/platform-actions` (§2.9) y `GET /api/v1/feature-flags` (§2.14)— se documentan **en el documento del producto, no en el de plataforma**, aunque su dato nazca aquí. Ponerlos en el de plataforma generaría un cliente que cree poder llamarlos con la sesión equivocada, que es exactamente lo que separar los dos documentos pretende evitar.
