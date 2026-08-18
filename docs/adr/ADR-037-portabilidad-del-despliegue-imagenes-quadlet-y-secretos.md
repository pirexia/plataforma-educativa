# ADR-037 · Portabilidad del despliegue: imágenes inmutables, unidades Quadlet y gestión de secretos

**Estado**: PROPUESTA
**Fecha**: 2026-08-18
**Concreta**: `ADR-027` (host inicial y Quadlet), `ADR-028` (topología de red y dependencias)
**Enmienda parcialmente**: `ADR-030` (las dos líneas que presuponen `compose.yaml` en producción — ver §9)
**Aclara**: `ADR-028 §1` (quién sirve los ficheros estáticos de la SPA — ver §1.4)
**Afecta a**: `RARQ-DEP-001`, `RARQ-DEP-002`, `RARQ-DEP-003`, `RARQ-DEP-008`, `RARQ-CLOUD-005`, `RNF-MANT-007`, `CLAUDE.md §8`, `CLAUDE.md §9`
**No cierra**: `OPEN-08`, `OPEN-09`, `OPEN-10`, `OPEN-11`

---

## 1. Contexto

El desarrollo va en WSL2 sobre equipo personal, solo con datos sintéticos (`ADR-030`). El alojamiento del piloto sigue sin decidir (`OPEN-11`, decisión de negocio, no técnica). La petición del propietario es explícita: seguir desarrollando en el equipo actual, pero **tomar ahora las decisiones que hagan que lo construido sea instalable en *staging* y producción sin rehacerlo**, sobre un VPS genérico con Podman.

El estado real del repositorio no soporta eso:

| Pieza | Estado hoy | Problema |
|---|---|---|
| `compose.yaml` (raíz) | Un único fichero, de desarrollo | Monta `./apps/api` y `./apps/web` como *bind mount*, construye con `build: context:`, `.env` con contraseñas en claro. Nada de esto es desplegable. |
| `infra/containers/*/Containerfile` | Solo runtime de desarrollo | No copian el código dentro. No existe imagen inmutable. |
| Traefik | No existe | `ADR-028` lo declara único punto de entrada. No hay ni configuración ni contenedor. |
| Unidades Quadlet | No existen | `ADR-027` las exige «antes de alojar datos reales». `infra/` solo tiene Containerfiles. |
| Publicación de imágenes | No existe | `ADR-027` dice «las imágenes se construyen en CI y el host solo las descarga». No hay *pipeline* que las construya ni registro donde publicarlas. |
| Secretos de producción | No existe | `CLAUDE.md §8` exige «secretos fuera del código, en gestor de secretos», sin fijar mecanismo. |

Y hay una **inconsistencia entre decisiones vigentes** que hay que resolver antes de escribir una línea de infraestructura:

- `ADR-030` dice: *«ficheros `compose.yaml` idénticos a los de producción, con un fichero de sobreescritura para desarrollo»*, y da por hecho que el servidor de producción también ejecuta `compose.yaml`.
- `ADR-027` ya dice, sin ambigüedad, que en desarrollo se usa `compose.yaml` pero que se convierte a **unidades Quadlet/systemd antes de alojar datos reales**. Producción nunca fue `compose.yaml`, según la decisión que ya existía.
- `ADR-028 §3` fija la regla `Wants=`+`After=`, nunca `Requires=`/`BindsTo=`, **explícitamente para cuando "los servicios se gestionan como unidades Quadlet/systemd"** — no dice nada sobre `compose.yaml` ni sobre su directiva `depends_on`.

La premisa de `ADR-030` (producción ejecuta `compose.yaml`) contradice lo que `ADR-027` ya había decidido (producción ejecuta Quadlet). No hace falta ninguna equivalencia técnica entre `depends_on` y `Requires=` para verlo: basta con que `ADR-030` asuma un artefacto de producción que `ADR-027` ya había descartado. *(Corrección tras revisión de `doc-reviewer`: una versión anterior de este párrafo afirmaba que el `depends_on` de Compose "se comporta como `Requires=`" por defecto. No es exacto — por defecto `depends_on` solo ordena el arranque, no propaga paradas ni reinicios; esa propagación es una condición explícita y opcional de la especificación Compose, no el comportamiento base. El argumento no la necesita: `ADR-027` ya bastaba para decidir que producción es Quadlet, y `ADR-028 §3` se aplicará con propiedad en cuanto los servicios se gestionen así.)*

Elegir entre las dos posturas hay que hacerlo con un ADR (`CLAUDE.md §11`), y la que se conserva es la que ya estaba vigente y sin ambigüedad: `ADR-027`.

### 1.1 Por qué hay que decidir ahora y no cuando exista el VPS

Porque la decisión no depende del proveedor. Un contenedor construido en CI, publicado en un registro y arrancado por una unidad Quadlet funciona igual en cualquier host con Podman. Lo que depende del proveedor —nombre de dominio, certificado, IP, destino de copias— está correctamente aparcado en `0.10`/`0.10b`/`0.10c`/`0.10d` y **este ADR no lo toca**.

Lo que sí cuesta caro es lo contrario: seguir seis meses desarrollando contra un `compose.yaml` que monta el árbol de fuentes y descubrir al desplegar que la aplicación depende de escrituras en disco local, de rutas absolutas del *bind mount* o de `artisan serve`. Eso es trabajo que habría que rehacer, y `CLAUDE.md §0` obliga a plantarse ante ello.

