# REQ-BO · Permisos

> Este documento es **la excepción del proyecto**, y conviene decirlo antes de la primera tabla: es el único módulo cuya autorización **no** se resuelve con el motor de `REQ-PERM`. No porque sea especial, sino porque su sujeto —un administrador de plataforma— **no tiene tenant**, y todo el motor de 1.5 está construido, correctamente, alrededor de que sí lo tenga.
>
> La decisión no es mía: `ADR-034 §2` ya la tomó y `REQ-CORE/permisos.md §4.5` la recoge. Lo que sí me toca es **justificar que sigue siendo la correcta después de 1.5**, cuando por fin existe un motor de verdad y reutilizarlo es una tentación legítima. Eso es §1.
>
> **`ADR-046` no reabre nada de este documento** (`ADR-046 §2.2`: «No decide los roles internos ni el catálogo de capacidades del backoffice»). Lo que sí añade son **tres reglas de autorización que no son capacidades** y que están en §5: el *host* de plataforma, la restricción del *slug* y el propósito declarado de `runAsPlatform()`.
>
> **`1.6c` tampoco añade ninguna capacidad.** Las tres del recurso `modulo` —`modulo.leer`, `modulo.contratar`, `modulo.contratar_masivo`— ya estaban declaradas en §3 y repartidas en §4 desde el chasis. Lo que añade es **§4.4**, el emparejamiento operación por operación con sus tres barreras, y **seis reglas de §5 que no son capacidades**. El único punto abierto es si la descontratación individual es sensible (`OPEN-BO-17`), que es una celda de §4.4 y no un permiso.
>
> **`1.6d` tampoco decide ningún reparto nuevo, y conviene decir por qué parece que sí.** Las tres capacidades de salud y métricas —`salud.leer`, `job.reintentar` y `metrica.leer`— están en §3 y repartidas en §4 **desde el chasis**, con su argumento escrito en §4.1; lo que `1.6d` hace es **declararlas en el `enum` `PlatformCapability`**, que hasta ahora no las tenía porque su propio *docblock* fija la regla: una capacidad se declara *«cuando exista el *endpoint* que la necesite»*, y declarar una sin *endpoint* sería inventar superficie. Lo que añade este sub-paso es **§4.5**, el emparejamiento operación por operación, y **seis reglas de §5 que no son capacidades**. El único punto abierto es si el reintento es sensible (`OPEN-BO-21`), que es una celda de §4.5 y no un permiso.
>
> **`1.6e` es el cuarto sub-paso seguido que no decide ningún reparto nuevo, y el primero que sí toca el `enum`.** Las dos capacidades de *flags* —`flag.leer` y `flag.gestionar`— están en §3 y repartidas en §4 **desde el chasis**, con su argumento en §4.1, que es además el único sitio de este documento donde el reparto se apoya en una palabra **literal** del requisito: `REQ-BO-007` describe el alcance de `operaciones` como «módulos, límites, **flags**». Lo que este sub-paso añade es **§4.6**, el emparejamiento operación por operación, **la declaración de las dos capacidades en el `enum`** —verificado el 2026-09-21: hoy tiene veinticuatro casos y ninguno es de `flag`— y **cuatro reglas de §5 que no son capacidades**. No hay ningún punto abierto de permisos: las cuatro preguntas de `1.6e` —`OPEN-BO-24`, `OPEN-BO-25`, y las dos ya resueltas el 2026-09-21, `OPEN-BO-26` (caché sobre Redis) y `OPEN-BO-11` (rutas por `key`)— son de caché, de superficie pública, de despliegue y de identificadores, y **ninguna toca una celda de §3 ni de §4**. Es el cuarto sub-paso seguido del que eso es cierto, y merece decirse: **la matriz de este módulo no ha cambiado desde el chasis**.

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

### 4.3 Qué capacidad exige cada transición de estado (`1.6b`)

§3 declara cuatro capacidades sobre el recurso `tenant` además de `leer` y `crear`, y §4 dice quién las tiene. Lo que faltaba, y lo que `implementer` necesita para no inventárselo, es **el emparejamiento entre cada arista de la máquina de estados y su capacidad**:

| Transición | Capacidad | Quién la tiene | Por qué esa y no otra |
|---|---|---|---|
| `en_alta` → `activo` | **Ninguna** | Nadie | No es alcanzable por API: la produce el aprovisionamiento (`RN-BO-52`). Una capacidad para forzarla sería una puerta para declarar «listo» un centro que no lo está |
| `activo` → `suspendido` | `tenant.suspender` | `operaciones`, `superadministrador` | Es la respuesta operativa a un incidente y tiene que poder darse de madrugada (§4.1) |
| `suspendido` → `activo` | `tenant.suspender` | Ídem | Deshacer lo propio, con la capacidad propia |
| `activo` → `en_baja` | `tenant.baja` | Sólo `superadministrador` | Es ciclo de vida comercial, no operación |
| `en_baja` → `activo` (rescate) | **`tenant.baja`** | Sólo `superadministrador` | **Y no `tenant.suspender`**: quien no puede dar de baja tampoco debe poder deshacer la baja que decidió otro |
| `en_baja` → `eliminado` | `tenant.eliminar`, **más** reautenticación, confirmación por nombre y doble autorización | Sólo `superadministrador` | Los cuatro cerrojos de `funcional.md §5.5.2` |
| Clonación | `tenant.crear` | Sólo `superadministrador` | Un clon **es** un alta: mismo efecto, mismos datos nuevos, misma capacidad. Darle una capacidad propia dejaría a alguien crear centros por la puerta de al lado |
| Cambio de `slug` | `tenant.actualizar` · sensible | `operaciones`, `superadministrador` | `api.md §2.5` |

