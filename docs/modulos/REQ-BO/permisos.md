# REQ-BO · Permisos

> Este documento es **la excepción del proyecto**, y conviene decirlo antes de la primera tabla: es el único módulo cuya autorización **no** se resuelve con el motor de `REQ-PERM`. No porque sea especial, sino porque su sujeto —un administrador de plataforma— **no tiene tenant**, y todo el motor de 1.5 está construido, correctamente, alrededor de que sí lo tenga.
>
> La decisión no es mía: `ADR-034 §2` ya la tomó y `REQ-CORE/permisos.md §4.5` la recoge. Lo que sí me toca es **justificar que sigue siendo la correcta después de 1.5**, cuando por fin existe un motor de verdad y reutilizarlo es una tentación legítima. Eso es §1.

---

## 1. Por qué el backoffice no usa `REQ-PERM`

### 1.1 La decisión ya estaba tomada

> *«Los roles de plataforma viven en `platform_admins` y sus propias tablas, sin `tenant_id`, en el paso 1.6. Insertar un superadministrador en `roles` sería darle un tenant, que es exactamente lo que no es.»* — `ADR-034 §2`, recogido en `REQ-CORE/permisos.md §4.5`

`super_administrador` **no es fila de `roles`** desde 1.1, confirmado por el usuario en el issue [#48](https://github.com/pirexia/plataforma-educativa/issues/48). Este paso no reabre eso: lo ejecuta.

### 1.2 Cuatro razones verificadas contra el código de 1.5

Que la decisión de `ADR-034 §2` fuera correcta en 0.8 no la hace correcta hoy: en 0.8 no había motor que reutilizar y ahora sí. Estas son las razones **de ahora**, comprobadas sobre `develop` en `b95be70`:

| # | Obstáculo | Verificación |
|---|-----------|--------------|
| 1 | **`roles` es tabla de tenant** con política `tenant_isolation` `FORCE` y unicidad `(tenant_id, code)` parcial | Un rol de plataforma necesitaría `tenant_id` nulo: rompe la política, rompe el índice y rompe el test de esquema #8 de `ADR-033 §10`. Y `ADR-033 §5` prohíbe en voz alta la «comodidad» de añadir `OR app.current_tenant_id() IS NULL` a una política |
| 2 | **`PermissionResolver` está atado al tenant en tres puntos** | Consulta `roles.tenant_id`; aplica el filtro de inercia `inerte_modulo` a través de `ModuleAvailability`, que es una noción **por tenant**; y se registra `scoped()` dentro de una petición que ya entró en un tenant |
| 3 | **El vocabulario de ámbitos no tiene la dimensión que hace falta** | Los seis de `RPERM-004` (`todos`, `propios`, `departamento`, `grupo`, `clase`, `unidad_familiar`) son ámbitos **dentro** de un centro. El ámbito de un administrador de plataforma sería «qué centros», que no está en la lista — y `ADR-044 §4.1` cerró ese vocabulario y sólo admite ampliarlo **por ADR nuevo**. Forzarlo aquí es exactamente lo que ese ADR quiso impedir |
| 4 | **`INV-007`** | `REQ-BO` no puede importar código interno de `App\Support\Authorization` |

### 1.3 Lo que sí se hereda: la forma

Que la maquinaria sea distinta no autoriza a bajar el listón. Se conservan, íntegras:

- **Denegación por defecto** (`RPERM-011`, `INV-002`): lo que no está concedido explícitamente, no se puede.
- **Verificación en cada *endpoint***, en el servidor. La interfaz oculta botones; eso no es seguridad.
- **Comprobación de capacidad, nunca de código de rol** dentro de servicios y controladores (`RN-BO-04`). `if ($admin->esSuperadmin())` es el error característico que la *skill* `permisos-y-roles` pone el primero, y aquí es **más** tentador que en el tenant precisamente porque los roles son cuatro y fijos.
- **Tests de acceso denegado**, no sólo de acceso concedido.

### 1.4 Y una diferencia que también hay que justificar: no hay `deny`

`RPERM-007` («deny sobrescribe allow») existe en el tenant porque el centro **compone roles personalizados** y necesita poder restringir. Aquí los roles son **cuatro, fijos y declarados en el código**: un `deny` no resolvería ningún caso y sólo añadiría una forma de equivocarse. La resolución multi-rol es **unión de capacidades**, y punto.

Si algún día `REQ-BO` necesitara roles de plataforma personalizados —ningún requisito lo pide—, esa conversación traería consigo la de `deny`, y sería un ADR.

---

## 2. Dónde vive el mapa de capacidades: **en el código**

`REQ-BO-007` enumera **exactamente cuatro roles** y ningún requisito pide más. Por tanto:

| | `REQ-PERM` (tenant) | `REQ-BO` (plataforma) |
|---|---|---|
| Roles | En base de datos, el centro crea los suyos | **En el código**, `CHECK` de cuatro valores |
| Catálogo de capacidades | Tabla `permissions`, materializada por `platform:sync-registry` | **En el código**, un `enum` de PHP |
| Mapa capacidad → rol | Tabla `permission_role`, el centro la edita | **En el código**, constante única |
| Asignación rol → sujeto | Tabla `role_user` | Tabla `platform_admin_roles` (§ `datos.md §2.2`) |

**Lo único que vive en base de datos es qué roles tiene cada persona.** Es lo único que cambia con el tiempo.

Precedente del proyecto para esta forma: el catálogo de módulos vive en el código y no en la base de datos (`ADR-034 §5`, ratificado por `ADR-045 §9`), *«una copia en base de datos de algo que declara el código se desincroniza en el primer despliegue en que alguien olvide correr el comando, y falla en silencio»*. Aquí el argumento es todavía más fuerte: **una tabla editable de capacidades de plataforma sería un camino para que alguien se conceda capacidades sobre todos los centros a la vez, y ese camino no debe existir.**

---

## 3. Matriz recurso × acción × ámbito

**El ámbito es `plataforma` en todas las celdas, y no es una casilla vacía: es la afirmación de que estas capacidades no se acotan.** Un administrador con `tenant.actualizar` lo tiene sobre **todos** los centros. No existe hoy «operaciones sobre estos veinte centros», ningún requisito lo pide, y el vocabulario de `RPERM-004` no lo contempla (§1.2).

> Consecuencia que hay que aceptar por escrito: **cualquier capacidad de escritura de este módulo es una capacidad sobre el producto entero.** Es lo que hace que `REQ-BO-007` empiece diciendo que «el Super Administrador es el rol más peligroso del sistema», y es el motivo de que el MFA sea incondicional y de que las acciones destructivas exijan dos personas.

| Recurso | crear | leer | actualizar | eliminar | otras |
|---------|-------|------|------------|----------|-------|
| `admin` | ✅ | ✅ | ✅ | ✅ | `admin.rol.gestionar`, `admin.mfa.restablecer` |
| `ip_allowlist` | — | ✅ | — | — | `ip_allowlist.gestionar` (alta y baja en una) |
| `tenant` | ✅ | ✅ | ✅ | ✅ | `tenant.suspender`, `tenant.baja` |
| `modulo` | — | ✅ | — | — | `modulo.contratar`, `modulo.contratar_masivo` |
| `flag` | — | ✅ | — | — | `flag.gestionar` (estado y reglas). **Sin `crear` y sin `eliminar`**, y no por olvido: un *flag* se crea escribiendo el código que lo consulta y se retira dejando de declararlo (`RN-BO-34`). Una capacidad de creación no tendría *endpoint* al que aplicarse |
| `autorizacion` | — | ✅ | — | — | La aprobación se rige por la capacidad de la **acción autorizada**, no por una propia (§5.2) |
| `auditoria_plataforma` | — | ✅ | — | — | Sin `exportar`, a propósito (§5.4) |
| `salud` | — | ✅ | — | — | `job.reintentar` |
| `metrica` | — | ✅ | — | — | |

### 3.1 Por qué aquí sí hay capacidades fuera de las nueve acciones de `RPERM-003`

En `REQ-PERM` la regla es estricta: las acciones son las nueve de `RPERM-003` y no se inventa ninguna — por eso `invitacion.crear` en vez de `usuario.invitar`, y `rol_datos_especiales` como recurso en vez de una acción nueva.

**Esa regla es del catálogo de `permissions`, que este módulo no usa** (§2). Aquí, `tenant.suspender` es una acción de verdad, con su propia comprobación, distinta de `tenant.actualizar` porque suspender un centro deja a su plantilla sin acceso y actualizar su nombre no. Modelarla como recurso —`suspension_tenant.crear`— sería aplicar una convención fuera de su contexto para acabar con un nombre peor.

**Se dice explícitamente** para que una revisión que venga de leer `REQ-PERM/permisos.md §1` no lo tome por una desviación descuidada.

---

## 4. Asignación a los cuatro roles internos

Denegación por defecto: **lo que no aparece, no se concede** (`RPERM-011`, `RN-BO-03`).

| Capacidad | `soporte` | `operaciones` | `comercial` | `superadministrador` |
|---|:---:|:---:|:---:|:---:|
| `tenant.leer` | ✅ | ✅ | ✅ | ✅ |
| `tenant.crear` | — | — | — | ✅ |
| `tenant.actualizar` | — | ✅ | — | ✅ |
| `tenant.suspender` | — | ✅ | — | ✅ |
| `tenant.baja` | — | — | — | ✅ |
| `tenant.eliminar` | — | — | — | ✅ |
| `modulo.leer` | ✅ | ✅ | ✅ | ✅ |
| `modulo.contratar` | — | ✅ | — | ✅ |
| `modulo.contratar_masivo` | — | ✅ | — | ✅ |
| `flag.leer` | ✅ | ✅ | — | ✅ |
| `flag.gestionar` | — | ✅ | — | ✅ |
| `salud.leer` | ✅ | ✅ | — | ✅ |
| `job.reintentar` | — | ✅ | — | ✅ |
| `metrica.leer` | — | ✅ | ✅ | ✅ |
| `auditoria_plataforma.leer` | ✅ | ✅ | — | ✅ |
| `autorizacion.leer` | — | ✅ | — | ✅ |
| `admin.leer` | — | — | — | ✅ |
| `admin.crear` / `.actualizar` / `.eliminar` | — | — | — | ✅ |
| `admin.rol.gestionar` | — | — | — | ✅ |
| `admin.mfa.restablecer` | — | — | — | ✅ |
| `ip_allowlist.leer` | — | — | — | ✅ |
| `ip_allowlist.gestionar` | — | — | — | ✅ |

### 4.1 Las decisiones de reparto que hay que defender

**`soporte` no tiene ni una escritura.** El requisito dice «solo lectura y diagnóstico» y se cumple literalmente, incluido `job.reintentar` — que **parece** diagnóstico y **es** escritura: reejecuta un trabajo que puede enviar correos, modificar datos y disparar eventos. Se le da a `operaciones`. El acceso real de soporte a un centro será la impersonación de `REQ-SUP-003`, con su motivo, su duración y su banner; no una capacidad global sigilosa.

**`comercial` es casi un rol vacío en 1.6, y eso es correcto.** Su ámbito es «planes y facturación», y ninguna de las dos cosas existe (`funcional.md §2.2`). Se declara el rol y se le da lectura del inventario y de las métricas para que exista, con su nombre, cuando `REQ-SAAS` llegue. **No se le concede nada «mientras tanto»**: un rol al que se le da de más «porque total, aún no hace nada» conserva ese exceso para siempre.

**Los *flags* son de `operaciones`, y esta vez el requisito lo dice con esa palabra.** `REQ-BO-007` describe el alcance de `operaciones` como «módulos, límites, **flags**». Es la única de las tres que el requisito nombra literalmente y que este paso puede cumplir: los módulos son `1.6c`, los límites son `REQ-BO-003` y están fuera (`funcional.md §2.2`), y los *flags* son `1.6e`. Se le concede `flag.gestionar` sin matices.

**`soporte` sí lee *flags*, y es la única lectura nueva que gana.** «¿Por qué este centro ve una pantalla que el de al lado no ve?» es literalmente la primera pregunta de un ticket sobre un despliegue progresivo, y `GET /tenants/{id}/feature-flags` con su `matched_by` (`api.md §2.13`) es la respuesta. Negarle esa lectura obligaría a escalar a `operaciones` cada consulta trivial, que es como se erosiona un reparto de roles. **Escritura, ninguna** (`CA-BO-094`): cambiar un porcentaje es una operación sobre el parque entero, no diagnóstico.

**`comercial` no toca *flags* en ninguna dirección**, ni siquiera de lectura. No tiene ningún caso de uso —su alcance es planes y facturación— y el catálogo de *flags* es el mapa de lo que el producto está construyendo. Es el mismo criterio con el que no se le dio `auditoria_plataforma.leer`.

**`operaciones` no elimina ni da de baja.** El requisito le asigna «módulos, límites, flags». Dar de baja un centro es ciclo de vida, y el ciclo de vida es del `superadministrador`. **Suspender sí es suyo**: es reversible en un clic, es la respuesta operativa a un incidente, y exigir un superadministrador a las tres de la mañana para parar un centro comprometido sería una restricción que empuja a compartir credenciales.

**La gestión de administradores y de la lista blanca es sólo del `superadministrador`.** Es la tercera vez que este proyecto llega a la misma conclusión —`REQ-CORE/permisos.md §4.1`, `REQ-PERM/permisos.md §5.1`— y aquí el argumento es el más fuerte de los tres: **quien administra a los administradores administra todo lo demás**, y puede además concederse a sí mismo cualquier capacidad si `RPERM-013` no lo frena (§5.1). Repartirlo es repartir el producto.

**`auditoria_plataforma.leer` lo tiene `soporte` y no `comercial`.** Es la herramienta de diagnóstico —«¿quién tocó este centro y cuándo?»— y ese es el trabajo de soporte. `comercial` no tiene ningún caso de uso que la necesite, y el registro es un mapa completo de la operación interna.

### 4.2 Multi-rol

Un administrador puede tener varios roles (`datos.md §2.2`); las capacidades se **suman**. La separación que `REQ-BO-007` exige es de **personas**, no de roles, y la impone la doble autorización con un `CHECK` de base de datos (`datos.md §3.1`).

Consecuencia concreta y aceptada: alguien con `operaciones` **y** `superadministrador` puede solicitar y no aprobar su propia eliminación de tenant. **Acumular roles no rompe la doble autorización**, y `CA-BO-062` lo verifica.

---

## 5. Reglas de autorización que no son una capacidad

Igual que `REQ-CORE/permisos.md §8` y `REQ-PERM/permisos.md §8`: lo que ninguna comprobación de capacidad cubre y que la revisión de seguridad debe recorrer entera.

| Regla | Dónde | Efecto |
|-------|-------|--------|
| **Lista blanca de IP antes que las credenciales** (`RN-BO-06`) | Primer *middleware* de la pila de plataforma | `403` genérico, auditado. **Antes** de tocar la base de datos de usuarios |
| **Lista vacía = denegar** (`RN-BO-07`) | Ídem | Nunca «vacía = sin restricción» |
| **MFA incondicional** (`RN-BO-05`) | *Middleware* posterior a la sesión | Sin factor confirmado, sólo `/mfa/*`. **Sin gracia y sin exención** |
| **Reautenticación en operaciones sensibles** (`RN-BO-08`) | *Middleware* sobre la lista cerrada de `api.md §4` | `403` con `type` propio |
| **Nadie se modifica a sí mismo** (`RN-BO-10`) | `PUT /admins/{id}/roles`, `POST /admins/{id}/status`, `DELETE /admins/{id}` | `409` |
| **Siempre un `superadministrador` vivo** (`RN-BO-11`) | Mismas rutas | `409`, con bloqueo de fila: es una restricción de conjunto y no cabe en un `CHECK` (`datos.md §2.2`) |
| **Quien aprueba ≠ quien solicita** (`RN-BO-19`) | Base de datos | `CHECK`. **No es una comprobación de aplicación** |
| **La ejecución usa el `payload` congelado** (`RN-BO-20`) | Servicio de ejecución | Huella distinta ⇒ rechazo |
| **El backoffice no devuelve datos personales de los centros** (`RN-BO-33`) | Todo *endpoint* | Restricción **funcional**, no de permiso: no hay capacidad que la conceda |
| **Un `User` de tenant nunca autentica aquí** (`RN-BO-02`) | *Guard* y *provider* separados, cookie *host-only* | `401`, auditado |
| **Ningún control de seguridad detrás de un *flag*** (`RN-BO-47`) | Test de arquitectura | El evaluador **no** se invoca desde el *middleware* de MFA, de lista blanca, de autorización, de resolución de tenant ni de doble autorización (`CA-BO-096`) |
| **Un *flag* no concede acceso a un módulo no contratado** (`RN-BO-45`) | Evaluador y `ModuleAvailability` | Son dos comprobaciones distintas y la de módulo es previa. Un *flag* al 100 % no evita el `urn:pge:error:module-disabled` (`CA-BO-092`) |
| **La unidad de reparto no la elige el operador** (`RN-BO-36`) | Descriptor del módulo, materializado; privilegio de columna (`datos.md §9.6`) | Ninguna capacidad permite escribir `rollout_unit`, ni siquiera `superadministrador` |


### 5.1 `RPERM-013` traducido a este módulo

*«Un usuario nunca puede conceder un permiso que él mismo no posee»* es `RPERM-013`, y aquí se cumple **por construcción y no por comprobación**: la única capacidad de concesión es `admin.rol.gestionar`, la tiene únicamente `superadministrador`, y `superadministrador` posee todas las capacidades. No hay ningún par (concedente, capacidad concedida) en el que el concedente no la tenga.

**No es una casualidad afortunada, es una propiedad que se puede romper**: el día que se añada un quinto rol interno, o que `admin.rol.gestionar` se reparta, `RPERM-013` deja de cumplirse solo y hay que implementarlo. Queda escrito aquí para que ese día alguien lo lea.

### 5.2 La aprobación se rige por la capacidad de la acción autorizada

No existe `autorizacion.aprobar`. Quien aprueba una `dual_authorization` de `action = 'tenant.eliminar'` necesita **`tenant.eliminar`**; quien aprueba una de `modulo.descontratar_masivo` necesita `modulo.contratar_masivo`.

Motivo: una capacidad genérica de aprobación sería una llave que abre cualquier operación destructiva sin poseer ninguna de ellas. **Un permiso más restrictivo no se obtiene componiendo dos menos restrictivos**, y su recíproco tampoco: aprobar es tan potente como ejecutar, porque **al aprobar se ejecuta**.

### 5.3 Los *feature flags* no son un mecanismo de autorización, y hay que blindarlo

Es la confusión más probable de este módulo, porque las dos cosas responden a «¿puede este usuario ver esto?» y las dos se consultan con una llamada booleana. **No son lo mismo, y las consecuencias de mezclarlas no son simétricas:**

| | Permiso (`REQ-PERM`) | *Feature flag* |
|---|---|---|
| Pregunta que responde | ¿Le corresponde a **esta persona**, por su rol y su ámbito? | ¿Está **esta funcionalidad** desplegada aquí todavía? |
| Quién lo decide | El centro, componiendo sus roles | El proveedor, con sus reglas de despliegue |
| Si falla en abierto | **Fuga de datos**: alguien ve lo que no le corresponde | Alguien ve una funcionalidad antes de tiempo |
| Sirve para sustituir al otro | **No** | **No** |

De ahí `RN-BO-47`, y de ahí que lleve **test de arquitectura y no revisión de código**: la tentación concreta que hay que impedir no es filosófica, es que alguien resuelva una incidencia de madrugada poniendo el MFA obligatorio, la lista blanca de IP o una comprobación de permiso detrás de un *flag* «para desactivarlo un momento». Sería exactamente la variable de entorno que `operacion.md §2` se negó a crear — con el agravante de que un *flag* **sí** se puede cambiar en caliente y sin desplegar, que es lo que lo hace peor y no mejor.

**El sentido inverso también está prohibido y es menos evidente**: un *flag* tampoco puede usarse para **conceder** acceso a datos. Si una funcionalidad nueva expone información que no todos deben ver, lo que la protege es un permiso; el *flag* sólo decide si esa funcionalidad existe todavía. Un despliegue progresivo que además concede lectura es un permiso encubierto que ningún administrador de centro puede ver ni revocar desde su matriz.

### 5.4 Sin `exportar`, en ninguno de los nueve recursos

Tercera vez que este proyecto dice que no a una exportación, con el mismo argumento (`REQ-AUTH/permisos.md §C.6` con `mfa`, `REQ-PERM/permisos.md §2.1` con `rol` y `permiso_efectivo`): un CSV con el inventario de centros, o con el registro completo de acciones del proveedor, es **un mapa de la plataforma ordenado por utilidad para quien quiera atacarla**.

La *skill* `permisos-y-roles` advierte de lo contrario —«olvidar `exportar` es el error característico»—, y por eso conviene ser explícito: **no se olvida, se descarta**. Si algún día hace falta, es un requisito nuevo, con su capacidad, su auditoría de exportación y su enlace caducable.

---

## 6. Datos de categoría especial

**`REQ-BO` no expone datos de categoría especial, y no debe.** Ninguna capacidad lleva marca de categoría especial, porque no hay salud, NEAE ni convivencia en ninguna de sus tablas.

Más aún: `RN-BO-33` y `REQ-BO-007` prohíben que el backoffice muestre **cualquier** dato personal de los centros — «el backoffice muestra métricas y estado, no listados de alumnos». Es una restricción más fuerte que la de categoría especial y **no se implementa con permisos**: se implementa no construyendo esos *endpoints*. `CA-BO-074` la verifica recorriendo las respuestas de todos ellos.

El único camino previsto para que personal del proveedor vea un dato concreto de un centro es la **impersonación auditada** de `REQ-SUP-003`, que no está en este paso (`funcional.md §2.2`) y que trae consigo motivo, duración máxima, banner permanente, consentimiento previo del centro y auditoría de todo lo consultado.

---

## 7. MFA (`RPERM-014` no aplica; `REQ-BO-007` es más estricto)

`RPERM-014` («todo rol incluye el atributo `mfa_obligatorio`») es de los roles de tenant. Aquí no hay atributo que configurar:

| | Tenant (`REQ-AUTH-003`) | Plataforma (`REQ-BO-007`) |
|---|---|---|
| Obligatoriedad | Atributo `roles.mfa_required`, por rol | **Incondicional**, para los cuatro roles |
| Período de gracia | Sí, configurable | **No** |
| Excepciones temporales (1.3b) | Sí, nominales y con caducidad | **No existen** (`CA-BO-006`) |
| Métodos | TOTP y correo | **Sólo TOTP** (`datos.md §2.4`) |
| Restablecimiento | Administrador de Centro, auditado | Otro `superadministrador`, auditado. **Nunca autoservicio ni por correo** |

**Una columna que sólo puede valer `true` es una columna que alguien pondrá a `false`.** Por eso `platform_admins` no tiene `mfa_required` (`datos.md §2.1`) y por eso no hay tabla de exenciones.

---

## 8. Lo que este módulo añade al catálogo de `REQ-CORE`

**Un permiso, y ninguno más aunque el módulo haya crecido.** Los *feature flags* añaden un segundo *endpoint* en la aplicación del tenant —`GET /api/v1/feature-flags` (`api.md §2.14`)— y **no traen permiso ninguno**: se autoriza por identidad del portador, como `GET /me`, porque la respuesta depende del propio sujeto y devuelve exclusivamente lo que a él le aplica. Crear un permiso para que alguien consulte lo que ya le afecta sería un permiso que ningún centro sabría a quién conceder, y `REQ-PERM/permisos.md §2.1` ya fijó ese criterio. **Ningún rol de tenant gana ni pierde nada por los *flags***.

El permiso que sí hay que decidir es el de la auditoría de plataforma, y tampoco es del backoffice. El requisito de `REQ-BO-007` de que sea «consultable por el propio centro en lo que le afecte» se sirve desde la aplicación del tenant (`api.md §2.9`), y por tanto se autoriza con el motor de `REQ-PERM`:

| Camino | Decisión |
|--------|----------|
| Reutilizar **`auditoria.leer`** | **Recomendado.** El recurso es el mismo desde el punto de vista del centro —«qué le ha pasado a mi centro»— y su reparto ya está decidido y razonado: sólo `administrador_centro` (`REQ-CORE/permisos.md §4.1`), precisamente porque es el registro más sensible del módulo |
| Declarar `auditoria_plataforma.leer` en el catálogo de tenant | Descartado. Añadiría un permiso que ningún centro sabría a quién conceder, y `REQ-PERM/permisos.md §2.1` ya estableció el criterio de no crear permisos que sugieren capacidades que nadie va a repartir |

**Ningún rol de tenant gana ni pierde nada**, y `soporte_plataforma` —que es un rol **de tenant**, sembrado sin permisos desde 1.1 (`REQ-CORE/permisos.md §4.4`)— **sigue exactamente igual**. Merece un párrafo porque el nombre engaña: `soporte_plataforma` es una fila de `roles` dentro de cada centro, no el rol interno `soporte` de `platform_admin_roles`. Son dos cosas distintas con nombres parecidos, y **este paso no las une**. `REQ-PERM/permisos.md §5.4` ya anticipó que «1.6 le dará lo que necesite por su propia vía y con su propio registro»: esa vía es `REQ-SUP-003`, y no está aquí.

---

## 9. Verificación

Los criterios completos están en `funcional.md §13`. Los que verifican **esta** matriz:

- **Test de catálogo de capacidades**: el `enum` de capacidades contiene **exactamente** las de §3 y ninguna más. Si el código declara una que este documento no lista, el test falla — mismo espíritu que el test de catálogo de `permissions` de `REQ-CORE/permisos.md §9`.
- **Test de la matriz de §4**, celda a celda: para cada uno de los cuatro roles y cada capacidad, se comprueba que la concede o la deniega **según esta tabla**. Es la única forma de que un cambio de reparto no pase inadvertido.
- **`CA-BO-008`** — `soporte` recibe `403` en **toda** escritura, `job.reintentar` incluido.
- **`CA-BO-009`** — `operaciones` recibe `403` al eliminar un tenant.
- **`CA-BO-010`** — nadie se modifica a sí mismo; nunca queda la plataforma sin `superadministrador`.
- **`CA-BO-062`** — el aprobador no puede ser el solicitante, **comprobado escribiendo por SQL directo**, no por la API: un test que pase por el controlador comprobaría el `if`, no la restricción.
- **`CA-BO-070`** — todo *endpoint* responde `401` sin sesión de plataforma y `403` sin la capacidad.
- **`CA-BO-074`** — ninguna respuesta contiene datos personales de alumnos, familias ni personal de los centros.
- **`CA-BO-094`** — `soporte` **lee** *flags* y no escribe ninguno; `operaciones` escribe.
- **`CA-BO-096`** — test de arquitectura: **ningún control de seguridad consulta el evaluador de *flags*** (`RN-BO-47`, §5.3). Es el segundo test de arquitectura de este módulo, junto al de comparación de códigos de rol, y por el mismo motivo: son propiedades que una revisión de código detecta hoy y deja de detectar cuando el fichero crece.
- **`CA-BO-097`** — la API del tenant devuelve **sólo** los *flags* que evalúan verdadero para quien pregunta: ni los apagados, ni los que están en despliegue parcial y no le han tocado.
- **Test de arquitectura**: **ningún control de acceso de este módulo compara un código de rol** (`RN-BO-04`). Es el candidato (1) de `ADR-044 §8` aplicado aquí, y aquí es más necesario que en el tenant, porque con cuatro roles fijos escribir `if ($admin->hasRole('superadministrador'))` es más cómodo que comprobar la capacidad.