---

## 2. Qué NO decide este ADR

Explícito, para que nadie lo lea como un adelanto de decisiones bloqueadas:

- **No decide el proveedor de VPS** (`OPEN-11`, paso `0.10`). Sigue abierta.
- **No decide dominio, DNS ni certificado** (`OPEN-08`, paso `0.10b`). Sigue abierta.
- **No decide proveedor de correo** (`OPEN-09`, paso `0.10c`) ni destino de copias (`OPEN-10`, paso `0.10d`).
- **No introduce Kubernetes.** `ADR-024` lo sitúa a partir de 3-5 centros y esa decisión no se toca.
- **No introduce un gestor de configuración** (Ansible, Salt, Puppet). Ver §5.4.

---

## 3. Separación real entre desarrollo y producción

### 3.1 Opciones consideradas

| # | Opción | Coste en solitario | Mantenimiento a 3 años | Invariantes | Reversibilidad |
|---|---|---|---|---|---|
| A | Un solo `compose.yaml` parametrizado con variables | Bajo | — | **Incumple**: no se puede desactivar un `volumes:` de *bind mount* con una variable, ni alternar `build:`/`image:` limpiamente | — |
| B | `compose.yaml` base + `compose.dev.yaml` + `compose.prod.yaml` (lo que insinuaba `ADR-030`) | Medio | Malo | **Incumple `ADR-028 §3`**: `depends_on` propaga como `Requires=` | Media |
| C | `compose.yaml` de desarrollo + `compose.prod.yaml` independiente | Bajo | Malo: dos ficheros que divergen en silencio | Incumple `ADR-028 §3` igual que B | Alta |
| D | **`compose.yaml` solo desarrollo; producción y *staging* solo Quadlet; paridad garantizada por Containerfile multi-etapa** | Medio | Bueno: una sola definición de imagen | Cumple | Alta |

En A, B y C el problema es el mismo y no es de gusto: **el artefacto de producción no puede ser un `compose.yaml`** porque no expresa el modelo de dependencias que `ADR-028` impone. Mantener un `compose.prod.yaml` que nadie ejecuta en el servidor sería código muerto desincronizándose de las unidades reales — deuda desde el primer día.

La opción B además tiene un coste operativo diario que no se ve al decidirla: en cuanto la base deja de ser autosuficiente, el `podman compose up -d` de todos los días pasa a exigir dos `-f` explícitos, y el soporte de fusión de múltiples ficheros varía entre `podman compose` (delegando en `docker compose`) y `podman-compose`. Se paga fricción diaria a cambio de un fichero de producción que no sirve.

### 3.2 Decisión

**Opción D.**

1. **`compose.yaml` (raíz) es y sigue siendo el fichero de desarrollo**, sin disfraz. Se le añade una cabecera que lo declare así de forma inequívoca. Mantiene *bind mounts*, `artisan serve`, `vite dev` y puertos en `127.0.0.1`, porque son correctos para lo que es.

2. **Producción y *staging* remoto se operan exclusivamente con unidades Quadlet** en `infra/quadlet/` (§5). No existe ningún `compose.yaml` de producción.

3. **La paridad dev↔producción no la garantiza el fichero de composición, la garantiza el `Containerfile` multi-etapa.** Una única definición de imagen por servicio, con etapas encadenadas:

   ```
   base   →  runtime, extensiones, versiones          (compartida)
     ├─ dev   →  + herramientas de desarrollo, sin código copiado
     └─ build →  dependencias de producción, assets
          └─ prod →  código copiado, sin herramientas de build, sin escritura
   ```

   Ahí es donde de verdad se produce la deriva entre entornos (una extensión de PHP distinta, una versión de Node distinta), y ahí es donde se ataja. Un `compose.prod.yaml` no evita ninguna de esas derivas; un `FROM base` compartido, sí.

4. **Banco de pruebas local de la topología de producción**: `infra/compose/compose.prodlike.yaml`. Su propósito **no es desplegar**, es verificar en WSL2 —sin VPS— que las imágenes inmutables arrancan, que Traefik enruta y que la SPA habla con la API por el mismo origen. Se ejecuta a mano, nunca a diario, y está documentado como herramienta de verificación, no como entorno.

### 3.3 Consecuencia sobre la SPA: URL de API relativa

Hoy `apps/web/.env` fija `VITE_API_URL=http://localhost:8000/api`, una URL absoluta horneada en el *bundle* en tiempo de compilación. Eso obligaría a **una imagen distinta por entorno**, que es lo contrario de una imagen inmutable promovible de *staging* a producción.

**Decisión**: en la etapa `prod` del `Containerfile` de `web`, `VITE_API_URL` es **`/api`**, relativa. La SPA y la API se sirven bajo el mismo origen a través de Traefik (`ADR-028 §1`), así que la ruta relativa es correcta en *staging*, en producción y en el banco de pruebas local, sin recompilar. En desarrollo se mantiene la URL absoluta, porque ahí la SPA la sirve Vite en el puerto 5173 y la API está en el 8000: orígenes distintos de verdad.

Efecto secundario deseable: la misma imagen que se probó en *staging* es **bit a bit** la que va a producción. Es la condición para que «probado en staging» signifique algo.

### 3.4 Quién sirve los ficheros estáticos (aclaración a `ADR-028 §1`)

`ADR-028 §1` dice que Traefik enruta `/*` a «ficheros estáticos de la SPA». **Traefik no tiene servidor de ficheros**: no puede servir estáticos por sí mismo. La redacción da a entender un componente que no existe.