> **La consecuencia que hay que ver de un vistazo: `operaciones` puede parar un centro y no puede cerrarlo.** Es la línea que §4.1 ya trazaba en prosa —«`operaciones` no elimina ni da de baja»— y que esta tabla convierte en algo comprobable celda a celda, como el test de la matriz de §9.

### 4.4 Qué exige cada operación sobre módulos (`1.6c`)

§3 declara tres capacidades sobre el recurso `modulo` y §4 dice quién las tiene. Lo que faltaba, y lo que `implementer` necesita para no inventárselo, es **el emparejamiento operación por operación**, con las tres barreras que no son la capacidad: reautenticación, doble autorización y confirmación.

| Operación | Capacidad | Quién la tiene | ¿Sensible? | ¿Doble autorización? |
|---|---|---|:---:|:---:|
| `GET /modules` (catálogo) | `modulo.leer` | Los cuatro roles | No | No |
| `GET /tenants/{id}/modules` | `modulo.leer` | Los cuatro | No | No |
| `POST /tenants/{id}/modules/preview` | `modulo.leer` | Los cuatro | No | No |
| `POST /module-rollouts/preview` | `modulo.leer` | Los cuatro | No | No |
| **Contratar** uno (`PUT …/modules/{code}`, `enabled: true`) | `modulo.contratar` | `operaciones`, `superadministrador` | **No** | No |
| **Descontratar** uno (`PUT …/modules/{code}`, `enabled: false`) | `modulo.contratar` | Ídem | **Sí** (`OPEN-BO-17`) | No |
| **Contratación masiva** (`POST /module-rollouts`, `enabled: true`) | `modulo.contratar_masivo` | Ídem | **Sí** | No |
| **Descontratación masiva** (`POST /module-rollouts`, `enabled: false`) | `modulo.contratar_masivo` | Ídem | **Sí** | **Sí** (`modulo.descontratar_masivo`) |
| **Aprobar** una `modulo.descontratar_masivo` | **`modulo.contratar_masivo`** | Ídem | **Sí** | — |

**Las cuatro decisiones de esta tabla que hay que poder defender:**

**1 · Una sola capacidad para las dos direcciones individuales, y no `modulo.contratar` / `modulo.descontratar`.** Es el mismo criterio con el que §4.3 empareja el rescate con `tenant.baja` y no con `tenant.suspender`: **quien contrata tiene que poder deshacerlo**. Partirlas produciría el caso conocido —alguien que contrata y no puede corregir su propio error, y acaba pidiéndoselo a un `superadministrador` a las nueve de la noche— sin ganar ninguna separación real: la diferencia de peligro entre las dos direcciones no es de **quién**, es de **cuánta fricción**, y eso lo resuelve la reautenticación, no una capacidad más.

**2 · La masiva tiene capacidad propia aunque la tengan los mismos roles.** `modulo.contratar_masivo` no reparte hoy a nadie distinto de `modulo.contratar`, y aun así existe — porque **la diferencia de alcance es real**: una es una escritura facturable sobre un centro y la otra sobre doscientos a la vez. Que hoy coincidan los titulares no significa que deban coincidir siempre, y separarlas cuesta una constante mientras que unirlas cuesta una migración de permisos el día que se separen. Es el mismo argumento con el que §4.1 se niega a darle de más a `comercial` «porque total, aún no hace nada».

**3 · Aprobar una descontratación masiva exige `modulo.contratar_masivo`, no una capacidad de aprobación.** Es §5.2 aplicado: **al aprobar se ejecuta**, luego aprobar es tan potente como ejecutar. No existe `autorizacion.aprobar` y no se crea aquí.

**4 · `soporte` no toca ni una escritura de módulos, y `comercial` tampoco.** `soporte` es «solo lectura y diagnóstico» (§4.1) y lee las cuatro consultas de arriba, **incluidas las dos vistas previa** — que son lectura pura y son exactamente lo que responde a «¿por qué este centro no ve el comedor?». `comercial` tiene `modulo.leer` desde §4 porque «planes y facturación» necesita saber qué está contratado, y **ninguna escritura**: contratar es la operación que produce la factura, no la que la consulta.