**Aclaración**: el contenedor `web` de producción es un **nginx que solo sirve ficheros estáticos**, con la SPA copiada dentro de la imagen y un `try_files ... /index.html` para el enrutado del cliente. **Sin una sola directiva `proxy_pass`.** Esto respeta íntegro el espíritu de `ADR-028 §1`: lo prohibido es que el frontend haga de proxy hacia la API, no que sirva sus propios ficheros. No existe relación `web → api`, que era el acoplamiento a eliminar.

Guarda automática, barata: **CI falla si aparece `proxy_pass` en cualquier fichero bajo `infra/containers/web/`.** Un `grep` en el workflow. Un ADR que nadie puede violar por descuido vale más que un ADR bien redactado.

---

## 4. Runtime de la imagen de producción de la API

No se puede decidir «imagen inmutable de producción» sin decidir qué proceso corre dentro. `artisan serve` es un servidor de desarrollo monoproceso y queda descartado sin discusión.

Traefik enruta **HTTP**, no FastCGI: no puede hablar directamente con `php-fpm`. Así que el contenedor de la API tiene que exponer HTTP.

| Opción | Valoración |
|---|---|
| **nginx + php-fpm en el mismo contenedor** (supervisord) | Funciona y es archiconocido. Dos procesos y un supervisor dentro de un contenedor, más una configuración de nginx que mantener. |
| **php-fpm en un contenedor + nginx en otro** | Reintroduce exactamente el patrón que `ADR-028` prohíbe: nginx resolviendo el nombre del *upstream* una sola vez al cargar la configuración. Descartada por contradicción directa con `ADR-028 §4`. |
| **FrankenPHP en modo clásico** | Un solo proceso, servidor HTTP nativo (Caddy) con PHP embebido. Sin supervisord, sin nginx, sin configuración intermedia. |
| **Octane con FrankenPHP/RoadRunner/Swoole en modo *worker*** | Mantiene la aplicación en memoria entre peticiones. Exige disciplina de código (estado en *singletons*, fugas entre peticiones) que un código no escrito para ello viola de formas sutiles — y en multi-tenant, una fuga de estado entre peticiones es una fuga entre tenants (`INV-001`). Descartada, y descartada por seguridad, no por rendimiento. |

**Decisión: FrankenPHP en modo clásico** (una petición, un ciclo de vida completo de la aplicación; **sin Octane, sin modo *worker***).

Justificación como dependencia nueva (`CLAUDE.md §1`): licencia MIT, mantenimiento activo bajo el paraguas de la PHP Foundation, imágenes oficiales publicadas con regularidad, y soporte de primera clase en el ecosistema Laravel. No aplica `RNF-MANT-007` («envolver tras interfaz propia») porque **no es una dependencia de código**: es un SAPI, la aplicación no lo importa ni lo referencia. Cambiarlo por nginx+php-fpm es una modificación del `Containerfile` sin una línea de cambio en la aplicación — reversibilidad total, que es el criterio que manda en este ADR.

Consecuencia deliberada: se descarta el modo *worker* aunque sea la razón principal por la que la gente adopta FrankenPHP. Se adopta por **simplicidad topológica**, no por rendimiento. Si algún día hace falta rendimiento, el modo *worker* se evalúa entonces, con un ADR propio y con una auditoría previa de estado compartido.

---

## 5. Construcción y distribución de imágenes

### 5.1 Registro

| Opción | Coste | Mantenimiento | Reversibilidad |
|---|---|---|---|
| **GHCR** (`ghcr.io`) | Cero cuentas nuevas. Publicación con el `GITHUB_TOKEN` efímero del propio job: **ningún secreto nuevo que gestionar** | Cuota de plan para paquetes privados (ver riesgo abajo) | Alta: cambiar de registro es un `login` y una variable |
| Docker Hub | Cuenta nueva, un repositorio privado en plan gratuito, límites de descarga | Peor | Alta |
| Quay.io | Cuenta nueva; privado requiere plan | Peor | Alta |
| Registro propio (`registry:2`) en el VPS | Un servicio más que operar, con su TLS, su almacenamiento y sus copias — para un solo host | Malo | Media |
| Sin registro: construir en el host | **Incumple `ADR-027`** («el host solo las descarga») y mete toolchain de compilación en un host que solo debe ejecutar contenedores | — | — |

**Decisión: GHCR**, con dos condiciones que no son opcionales:

1. **Verificar la cuota del plan antes de confiar en él.** Para paquetes **privados**, el plan Free de GitHub concede del orden de 500 MB de almacenamiento y 1 GB de transferencia mensual; el plan Pro amplía ambos. La imagen de la API con `vendor/` y el runtime de PHP no es pequeña. **Es un riesgo real de quedarse sin cuota, no teórico**, y debe comprobarse en el primer subpaso de la implementación, no descubrirse en un despliegue. La transferencia consumida desde GitHub Actions no cuenta contra la cuota; la que consume el VPS al descargar, sí.
2. **Política de retención desde el primer día**: se conservan los últimos N *tags* de `develop` y **todos** los de versión (`vX.Y.Z`). Los `sha-` antiguos se purgan automáticamente. Sin esto la cuota se agota sola en semanas.

Se **descarta hacer públicos los paquetes** para esquivar la cuota: la imagen contiene el código completo de la aplicación, y publicarlo mientras el repositorio es privado sería incoherente. Es una decisión comercial del propietario, no técnica, y este ADR no la toma por él.

### 5.2 Nomenclatura y etiquetado

Dos paquetes: `ghcr.io/pirexia/plataforma-api` y `ghcr.io/pirexia/plataforma-web`.

| Disparador | Etiquetas publicadas | Para qué |
|---|---|---|
| `push` a `develop` | `develop` (móvil) y `sha-<7>` (inmutable) | *Staging* |
| `push` de *tag* `v*` en `main` | `X.Y.Z` y `sha-<7>` | Producción |
| `pull_request` | **Ninguna.** Se construye y se descarta | Verificar que el `Containerfile` no está roto sin gastar cuota |

**Regla dura: producción referencia siempre una versión exacta (`X.Y.Z`), nunca una etiqueta móvil.** Es lo que convierte la reversión en una operación de treinta segundos —cambiar una línea y reiniciar la unidad— y por tanto lo que hace cumplible «cada entrega incluye su procedimiento de reversión probado» (`CLAUDE.md §9`).

Se **descarta publicar `latest`**: es una trampa operativa que hace irreproducible saber qué corre en un host.

**Una sola arquitectura: `linux/amd64`.** WSL2 es amd64 y cualquier VPS razonable lo es. Construir también arm64 dobla el tiempo de CI a cambio de nada hoy. Revisable con una línea del workflow si el proveedor elegido resulta ser ARM.

### 5.3 Workflow

**Fichero nuevo, `.github/workflows/build-images.yml`**, no ampliación de `ci-api.yml`/`ci-web.yml`.

Motivo concreto: esos dos workflows son (o serán, cuando se active *branch protection* — `SYSADMIN.md §4`) *required status checks*. Meter en ellos un job que publica artefactos y que **no debe ejecutarse en un PR** obliga a un job condicional que unas veces corre y otras no; y GitHub no considera aprobado un check requerido que no se dispara. El proyecto ya tropezó exactamente con esta trampa al usar filtros `paths:` (`SYSADMIN.md §4`). No se repite.

`build-images.yml` **no re-ejecuta los tests**. Duplicar cinco minutos de suite para no aprender nada nuevo es coste sin beneficio. Si `develop` está roto, la imagen de `develop` estará rota, y `develop` roto ya es el problema a resolver. Para los *tags* de versión sí se exige CI en verde, porque ahí el artefacto va a un entorno con datos reales.

### 5.4 Cómo descarga el host sin credenciales en el repositorio

| Opción | Valoración |
|---|---|
| Paquetes públicos | Elimina la credencial, pero publica el producto. Descartada (§5.1). |
| **PAT de grano fino, solo `read:packages`, con caducidad** | Una credencial de **solo lectura** en el host, en fichero `0600` de `root`, consumida una vez por `podman login` y persistida en `auth.json`. |
| CI empuja al host (`podman save` + `ssh`) | Invierte la dirección: en vez de una credencial de lectura en el host, pone una **llave de acceso a producción en manos de GitHub Actions**. Estrictamente peor. Descartada. |

**Decisión: PAT de grano fino con `read:packages` únicamente.**

Sí, es una credencial en el host, y con un registro privado eso es inevitable. Lo que se acota es su poder: **solo lectura**, **solo paquetes**, con caducidad y rotación documentada. Lo inaceptable sería que estuviera en el repositorio, y no lo está.

**Se descarta cualquier gestor de configuración** (Ansible y equivalentes) para instalar y actualizar el host. Con **un** host, el procedimiento es `scp` de las unidades más un `install.sh` de unas decenas de líneas que copia, sustituye el *tag* y hace `daemon-reload`. Ansible es la respuesta correcta a partir de tres hosts o dos operadores; hoy sería una herramienta más que aprender, versionar y depurar para automatizar algo que se ejecuta una vez al mes. **Es un no explícito**, revisable en E1 (`ARCHITECTURE.md §4.2`).

---

## 6. Unidades Quadlet

### 6.1 Ubicación y forma

`infra/quadlet/`, un fichero por unidad, con las extensiones nativas (`.container`, `.network`, `.volume`, `.build` si hiciera falta).

**Ficheros literales, sin sistema de plantillado.** Ni `envsubst`, ni Jinja, ni Helm-de-pobre. Lo único que difiere entre *staging* y producción es (a) el `EnvironmentFile`, (b) el nombre de la red y (c) el *tag* de la imagen. (a) y (b) se resuelven con las directivas nativas de Quadlet; (c) se resuelve con **un `sed` de una línea en `install.sh`** al copiar la unidad al host, porque Quadlet no expande variables de entorno en `Image=`. Un `sed` no es un sistema de plantillado y no hay que mantenerlo.

### 6.2 Unidades y reglas

Aplicación literal de `ADR-028`:

| Unidad | Notas |
|---|---|
| `plataforma.network` | `NetworkName=plataforma-net`, subred fija `10.89.10.0/24`. Matiz: systemd la crea si no existe y **no la destruye al parar la unidad**, así que es compatible con «la red se crea una vez y no se destruye» (`ADR-028 §2`) siempre que ninguna operación documentada haga `systemctl stop` de ella. Se hace constar en `RUNBOOK.md`. |
| `postgres-data.volume`, `redis-data.volume`, `minio-data.volume` | Volúmenes nombrados. |
| `postgres.container`, `redis.container` | Datos. |
| `api@.container` | **Unidad plantilla**, instanciable (`api@1`, `api@2`), con `ContainerName=plataforma-api-%i`. Ver §6.4 sobre cuántas instancias se habilitan. |
| `worker.container` | Horizon. Se añade cuando haya colas reales (`INV-012`, a partir de 1.1). |
| `web.container` | nginx de estáticos. **Sin `proxy_pass`** (§3.4). |
| `traefik.container` | Único punto de entrada. Descubre contenedores por el socket de Podman (`ADR-028 §4`). |
| `plataforma-migrate.service` | `oneshot`. Ejecuta `artisan migrate --force` **una vez por despliegue**, no una por réplica. |

Reglas de obligado cumplimiento en toda unidad:

- **`Wants=` + `After=`. Nunca `Requires=` ni `BindsTo=`** entre servicios de aplicación (`ADR-028 §3`).
- **Ninguna IP escrita a mano.** Referencias por nombre de servicio (`ADR-028 §4`).
- **`HealthCmd=` en toda unidad, más `Notify=healthy`**: con esa directiva systemd considera la unidad arrancada cuando el chequeo pasa, no cuando el proceso existe. Es la implementación literal de `ADR-028` (Consecuencias) y de `RARQ-DEP-008`.
- **Volúmenes con `:Z`** (`ADR-027`).

Detalle que merece constar: `api@` se relaciona con `plataforma-migrate.service` mediante `Wants=`+`After=`, **no** `Requires=`. Parece temerario —¿arrancar la API antes de migrar?— y no lo es: `RARQ-DEP-003` obliga a migraciones *expand/contract*, es decir, el esquema anterior siempre es compatible con el código nuevo. **Es expand/contract lo que permite que el acoplamiento sea flojo.** Si alguna vez hiciera falta `Requires=` aquí, sería la señal de que se ha colado una migración destructiva.

### 6.3 Traefik y el socket de Podman

`ADR-028 §4` exige que Traefik observe el socket de Podman para actualizar rutas al vuelo. Eso implica montarle el socket, y quien controla el socket controla todos los contenedores. Traefik es el componente **expuesto a Internet**.

**Decisión escalonada, proporcionada al riesgo real:**

- **Ahora (desarrollo, banco de pruebas local, *staging* sin datos reales)**: socket montado en solo lectura, Podman *rootless*, lo que acota el radio de daño al usuario sin privilegios.
- **Antes del primer dato real**: interponer un **proxy de socket** que filtre a los métodos de solo lectura que Traefik necesita. Queda en la lista de §7 como requisito de `0.10e`, no como tarea de hoy.

Se descarta el proveedor de fichero estático de Traefik como forma de evitar el socket: dejaría la resolución de *upstream* congelada, que es exactamente el fallo que `ADR-028` documenta como ya sufrido.

### 6.4 Una réplica de API ahora, no dos

`ARCHITECTURE.md §4.3` y `ADR-028` (Consecuencias) piden **dos réplicas** de la API tras Traefik, recreadas de una en una, para cumplir `RARQ-DEP-001` sin orquestador.

**Decisión: la unidad se escribe como plantilla (`api@.container`), pero se habilita una sola instancia hasta que exista tráfico real.**

Argumento: dos réplicas duplican consumo de memoria en un host de piloto, y el despliegue sin corte que habilitan protege a **cero** usuarios mientras no haya centro. Es complejidad sin beneficio proporcional hoy, que es el criterio del que este rol responde. Pasar a dos instancias es `systemctl enable --now api@2` sobre una unidad ya escrita: coste de aplazarlo, prácticamente nulo. **La decisión de `ADR-028` no se revoca**: se difiere su activación, y el requisito de tenerla activa **antes del primer usuario real** queda en §7 como condición de `0.10e`.

### 6.5 Cómo se prueban sin host real

Esta es la pregunta que decide si el paso tiene sentido antes de `OPEN-11`. Tres niveles, dos ejecutables hoy:

1. **Validación de sintaxis, hoy y en CI**: el generador de Quadlet admite ejecución en seco y emite la unidad systemd resultante. Se puede ejecutar en WSL2 y **engancharse a CI** para que un fichero mal escrito falle el PR. Coste: unas líneas de workflow.
2. **Arranque funcional real en WSL2, hoy**: el entorno ya usa systemd de usuario (`systemctl --user enable --now podman.socket`, `SYSADMIN.md §1.2`), así que las unidades se instalan en `~/.config/containers/systemd/` y la pila entera arranca con `systemctl --user start`. Esto verifica de verdad: orden de arranque, que `Wants=` no propaga reinicios, que los chequeos de salud gobiernan el arranque, que Traefik enruta, y **la prueba obligatoria previa a despliegue de `ARCHITECTURE.md §4.3`** (reiniciar la API sin que caiga el frontend; reiniciar PostgreSQL y que la API reconecte sola; recrear la API y que Traefik siga enrutando).
3. **Lo que NO se puede verificar en WSL2 y queda declarado como no verificado**: SELinux en `enforcing` (WSL2 no lo tiene, así que `:Z` se escribe pero no se prueba), `loginctl enable-linger` y arranque en el arranque del sistema real, TLS con certificado comodín (bloqueado por `OPEN-08`), y cualquier cifra de rendimiento (`ADR-030` ya lo advierte).

**El punto 3 se documenta explícitamente en `SYSADMIN.md §5` como código escrito y no ejecutado en su entorno de destino.** Declararlo «listo» sería exactamente la afirmación no verificada que `CLAUDE.md §0` prohíbe.