> **La consecuencia de un vistazo, como en §4.3: `operaciones` contrata y descontrata módulos en cualquier centro, y no puede cerrar ninguno.** Es coherente con «módulos, límites, flags» de `REQ-BO-007`, que es literal, y con que el ciclo de vida sea del `superadministrador`. Y **la descontratación masiva es la única operación del módulo en la que `operaciones` necesita a otra persona** — no a un `superadministrador`, a **otro `operaciones`**, que es lo que `RN-BO-19` exige y lo que la capacidad de §5.2 permite.

### 4.5 Qué exige cada operación de salud y métricas (`1.6d`)

§3 declara tres capacidades para este terreno —`salud.leer`, `job.reintentar` y `metrica.leer`— y §4 dice quién las tiene. Lo que faltaba, y lo que `implementer` necesita para no inventárselo, es el emparejamiento operación por operación con las barreras que no son la capacidad.

| Operación | Capacidad | Quién la tiene | ¿Sensible? | ¿Doble autorización? |
|---|---|---|:---:|:---:|
| `GET /tenants/{id}/health` | `salud.leer` | `soporte`, `operaciones`, `superadministrador` | No | No |
| `GET /tenants/{id}/failed-jobs` | `salud.leer` | Ídem | No | No |
| **Reintentar** un trabajo (`POST …/failed-jobs/{uuid}/retry`) | **`job.reintentar`** | `operaciones`, `superadministrador` | **Sí** (`OPEN-BO-21`) | No |
| `GET /metrics/platform` | `metrica.leer` | `operaciones`, `comercial`, `superadministrador` | No | No |
| `GET /metrics/module-adoption` | `metrica.leer` | Ídem | No | No |

**Las cuatro decisiones de esta tabla que hay que poder defender:**

**1 · `job.reintentar` es una capacidad aparte de `salud.leer`, y no una acción más del mismo recurso.** No es simetría con `modulo.contratar` —donde §4.4 punto 1 decidió **una sola** capacidad para las dos direcciones—: allí las dos direcciones las hace la misma persona y la diferencia es de fricción; aquí la diferencia es de **naturaleza**, porque el recurso `salud` es de diagnóstico y `soporte` lo tiene entero, mientras que reintentar **es escritura** y `soporte` no puede tener ni una. Si fuesen la misma capacidad, o `soporte` pierde el diagnóstico —que es literalmente su trabajo— o gana una escritura que `REQ-BO-007` le niega.

**2 · `comercial` lee métricas y no lee la ficha de salud, y eso ya estaba decidido en §4.** Merece la frase porque la tentación de darle `salud.leer` «para que vea si un cliente tiene problemas» es real: su alcance es «planes y facturación», la adopción por módulo es exactamente la cifra que necesita para eso, y el detalle de los trabajos de un centro no le dice nada que pueda usar. **No se le da de más «porque total, es sólo lectura»** — es el mismo argumento de §4.1 con el que no se le dio `auditoria_plataforma.leer`, y una lectura concedida de más no se retira nunca.

**3 · `soporte` no lee métricas, y ésa es la asimetría que sorprende.** Tiene `salud.leer` —el diagnóstico de **un** centro, que es su trabajo— y no `metrica.leer`, que es el estado del **parque**: cuántos centros hay, cuántos se han ido y qué se contrata. Eso es información de negocio del proveedor, no una herramienta de soporte, y el reparto de §4 ya lo decidió así. Quien atiende un ticket no necesita saber el *churn*.

**4 · Ninguna operación de este sub-paso pasa por doble autorización, y hay que decir por qué no.** `REQ-BO-007` la exige para *«eliminar un tenant, purgar datos o desactivar módulos en masa»*, y reintentar **un** trabajo no es ninguna de las tres. La que sí lo sería es un reintento masivo — y por eso `RN-BO-88` decide que **no existe** en vez de construirlo con doble autorización: no hay ningún caso de uso que lo pida, y el camino de §5.7 sigue disponible el día que lo haya.

> **La consecuencia de un vistazo, como en §4.3 y §4.4: `soporte` puede verlo todo de un centro y no puede tocar nada; `comercial` puede ver el parque y no puede ver un centro.** Las dos mitades del rol de `REQ-BO-007` —«solo lectura y diagnóstico» frente a «planes y facturación»— quedan, por primera vez en este módulo, con superficie real que las distinga.

### 4.6 Qué exige cada operación de *feature flags* (`1.6e`)

§3 declara dos capacidades sobre el recurso `flag` —`flag.leer` y `flag.gestionar`— y §4 dice quién las tiene, **las dos desde el chasis**. Lo que faltaba, y lo que `implementer` necesita para no inventárselo, es el emparejamiento operación por operación con las barreras que no son la capacidad.