---

## 7. Gestión de secretos

### 7.1 Opciones consideradas

| Opción | Coste en solitario | Recuperación ante desastre | Veredicto |
|---|---|---|---|
| `.env` en el repositorio | — | — | Prohibido. |
| **Fichero fuera del repositorio + `EnvironmentFile=`**, `root:root`, `0600` | Cero | Trivial: es un fichero, se copia al gestor de contraseñas personal | **Elegida** |
| `podman secret` | Bajo | Media | No aporta cifrado (el driver de fichero guarda en claro); solo mejora la higiene de `inspect`. Beneficio marginal. |
| `systemd-creds` | Medio | **Mala**: el cifrado queda atado a la máquina; restaurar en un host nuevo obliga a recifrar todo. Trampa de recuperación para un operador en solitario | Descartada hoy |
| Gestor externo (Vault, Infisical, 1Password, Bitwarden Secrets) | Alto: un servicio más que operar, o una suscripción, y una dependencia de arranque | Variable | Desproporcionado con **un** host. Descartada |

### 7.2 Decisión

**Fichero de entorno fuera del repositorio, en `/etc/plataforma/<entorno>.env`, propiedad de `root`, permisos `0600`, referenciado desde las unidades con `EnvironmentFile=`.**

No es lo más sofisticado. Es lo que un operador en solitario puede mantener correctamente durante tres años, y un mecanismo correcto y aburrido bate a uno elegante que se degrada. Reglas que lo acompañan y que sí son obligatorias:

1. **Se genera, no se escribe a mano.** Un comando documentado produce contraseñas aleatorias. Contraseñas elegidas por una persona es la causa raíz de la mitad de los incidentes de este tipo.
2. **`infra/quadlet/plataforma.env.example`** se versiona: documenta **todas** las variables con valores vacíos. Es la referencia de qué hay que rellenar; nunca contiene un valor.
3. **Rotación documentada** en `RUNBOOK.md`, con su procedimiento por variable.
4. **`APP_KEY` merece tratamiento aparte.** Es la clave de cifrado de la aplicación: sin ella, los datos de categoría especial cifrados (`CLAUDE.md §8`: salud, NEAE, convivencia) son **irrecuperables**. Debe estar en la copia de seguridad y **almacenada por separado de la copia de la base de datos** — una copia que contenga ambas cosas juntas anula el cifrado. Esto es una entrada obligatoria para `0.10d` y se hace constar allí.

Riesgo asumido y declarado: los valores quedan visibles en el entorno del proceso (`/proc/<pid>/environ`, `podman inspect`) para quien tenga `root` en el host. Con un solo operador es aceptable —quien tiene `root` ya lo tiene todo—. **Deja de ser aceptable en cuanto haya un segundo operador o un tercero con acceso al host**, y ese es el disparador explícito para reabrir esta decisión con un ADR nuevo.

### 7.3 Secretos en CI

**Ninguno nuevo.** La publicación en GHCR usa el `GITHUB_TOKEN` efímero que Actions genera por job. Es un argumento de peso a favor de GHCR frente a cualquier otro registro: cero superficie de credenciales de larga vida en la CI.

---

## 8. Qué se prepara ahora y qué espera a que exista destino

**Ejecutable hoy, sin VPS y sin ninguna decisión abierta:**

- Containerfiles multi-etapa de `api` (FrankenPHP) y `web` (nginx de estáticos), con las etapas `dev` compartiendo `base` con las de `prod`.
- `build-images.yml`, GHCR, esquema de etiquetas y política de retención. **Verificable de extremo a extremo hoy**: se publica desde CI y se descarga desde WSL2.
- Unidades Quadlet completas, validadas en seco en CI y **arrancadas de verdad** con systemd de usuario en WSL2 (§6.5).
- `infra/compose/compose.prodlike.yaml` con Traefik, para verificar la topología de `ADR-028` en local.
- Procedimiento de despliegue y **de reversión** en `RUNBOOK.md`, con la reversión **probada en WSL2** (bajar el *tag* a la versión anterior y reiniciar la unidad).
- `infra/quadlet/plataforma.env.example` y la convención de secretos documentada.
- `SYSADMIN.md §5` reescrito: deja de decir «cuando exista destino» y pasa a decir qué está escrito, qué está probado y **qué está escrito pero no probado**.

**Bloqueado por decisiones ya registradas, y que este ADR no adelanta:**

| Pieza | Bloqueada por | Paso |
|---|---|---|
| Proveedor, IP, dimensionado del host | `OPEN-11` | `0.10` |
| Dominio, DNS comodín, certificado por DNS-01 | `OPEN-08` | `0.10b` |
| Correo transaccional (SPF/DKIM/DMARC) | `OPEN-09` | `0.10c` |
| Destino de copias en proveedor distinto, y custodia de `APP_KEY` | `OPEN-10` | `0.10d` |
| SELinux `enforcing` verificado, `linger`, arranque en boot | Destino real | `0.10e` |
| Proxy de socket delante de Traefik (§6.3) | Antes de datos reales | `0.10e` |
| Segunda réplica de API activa (§6.4) | Antes del primer usuario real | `0.10e` |
| Contrato de encargado de tratamiento | `OPEN-07` | `0.12` |

---

## 9. Relación con decisiones anteriores

- **`ADR-027` se concreta, no se cambia.** «Imágenes construidas en CI, el host solo las descarga» pasa de intención a mecanismo (§5). «Convertidos a unidades Quadlet antes de alojar datos reales» pasa de plazo a estructura de ficheros (§6).
- **`ADR-028` se concreta y se aclara.** Se concreta en directivas Quadlet nombradas (§6.2). Se aclara el hueco de §1: Traefik no sirve ficheros, los sirve un nginx sin `proxy_pass` (§3.4). La aclaración no altera ninguna de las cuatro medidas de `ADR-028`.
- **`ADR-030` se enmienda en dos líneas, ambas por la misma razón: presuponían que producción ejecutaría `compose.yaml`, premisa que `ADR-027` ya había descartado.**
  1. Se sustituye *«ficheros `compose.yaml` idénticos a los de producción, con un fichero de sobreescritura para desarrollo»* por: **el `compose.yaml` es de desarrollo; producción y *staging* se operan con Quadlet; la paridad entre entornos la garantiza el `Containerfile` multi-etapa, no el fichero de composición.**
  2. En "Distribución y runtime", la frase *«Podman, no Docker Desktop, para mantener paridad con el destino de producción y evitar que `compose.yaml` funcione en desarrollo y falle en el servidor»* también da por hecho que el servidor ejecuta `compose.yaml`. El fondo de la frase —usar Podman por paridad con producción y no Docker Desktop— sigue vigente sin matices: es la razón por la que hoy se puede escribir `Containerfile`/Quadlet compatibles con el destino final sin tenerlo todavía. Lo que deja de ser cierto es el ejemplo concreto ("falla en el servidor" presupone que el servidor corre `compose.yaml`, y no lo hará).

  Todo lo demás de `ADR-030` —WSL2, solo datos sintéticos, perfil reducido, límites de recursos, ficheros en el sistema de ficheros de Linux— queda intacto y vigente.

  Motivo de la enmienda: ambas líneas presuponían que producción se operaría con `compose`. `ADR-027` ya decidía lo contrario desde antes de este ADR («convertidos a unidades Quadlet/systemd antes de alojar datos reales»), así que no es una decisión nueva que compita con `ADR-030`: es una inconsistencia entre `ADR-030` y una decisión anterior que ya tenía prioridad, que `ADR-037` resuelve a favor de la que ya existía.

---

## 10. Consecuencias

**A favor:**

- El artefacto que se prueba en *staging* es **bit a bit** el que va a producción, y la misma imagen sirve para ambos (§3.3).
- La reversión pasa a ser una operación de segundos sobre una versión exacta, lo que hace por fin cumplible `CLAUDE.md §9` («procedimiento de reversión probado»).
- La topología de `ADR-028` se verifica de verdad en WSL2 antes de que exista host, incluida la prueba obligatoria de `ARCHITECTURE.md §4.3`.
- Cero secretos nuevos en CI; una única credencial de solo lectura en el host.
- La portabilidad que `ARCHITECTURE.md §4.4` promete («levantar en otro proveedor es cuestión de horas») deja de ser una aspiración y pasa a tener soporte material: imágenes en un registro y unidades en un directorio.

**En contra, y asumido:**

- **Dos definiciones de topología que hay que mantener en paralelo**: `compose.yaml` (desarrollo) y `infra/quadlet/` (producción). El coste se acota porque comparten `Containerfile`, que es donde estaría la deriva peligrosa; pero un servicio nuevo hay que darlo de alta en los dos sitios, y eso hay que decirlo sin adornos.
- **Código escrito y no probado en su entorno real** (SELinux, `linger`, TLS). Se marca como tal en `SYSADMIN.md`; no se declara terminado.
- **Dependencia de la cuota de GHCR**, mitigada con retención y verificable en el primer subpaso. Si la cuota no da, la salida es un registro propio en el VPS cuando exista, sin cambios en la aplicación.
- **Una dependencia de runtime nueva** (FrankenPHP), justificada en §4 y reversible sin tocar código de aplicación.
- El desarrollo diario **no cambia en absoluto**: `podman compose up -d` sigue funcionando igual. Es condición del diseño, no un efecto colateral.

---

## 11. Alternativas descartadas

- **`compose.yaml` base con sobreescrituras por entorno** (lo que `ADR-030` insinuaba): incumple `ADR-028 §3` y encarece el flujo diario. §3.1.
- **`compose.prod.yaml` independiente**: código muerto que nadie ejecuta en el servidor y que se desincroniza. §3.1.
- **Octane / FrankenPHP en modo *worker***: estado en memoria entre peticiones es, en multi-tenant, riesgo de fuga entre tenants (`INV-001`). §4.
- **nginx y php-fpm en contenedores separados**: reintroduce la resolución de *upstream* congelada que `ADR-028 §4` prohíbe. §4.
- **Docker Hub, Quay, registro propio**: cuenta o servicio nuevos sin ventaja sobre GHCR. §5.1.
- **Paquetes públicos en GHCR**: publicaría el producto completo mientras el repositorio es privado. §5.1.
- **CI empujando imágenes al host por SSH**: pone una credencial de escritura en producción en manos de GitHub Actions. §5.4.
- **Ansible o equivalente**: correcto a partir de tres hosts; hoy es una herramienta más que mantener para una operación mensual. §5.4.
- **Gestor de secretos externo**: desproporcionado con un host y un operador; añade una dependencia de arranque. §7.1.
- **`systemd-creds`**: cifrado atado a la máquina, mala recuperación ante desastre para un operador en solitario. §7.1.
- **Kubernetes**: `ADR-024` lo sitúa a partir de 3-5 centros. No se toca.
- **Esperar a `OPEN-11` para escribir todo esto**: es lo que se venía haciendo, y es lo que convertiría seis meses de desarrollo contra *bind mounts* en trabajo por rehacer.