| Operación | Capacidad | Quién la tiene | ¿Sensible? | ¿Doble autorización? |
|---|---|---|:---:|:---:|
| `GET /feature-flags` | `flag.leer` | `soporte`, `operaciones`, `superadministrador` | No | No |
| `GET /feature-flags/{key}` | `flag.leer` | Ídem | No | No |
| `POST /feature-flags/{key}/rules/preview` | `flag.leer` | Ídem | No | No |
| `GET /tenants/{public_id}/feature-flags` | `flag.leer` | Ídem | No | No |
| **Apagar** (`PUT …/state`, destino `forced_off`) | `flag.gestionar` | `operaciones`, `superadministrador` | **No** | No |
| **Encender** (`PUT …/state`, destino `activo`) | `flag.gestionar` | Ídem | **Sí** | No |
| **Escribir el conjunto de reglas** (`PUT …/rules`) | `flag.gestionar` | Ídem | **Sí** | No |
| **Designar o retirar *early adopter*** (`PUT /tenants/{public_id}/early-adopter`) | **`tenant.actualizar`** | Ídem | No | No |
| `GET /api/v1/feature-flags` | **Ninguna** — es del tenant, por identidad del portador | — | — | — |

**Las cinco decisiones de esta tabla que hay que poder defender:**

**1 · Una sola capacidad para las dos direcciones del interruptor, y la asimetría vive en la reautenticación y no en el permiso.** Es literalmente el criterio de §4.4 punto 1 —*«quien contrata tiene que poder deshacerlo»*— aplicado aquí, y con más motivo: partir `flag.apagar` de `flag.encender` produciría a alguien que puede parar una funcionalidad rota a las tres de la mañana y **no** puede volver a encenderla cuando el arreglo esté desplegado, que es la mitad del trabajo. La diferencia de peligro entre apagar y encender es real, pero es de **fricción**, y eso lo resuelve `api.md §4`, no una capacidad más.

**2 · `flag.gestionar` cubre el estado **y** las reglas, y tampoco se parte.** La tentación es dar el freno de emergencia a más gente que el porcentaje. No se hace, y el motivo es que **apagar y arreglar la regla que encendió de más son la misma tarea**: quien apaga un *flag* porque está rompiendo colegios tiene que poder, acto seguido, dejar su regla como estaba. Dos capacidades obligarían a llamar a otra persona en mitad del incidente, que es exactamente lo que el freno sin fricción de `api.md §2.12` existe para evitar.

**3 · La designación de *early adopter* se autoriza con `tenant.actualizar`, y eso tiene una consecuencia visible.** El motivo está en `api.md §2.11` —es un atributo **del centro**, escrito en `tenants`, no una regla de despliegue— y la consecuencia es que **`soporte` puede ver que un centro es *early adopter* y no puede convertirlo en uno**: tiene `flag.leer` y no tiene `tenant.actualizar`. Es el reparto correcto y no una casualidad: meter a un colegio en la cohorte que recibe todo antes que nadie es una decisión sobre la relación con ese cliente, no un diagnóstico.

**4 · `GET /tenants/{public_id}/feature-flags` cuelga de una ruta de tenant y se autoriza con `flag.leer`, no con `tenant.leer`.** La capacidad es la del recurso que se lee, no la del segmento de la URL — mismo criterio que `§4.5` con `GET /tenants/{id}/health`. **No abre ningún camino de enumeración de centros**: los tres roles que tienen `flag.leer` tienen también `tenant.leer` y ya ven el inventario entero en `GET /tenants`.

**5 · Ninguna operación de *flags* pasa por doble autorización, y hay que decir por qué no.** `REQ-BO-007` la exige para *«eliminar un tenant, purgar datos o desactivar módulos en masa»*, y **ninguna escritura de *flag* destruye nada**: se deshace con otra escritura, en segundos y sin desplegar (`RNF-MANT-005`). El argumento completo está en `api.md §2.12`, y su reverso importa: exigir dos personas para mover un porcentaje convertiría el despliegue progresivo en algo que nadie usa, y lo que se usaría en su lugar es desplegar de golpe — justo lo que `RARQ-DEP-010` quiere evitar.

> **La consecuencia de un vistazo, como en §4.3, §4.4 y §4.5: `operaciones` puede exponer u ocultar una funcionalidad en los doscientos centros a la vez, y no puede cerrar ni uno.** Es la misma línea que §4.1 trazó en prosa al repartir «módulos, límites, **flags**», y la única de las cuatro tablas de este apartado en la que una sola llamada alcanza al parque entero sin pasar por una masiva ni por una segunda persona. Es también el motivo de que `api.md §4` ponga la reautenticación en las dos escrituras que exponen, y de que `operacion.md §7` vigile el uso de `forced_off` como señal de calidad.

**Verificado el 2026-09-21 sobre `develop`, y no supuesto**: `PlatformCapability` declara hoy **veinticuatro** casos y **ninguno es de `flag`**. `1.6e` añade **exactamente dos** —`FlagLeer = 'flag.leer'` y `FlagGestionar = 'flag.gestionar'`—, siguiendo la regla que el propio *docblock* del `enum` fija y que `1.6d` ya aplicó: una capacidad se declara **cuando existe el *endpoint* que la necesita**. Con eso el `enum` queda en veintiséis y la matriz de §3 y §4 cuadra celda a celda (`CA-BO-167`).