---

## 12. Propuesta de cambio en `PLAN-IMPLEMENTACION.md`

**Este ADR no edita el plan.** La propuesta concreta, para que la aplique la sesión orquestadora:

### 12.1 Paso nuevo `0.9b`, insertado entre `0.9` y `0.10`

Se propone **`0.9b`, no `0.10a`**. Argumento: toda la serie `0.10` está marcada con ⚠️ y bloqueada por decisiones abiertas de negocio. Este paso **no depende de ninguna**, y colocarlo dentro de esa serie transmitiría lo contrario a quien lea el plan. La convención de sufijos alfabéticos ya está en uso (`0.10b`, `0.11c`, `1.14b`, `1.15b`), así que no introduce notación nueva.

Texto propuesto:

> - [ ] **0.9b · Portabilidad del despliegue: imágenes, Quadlet y secretos** [SONNET]
>   Implementa `ADR-037`. Containerfiles multi-etapa (`base`/`dev`/`build`/`prod`) para `api` (FrankenPHP en modo clásico) y `web` (nginx de estáticos, sin `proxy_pass`); `build-images.yml` publicando en GHCR con etiquetado por `sha` y por versión, y política de retención; unidades Quadlet en `infra/quadlet/` conformes a `ADR-028` (`Wants=`+`After=`, red externa, sin IPs, `HealthCmd`+`Notify=healthy`), validadas en seco en CI y **arrancadas de verdad con systemd de usuario en WSL2**; `infra/compose/compose.prodlike.yaml` con Traefik como banco de pruebas local; convención de secretos por `EnvironmentFile=` fuera del repositorio; procedimiento de despliegue y **de reversión probada** en `RUNBOOK.md`. **No requiere host: no depende de `OPEN-08`/`OPEN-11`.** Lo no verificable en WSL2 (SELinux `enforcing`, `linger`, TLS real) se documenta en `SYSADMIN.md §5` como escrito y no probado — no se declara terminado.

Subpasos sugeridos, en orden de dependencia:

| # | Contenido | Verificable hoy |
|---|---|---|
| 0.9b.1 | **Verificar la cuota de GHCR del plan actual** (§5.1) antes de construir nada sobre esa base | Sí |
| 0.9b.2 | Containerfiles multi-etapa `api` y `web`; `VITE_API_URL` relativa en `prod` | Sí |
| 0.9b.3 | `build-images.yml`, etiquetado, retención; descarga de prueba desde WSL2 | Sí |
| 0.9b.4 | Unidades Quadlet + validación en seco enganchada a CI + guarda `grep proxy_pass` | Sí |
| 0.9b.5 | Arranque real con systemd de usuario en WSL2 y **las tres pruebas obligatorias de `ARCHITECTURE.md §4.3`** | Sí |
| 0.9b.6 | `compose.prodlike.yaml`, convención de secretos, `RUNBOOK.md` (despliegue y reversión probada), `SYSADMIN.md §5` | Sí |

Modelo **`[SONNET]`**, no `[OPUS+SONNET]`: toda la decisión de diseño está resuelta en este ADR. Lo que queda es escribir Containerfiles, YAML y unidades, y verificarlo. Marcarlo `[OPUS]` gastaría cuota (`CLAUDE.md §2`) en un trabajo que no la necesita.

### 12.2 Reformulación de `0.10e`

`0.10e` hoy dice *«Con un único host, staging convive con producción mediante separación de red y datos, o se levanta una segunda VM pequeña»*, presuponiendo que el host ya existe. Con `0.9b` hecho, su alcance se reduce a **instanciar sobre el host real lo ya escrito y probado**. Texto propuesto:

> - [ ] **0.10e · Entorno de staging** [SONNET] · *depende de `0.9b` y de `0.10`*
>   Instancia sobre el host real las unidades Quadlet de `0.9b` (`ADR-037`): red y datos separados de producción, `EnvironmentFile` propio, y descarga desde GHCR con PAT de solo lectura. Verifica lo que WSL2 no puede: SELinux `enforcing` con `:Z`, `linger` y arranque en boot, TLS real. Activa antes del primer dato real el **proxy de socket delante de Traefik** (`ADR-037 §6.3`) y, antes del primer usuario real, la **segunda réplica de API** (`ADR-037 §6.4`). `RARQ-CLOUD-005` pide cuatro entornos: documenta cuáles existen de verdad y cuáles no.

### 12.3 Adición a `0.10d`

Añadir a la descripción de `0.10d`: **la custodia de `APP_KEY` separada de la copia de la base de datos** (`ADR-037 §7.2`, punto 4). Una copia que contenga ambas juntas anula el cifrado de los datos de categoría especial.

### 12.4 Índice de ADR

Añadir a la tabla «ADR en fichero propio» de la sección 18 de `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md`:

> | `ADR-037` | Portabilidad del despliegue: imágenes inmutables, unidades Quadlet y gestión de secretos (**concreta `ADR-027` y `ADR-028`; enmienda la línea de `compose.yaml` de producción de `ADR-030`**) |