---

## 5. Reglas de autorización que no son una capacidad

Igual que `REQ-CORE/permisos.md §8` y `REQ-PERM/permisos.md §8`: lo que ninguna comprobación de capacidad cubre y que la revisión de seguridad debe recorrer entera.

| Regla | Dónde | Efecto |
|-------|-------|--------|
| **El *host* de plataforma antes que nada** (`RN-BO-48`) | **Primer** *middleware* de la pila de plataforma, `RequirePlatformHost` (`api.md §1.1`, puesto 1) | **`404`**, no `403`: antes de sesión y de credenciales, y sin revelar que la superficie existe. **No es una capacidad y no hay ninguna que lo relaje** |
| **Lista blanca de IP antes que las credenciales** (`RN-BO-06`) | Segundo *middleware* de la pila (`api.md §1.1`, puesto 2), **y además `ipallowlist` en Traefik** (`operacion.md §0`) | `403` genérico, auditado. **Antes** de tocar la base de datos de usuarios. Las dos capas son obligatorias y ninguna sustituye a la otra |
| **Lista vacía = denegar** (`RN-BO-07`) | Ídem | Nunca «vacía = sin restricción» |
| **MFA incondicional** (`RN-BO-05`) | *Middleware* posterior a la sesión | Sin factor confirmado, sólo `/mfa/*`. **Sin gracia y sin exención** |
| **Reautenticación en operaciones sensibles** (`RN-BO-08`) | *Middleware* sobre la lista cerrada de `api.md §4` | `403` con `type` propio |
| **Nadie se modifica a sí mismo** (`RN-BO-10`) | `PUT /admins/{id}/roles`, `POST /admins/{id}/status`, `DELETE /admins/{id}` | `409` |
| **Siempre un `superadministrador` vivo** (`RN-BO-11`) | Mismas rutas | `409`, con bloqueo de fila: es una restricción de conjunto y no cabe en un `CHECK` (`datos.md §2.2`) |
| **Quien aprueba ≠ quien solicita** (`RN-BO-19`) | Base de datos | `CHECK`. **No es una comprobación de aplicación** |
| **Una solicitud no se resuelve dos veces** (`RN-BO-19`/`RN-BO-20`) | `DualAuthorizationService::approve()`/`reject()` | `409`, con bloqueo de fila: mismo argumento que `RN-BO-11`, restricción de orden entre dos transacciones y no cabe en un `CHECK` (`datos.md §3.3`) |
| **La ejecución usa el `payload` congelado** (`RN-BO-20`) | Servicio de ejecución | Huella distinta ⇒ rechazo. Bloqueo de fila también en `executeApprovedDeletion()` (`datos.md §6.4`): sin él, un rescate concurrente podía colarse entre la comprobación y la escritura |
| **Una transición no se aplica sobre un estado ya superado** (`RN-BO-12`) | `TenantLifecycleService::executeSimpleTransition()` | `409 bo.tenant.invalid_transition`, con bloqueo de fila (`datos.md §6.4`) |
| **El backoffice no devuelve datos personales de los centros** (`RN-BO-33`) | Todo *endpoint* | Restricción **funcional**, no de permiso: no hay capacidad que la conceda |
| **Un `User` de tenant nunca autentica aquí** (`RN-BO-02`) | *Guard* y *provider* separados, cookie *host-only* con nombre propio, y **almacén de sesión propio** (`datos.md §2.6`) | `401`, auditado. Y `plataforma_app` **no puede ni leer** `platform_sessions`: `REVOKE ALL`, verificado por privilegios de motor (`CA-BO-018`) |
| **El *slug* de un centro no puede ser el *host* de plataforma** (`RN-BO-49`) | Alta y cambio de `slug` (`api.md §2.4`, `§2.5`) | `422`. **Tampoco es una capacidad**: ni `superadministrador` puede saltárselo |
| **`runAsPlatform()` exige propósito declarado y ausencia de tenant** (`funcional.md §6.2`) | `App\Support\Tenancy`, con `PlatformAccessCheck` cuyo enlace por defecto **deniega** los propósitos de backoffice | Lanza excepción. **No se comprueba una capacidad dentro de la primitiva** —un comando de consola no tiene a quién comprobársela—: la capacidad la comprueba el llamador, y la primitiva comprueba que el propósito es alcanzable desde donde se invoca (`CA-BO-025`, `CA-BO-027`) |
| **Ningún control de seguridad detrás de un *flag*** (`RN-BO-47`) | Test de arquitectura | El evaluador **no** se invoca desde el *middleware* de MFA, de lista blanca, de autorización, de resolución de tenant ni de doble autorización (`CA-BO-096`) |
| **Un *flag* no concede acceso a un módulo no contratado** (`RN-BO-45`) | Evaluador y `ModuleAvailability` | Son dos comprobaciones distintas y la de módulo es previa. Un *flag* al 100 % no evita el `urn:pge:error:module-disabled` (`CA-BO-092`) |
| **La unidad de reparto no la elige el operador** (`RN-BO-36`) | Descriptor del módulo, materializado; privilegio de columna (`datos.md §9.6`) | Ninguna capacidad permite escribir `rollout_unit`, ni siquiera `superadministrador` |
| **`1.6b` · De `en_alta` no se sale por API** (`RN-BO-52`) | `POST /tenants/{id}/transitions` | `409`. **No hay capacidad que lo permita**: la transición la produce el aprovisionamiento. Forzarla a mano daría por configurado un centro que no lo está |
| **`1.6b` · La confirmación por nombre es literal** (`RN-BO-57`) | Eliminación de tenant | `422`. No es una comprobación de permiso y **ninguna capacidad la salta**, tampoco la de `superadministrador` |
| **`1.6b` · Eliminar un tenant no borra ni un dato suyo** (`RN-BO-56`) | Servicio de eliminación | Es una restricción **funcional**, como `RN-BO-33`: no hay capacidad que conceda purgar, porque no existe el camino. La purga es `REQ-PRIV-006` |
| **`1.6b` · La revocación de sesiones del centro no es una capacidad** (`RN-BO-55`) | Efecto de la eliminación, en cola | Nadie «revoca sesiones de un centro» como operación propia: es una consecuencia de eliminarlo. Un *endpoint* de revocación masiva sería una capacidad de dejar a un colegio fuera sin pasar por el ciclo de vida ni por su auditoría |
| **`1.6b` · El período de gracia no se acorta por configuración** (`RN-BO-61`) | Servicio de baja | 90 días fijos (`REQ-BO-001`). Ni capacidad, ni variable de entorno (`operacion.md §2`) |
| **`1.6c` · Un módulo esencial no se conmuta en ninguna dirección** (`RN-BO-65`) | Servicio de contratación de `REQ-CORE` | `422` al contratar **y** al descontratar. **Ninguna capacidad lo salta**, tampoco la de `superadministrador`: `essential` lo declara el código y la celda está bloqueada (`ADR-045 §4.7`) |
| **`1.6c` · Ninguna escritura deja un módulo contratado sin su dependencia** (`RN-BO-22`, `RN-BO-66`) | Servicio de contratación, con bloqueo de la fila de `tenants` | `409` sin `cascade`; recálculo dentro de la transacción con él. Es una restricción de **orden entre dos transacciones** y no cabe en un `CHECK`: mismo argumento que `RN-BO-11` y `RN-BO-12` (`datos.md §7.6`) |
| **`1.6c` · El estado del centro decide si admite escritura de módulos** (`RN-BO-71`) | Servicio de contratación | `409` en `en_alta` y `eliminado`. **No es una capacidad** y ningún rol la salta. En una masiva no es error: se omite y se reporta |
| **`1.6c` · El backoffice no escribe `created_by`/`updated_by` de `module_subscriptions`** (`RN-BO-73`) | Servicio de contratación | Son referencias a `users` **del centro** y un administrador de plataforma no lo es (`RN-BO-01`). Quedan nulos; el actor vive en `admin_action_logs` |
| **`1.6c` · El motivo interno del operador no lo lee el centro** (`RN-BO-82`) | **Privilegio de columna**, `datos.md §7.7` | Ningún texto libre del proveedor cruza el `GRANT`, mismo criterio ya ratificado para las otras dos tablas. **Sujeta a `OPEN-BO-19`**: si se decide que no, la barrera pasa a ser la proyección del *resource* de `REQ-CORE`, que es más débil |
| **`1.6c` · La descontratación individual no exige doble autorización** | Servicio de contratación | `REQ-BO-007` la exige para eliminar, purgar y desactivar **en masa**; el vocabulario desplegado es `modulo.descontratar_masivo` y no `modulo.descontratar`. **Sí exige reautenticación** (§4.4, `OPEN-BO-17`) |
| **`1.6d` · La ficha de salud no devuelve el *payload* de ningún trabajo** (`RN-BO-84`) | Proyección del *resource*, en **todas** las respuestas de salud | Restricción **funcional**, como `RN-BO-33`: **ninguna capacidad la concede**, tampoco `superadministrador`. Un *payload* serializado contiene el correo y el nombre de personas del centro, y en `SendPasswordResetEmail` además un token de un solo uso. `CA-BO-150` lo comprueba recorriendo la respuesta entera |
| **`1.6d` · Un trabajo de otro centro responde `404`, no `403`** (`RN-BO-90`) | Servicio de reintento | Es aislamiento (`INV-001`) **y** no revelación: un `403` confirmaría que ese `uuid` existe en algún sitio, y un `uuid` es adivinable por fuerza bruta mucho antes que un `slug`. Mismo criterio que `RN-BO-48` con el *host* y que `RN-BO-15` con los centros (`CA-BO-153`) |
| **`1.6d` · El estado del centro decide si admite reintento** (`RN-BO-87`) | Servicio de reintento | `409` en `eliminado`. **No es una capacidad** y ningún rol la salta. Y los estados admitidos **no son los mismos** que los de la escritura de módulos (`RN-BO-71`): aquí `en_alta` sí, porque reparar un aprovisionamiento a medias es justamente lo que hace falta ahí |
| **`1.6d` · No existe reintento masivo** (`RN-BO-88`) | Ausencia de ruta y de bandera | No hay capacidad que lo conceda porque **no hay camino**. Un `retry all` reejecutaría efectos secundarios —correos incluidos— sobre todos los centros a la vez: sería una acción destructiva, y `REQ-BO-007` exige doble autorización a las destructivas (`CA-BO-157`) |
| **`1.6d` · Ninguna lectura agregada corre fuera del bloque de plataforma** (`RN-BO-94`) | `runAsPlatform(BackofficeLectura, …)` | No es una comprobación de permiso y **su fallo es silencioso**: fuera del bloque, `TenantScope` filtra y la métrica sale reducida al tenant activo **sin error**. Es lo que `CA-BO-162` existe para atrapar, y por eso ese test usa tres centros y comprueba el total |
| **`1.6d` · El reintento reencola el *payload* literal** (`RN-BO-85`) | Servicio de reintento | Ni capacidad ni validación: es la condición para que el trabajo vuelva a correr **dentro de su tenant**. Recomponerlo lo dejaría con `tenant_id` nulo sobre `plataforma_app`, escribiendo **sin filtro de RLS** — el modo de fallo de aislamiento más grave del sub-paso, y el único que no da síntoma (`CA-BO-152`) |
| **`1.6e` · El evaluador no acepta un tenant como argumento** (`RN-BO-100`) | Firma de `FeatureFlagEvaluator::isEnabled()`, en `App\Support\FeatureFlags` | **No es una comprobación: es la ausencia de un parámetro.** Un `isEnabled($key, ?int $tenantId)` sería una lectura entre centros disponible en cada módulo del producto, defendida sólo por que nadie pase el segundo argumento — y `INV-001` no se sostiene sobre eso. Sin contexto de tenant devuelve `false`, nunca el valor de otro (`CA-BO-168`) |
| **`1.6e` · La interfaz que sí acepta sujeto explícito no la inyecta nadie fuera del backoffice** (`RN-BO-99`) | Test de arquitectura, más enlace que deniega por defecto | `FeatureFlagExplainer` es, por construcción, la puerta por la que se evalúa para un centro que no es el de la petición. **Ninguna capacidad la protege y ninguna la concede**: la protege que ningún fichero de `app/` fuera de `app/Modules/Backoffice` la inyecte, con el patrón de `PlatformAccessCheck` de `ADR-046 §6.3` (`CA-BO-169`) |
| **`1.6e` · Ningún módulo del producto lee las dos tablas de *flags*** (`RN-BO-99`) | Test de arquitectura, más `REVOKE` en el motor (`datos.md §9.6`) | `plataforma_app` tiene `SELECT` sobre las dos y **es correcto** (§9.6): su contenido no es de ningún centro. Lo que no puede es **exponerlo**, y eso no lo garantiza un permiso sino que la única lectura del tenant es `GET /api/v1/feature-flags`, que devuelve sólo lo que evalúa verdadero para quien pregunta (`CA-BO-097`, `CA-BO-176`) |
| **`1.6e` · El estado del centro decide si admite designación de *early adopter*** (`RN-BO-108`) | Servicio de designación | `409` en `eliminado`. **No es una capacidad** y ningún rol la salta. Y los estados admitidos **no son los mismos** que los de la escritura de módulos (`RN-BO-71`) ni los del reintento (`RN-BO-87`): aquí `en_alta` sí, porque la columna vive en `tenants` y el aprovisionamiento no la toca |


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
- **Test de la matriz de §4.3**, arista a arista de la máquina de estados: para cada transición y cada uno de los cuatro roles, se comprueba que la capacidad exigida es **exactamente** la de esa tabla. Es lo que impide que un reparto se afloje sin que nadie lo note — en particular que el rescate acabe pidiendo `tenant.suspender` «porque es volver a activo».
- **`CA-BO-115`** — de `en_alta` no se sale por API con ninguna capacidad.
- **`CA-BO-126`** — el aislamiento entre centros se mantiene a lo largo de todo el ciclo de vida: operar sobre un tenant no altera nada del otro.
- **`CA-BO-013`** — **toda** ruta de `/api/platform/*` lleva la pila completa de `api.md §1.1`, **incluido el puesto 9, el de capacidad**, comprobado por presencia sobre `Route::getRoutes()`. Es lo que impide que una ruta nueva se quede sin comprobación de capacidad y nadie lo note: `CA-BO-070` prueba las que hay, ésta prueba las que habrá.
- **`CA-BO-018`** — `plataforma_app` **no puede leer `platform_sessions`**, rechazado por el motor y no por la aplicación.
- **`CA-BO-074`** — ninguna respuesta contiene datos personales de alumnos, familias ni personal de los centros.
- **`CA-BO-094`** — `soporte` **lee** *flags* y no escribe ninguno; `operaciones` escribe.
- **`CA-BO-096`** — test de arquitectura: **ningún control de seguridad consulta el evaluador de *flags*** (`RN-BO-47`, §5.3). Es el segundo test de arquitectura de este módulo, junto al de comparación de códigos de rol, y por el mismo motivo: son propiedades que una revisión de código detecta hoy y deja de detectar cuando el fichero crece.
- **`CA-BO-097`** — la API del tenant devuelve **sólo** los *flags* que evalúan verdadero para quien pregunta: ni los apagados, ni los que están en despliegue parcial y no le han tocado.
- **Test de arquitectura**: **ningún control de acceso de este módulo compara un código de rol** (`RN-BO-04`). Es el candidato (1) de `ADR-044 §8` aplicado aquí, y aquí es más necesario que en el tenant, porque con cuatro roles fijos escribir `if ($admin->hasRole('superadministrador'))` es más cómodo que comprobar la capacidad.
- **Test de la matriz de §4.4**, operación a operación: para cada una de las nueve y cada uno de los cuatro roles, se comprueba que la capacidad exigida, la reautenticación y la doble autorización son **exactamente** las de esa tabla. Mismo espíritu que el test de §4.3: es lo que impide que un reparto se afloje sin que nadie lo note — en particular que la descontratación acabe pidiendo una capacidad propia «porque suena más peligrosa», rompiendo el emparejamiento del punto 1.
- **`CA-BO-008` y `CA-BO-094` cubren la lectura de `soporte`; para módulos falta su simétrico**: `soporte` **lee** el catálogo, la matriz del centro y las **dos** vistas previa, y recibe `403` en las cuatro escrituras (`PUT …/modules/{code}` en las dos direcciones y `POST /module-rollouts` en las dos). Es la misma forma que `CA-BO-094` tiene para *flags*.
- **`CA-BO-130`** — contratar un módulo esencial es `422` igual que descontratarlo: **ninguna capacidad abre esa celda**.
- **`CA-BO-132`** — dos operaciones concurrentes sobre el mismo centro no dejan el grafo de dependencias roto.
- **`CA-BO-136`** — los cinco estados de tenant frente a la escritura de módulos, celda a celda.
- **`CA-BO-143`** — la descontratación masiva pasa por doble autorización y la aprueba **otro administrador con `modulo.contratar_masivo`**, no una capacidad genérica de aprobación (§5.2).
- **`CA-BO-147`** — `plataforma_app` **no puede leer `module_subscriptions.reason`**, rechazado por el motor y no por la aplicación. **Depende de `OPEN-BO-19`**; si se resuelve en contra, este criterio se retira y la garantía queda en el *resource*.
- **`CA-BO-148`** — un lote sobre dos centros no altera nada del tercero: ni sus suscripciones, ni su caché, ni sus respuestas.
- **Test de la matriz de §4.5** (`1.6d`), operación a operación: para cada una de las cinco y cada uno de los cuatro roles, se comprueba que la capacidad exigida y la reautenticación son **exactamente** las de esa tabla. Mismo espíritu que los de §4.3 y §4.4 — en particular, que `job.reintentar` no acabe fundiéndose con `salud.leer` «porque las dos son de la misma pantalla», que es lo que le daría a `soporte` una escritura que `REQ-BO-007` le niega.
- **`CA-BO-163`** — el `enum` de capacidades gana **exactamente tres** (`salud.leer`, `job.reintentar`, `metrica.leer`) y ninguna más, y el test de catálogo sigue cuadrando celda a celda con §3 y §4.
- **`CA-BO-165`** — `soporte` **lee** la ficha y el listado de trabajos y recibe `403` al reintentar; `operaciones` reintenta; `comercial` recibe `403` en la ficha y `200` en las dos métricas. Es la comprobación de las dos asimetrías de §4.5 puntos 2 y 3, que son las que una revisión va a querer «arreglar».
- **`CA-BO-150`** — ninguna respuesta de salud contiene el *payload* de un trabajo, su traza, ni ningún dato personal que vinieran dentro (`RN-BO-84`). Es la mitad de `CA-BO-074` que este sub-paso tiene que ganarse: es el primero del módulo que lee una estructura **serializada por otro módulo** sin controlar su contenido.
- **`CA-BO-153`** — un `uuid` de otro centro responde `404`, no `403`, y no encola nada.
- **`CA-BO-158`** — `plataforma_app` **no puede** leer, actualizar ni borrar `failed_jobs`, y **sí** puede insertar; el camino del backoffice **funciona** porque corre por `pgsql_platform`. Comprobado por privilegios de motor, con el patrón de `CA-BO-018` y `CA-BO-030`.
- **`CA-BO-162`** — con tres centros, los agregados los cuentan a los tres, la ficha de uno no contiene nada de los otros dos, y **la misma consulta fuera del bloque de plataforma da un resultado distinto y menor** — que es la demostración de que `RN-BO-94` describe un fallo real y silencioso.
