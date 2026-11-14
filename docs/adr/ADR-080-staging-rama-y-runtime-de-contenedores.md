# ADR-080 · Entorno de staging barato, rama `staging` con promoción sin *squash* y nivel 1 de runtime de contenedores agnóstico

**Estado**: **PROPUESTA** (2026-11-12). La acepta el usuario; hasta entonces no se implementa nada de `0.10e`.
**Fecha**: 2026-11-12
**Origen**: decisiones del usuario del 2026-11-05 (staging barato con dominio propio; flujo `develop` → `staging` → `main` sin *squash*; separación de redes de #484; proyecto agnóstico al runtime de contenedores, aplazado hasta tratar staging) y `PLAN-IMPLEMENTACION.md`, paso `0.10e`.
**Afecta a**: `PLAN-IMPLEMENTACION.md` (`0.10e`), `CLAUDE.md §1`, `§4` y `§9`, `infra/quadlet/`, `infra/install.sh`, `.github/workflows/`, `.claude/hooks/block-protected-branch.sh`, skills `espera-ci`, `pr-check` y `contenedores-y-red`, `SYSADMIN.md`, `RUNBOOK.md`, los guiones de `apps/web/scripts/` e `infra/` que invocan `podman`. Este ADR **no toca ninguno**: los cambios se proponen en `§11`.
**Concreta sin sustituir**: `ADR-028` (sus reglas se aplican a cada red de `§3.7`), `ADR-030` (staging hereda «solo datos sintéticos»; Podman sigue siendo el runtime por defecto), `ADR-037` **salvo su `§5.2`, que sustituye en parte (abajo)** (concreta `§6.3`, `§6.4`, `§6.5` punto 3 y la tabla de `§8`: este ADR dice dónde y cuándo se verifican), `ADR-076 §2.3` (columna «Staging contra el proveedor real»).
**Sustituye en parte a**:
- `CLAUDE.md §4` como norma de proceso (no es un ADR, pero es donde vive la decisión): «Ramas permanentes: `main` y `develop`» y «`main` solo recibe merges desde `develop`» pasan a leerse con `staging` en medio (`§4`).
- `ADR-037 §5.2`, columna «Para qué» de la fila «`push` a `develop`»: la imagen `sha-<7>` construida en `develop` sigue siendo la que se despliega en staging, pero **solo la del commit al que apunta la rama `staging`**; la etiqueta móvil `develop` deja de usarse para desplegar (`§4.5`).
**No cierra**: `OPEN-08` (dominio y DNS de la plataforma), `OPEN-09`, `OPEN-10`, `OPEN-11` (alojamiento del piloto), `OPEN-STO-*` (`ADR-083`, en `PROPUESTA`).
**Se apoya en**: `RARQ-CLOUD-001`, `RARQ-CLOUD-005`, `RARQ-CLOUD-006`, `RARQ-DEP-001`, `-006`, `-008`, `-012`, `-013`, `RARQ-INF-002`, `RNF-MANT-007`, `CLAUDE.md §8` y `§9`.

Convención de este documento: **V** = verificado en fuente primaria el 2026-11-12 (`§12`); **SV** = sin verificar. Ningún precio, versión ni disponibilidad se da de memoria.

---

## 1 · Contexto

### 1.1 Qué hay hoy (comprobado en el repositorio, 2026-11-12, `develop` en `b7995bc`)

- Desarrollo en WSL2 con Podman y `compose.yaml` (`ADR-030`). Unidades Quadlet escritas y arrancadas con `systemd --user` en WSL2 (`ADR-037 §6.5`); `infra/install.sh <tag> [--user]`.
- `infra/quadlet/traefik.container` publica **solo el puerto 80, sin TLS** («bloqueado por `OPEN-08`»), monta el socket de Podman en solo lectura y no tiene proxy de socket delante. Una sola red, `plataforma-net` (`10.89.10.0/24`). Una sola réplica de `api@`.
- `TRUSTED_PROXIES` confía en la subred entera (#484, cerrado con el PR #539): otro contenedor de la red puede falsear `X-Forwarded-For` (limitación escrita en `SYSADMIN.md`). La separación de redes que el usuario eligió el 2026-11-05 **no está hecha**.
- No hay servicio para el planificador (`schedule:work`) ni en `compose.yaml` ni en Quadlet (#453, Media, abierto).
- `build-images.yml` publica en GHCR al hacer *push* a `develop` (`develop` y `sha-<7>`) y a etiquetas `v*`. Los flujos de CI se disparan para `develop` y `main`. No existe la rama `staging` ni ninguna etiqueta `v*`.
- **El repositorio es público** (comprobado con `gh repo view`). `ADR-037 §5.1` razonó la cuota de GHCR suponiendo repositorio privado; no se ha podido comprobar la visibilidad de los paquetes (el *token* no tiene ese permiso; **SV**).
- En el clon local, `origin/main` tiene **un commit que no está en `develop`** (`e2c6456`, `renovate.json`) y `develop` lleva 287 por delante: `main` no es hoy ancestro de `develop` (`§4.6`).
- 23 ficheros que no son documentación mencionan `podman`; los que lo **ejecutan** son los guiones de `§5.1`.

### 1.2 Qué no se ha probado nunca y solo se puede probar en un servidor

`ADR-037 §6.5` punto 3 y su tabla de `§8` lo declararon «escrito y no ejecutado»: SELinux en `enforcing` con `:Z`, `linger` y arranque en el arranque del sistema, TLS con certificado comodín, proxy de socket delante de Traefik, segunda réplica de la API. `ADR-076 §2.3` añade todo lo que Garage no implementa. A eso se suman la reversión en un host real, la IP de cliente tras Traefik con una IP pública de verdad y el planificador (#453).

### 1.3 Por qué decidir ahora

Sin staging, todo lo anterior se estrenaría con el primer centro. `OPEN-11` (dónde vive el piloto) sigue abierta y exige contrato de encargado, que depende de `OPEN-07`. Staging **no necesita esperar a eso** si se cumple una condición: no contiene ningún dato real (`§3.2`).

## 2 · Qué no decide este ADR

- **No decide el alojamiento del piloto ni de producción** (`OPEN-11`). El proveedor de staging es un ensayo barato y cancelable, no un compromiso. Elegir el mismo después es posible, no obligado.
- **No decide el dominio comercial de la plataforma** (`OPEN-08`). Propone qué necesita staging (`OPEN-STG-02`).
- **No decide el proveedor S3** (`ADR-083`, `OPEN-STO-01`) ni la configuración del *bucket* (`OPEN-STO-03`, `-08`, `-11`). Staging consume lo que allí se decida.
- **No decide el correo** (`OPEN-09`, `ADR-079`) ni las copias (`OPEN-10`).
- **No introduce** Kubernetes (`ADR-024`), un gestor de configuración (`ADR-037 §5.4`), Terraform ni una segunda definición de producción.
- **No crea un entorno de preproducción.** `RARQ-DEP-012` (ensayo con copia reciente de datos anonimizados) **no queda cumplido** por este ADR (`§3.2`, `OPEN-STG-10`).

## 3 · Decisión A: entorno de staging

### 3.1 Opciones

| Opción | Ventajas | Riesgos | Coste en solitario | Mantenimiento a 3 años | Coste de revertir |
|---|---|---|---|---|---|
| **A1 · VPS pequeño propio, permanente, en la UE, con Quadlet** | Prueba todo `§1.2` en un host real; ensaya el procedimiento de despliegue y reversión tal como será en producción; desacoplado de `OPEN-11` y de `OPEN-07` | Un servidor expuesto a Internet que parchear; coste fijo mensual | Bajo: `0.9b` ya dejó escrito casi todo; faltan TLS, red y lo de `§3.7` | Parches del sistema y renovación del certificado (automática) | Mínimo: se cancela el VPS; nada del producto depende de él |
| A2 · Staging en el mismo host que producción, con red y datos separados (texto original de `0.10e`) | Sin coste añadido | El host no existe (`OPEN-11`); un error en staging puede tumbar producción; mezcla sintéticos y reales en la misma máquina | Nulo hoy, porque no se puede empezar | Alto: aislar dos entornos en un host | Medio |
| A3 · VPS efímero, creado para cada ensayo y destruido después | El más barato (facturación por horas) | Certificado comodín a reemitir o custodiar fuera, DNS cambiante, exige automatizar la creación (dependencia nueva), no detecta lo que solo falla con días de marcha (renovación, `linger`, discos, planificador) | Alto | Medio | Mínimo |
| A4 · Seguir solo con WSL2 (`compose.prodlike.yaml` y Quadlet de usuario) | Cero coste | Es la situación actual: deja sin probar todo `§1.2` | Nulo | Nulo | — |
| A5 · PaaS (Vercel, Supabase y similares) | Sin servidor que operar | Descartado por el usuario el 2026-11-05: no ejecuta Laravel con *workers*, choca con los roles y RLS de PostgreSQL y no valida Quadlet ni Traefik | — | — | — |

**Recomendación: A1.** Es la única que prueba lo que falta, y la más reversible: lo peor que puede pasar es perder unos euros y cancelar. A3 ahorra del orden de 4 € al mes a cambio de automatización nueva y de no ver los fallos de larga duración; no compensa. A2 se reconsidera solo si `OPEN-11` acabara en un host con capacidad sobrada, y aun así mezclar sintéticos y reales en la misma máquina es mala idea.

### 3.2 Reglas del entorno (propuesta)

1. **Solo datos sintéticos** (`REQ-SEED`, `REQ-SEED-005`). La prohibición de `ADR-030` se extiende a staging sin excepción: ni una exportación real, ni una copia de producción «anonimizada a mano». Por eso staging no necesita contrato de encargado y puede existir antes de `OPEN-07`, y por eso **no es la preproducción de `RARQ-DEP-012`**.
2. **Región de la UE** (`RARQ-CLOUD-006`), aunque no haya datos reales: se ensaya lo que será.
3. **Staging se puede destruir y recrear** en cualquier momento. Nada vive solo allí. Sus secretos son propios y distintos de los de cualquier otro entorno (`APP_KEY`, claves S3, contraseñas de base de datos).
4. **Mismo procedimiento que producción**: unidades Quadlet de `infra/quadlet/`, `install.sh <tag>` con etiqueta exacta `sha-<7>`, `EnvironmentFile` propio, descarga desde GHCR, y **`APP_ENV=production`** (revisión C2-01: es lo que se ensaya; arrastra las guardas de arranque de producción, como `MailEnvironmentGuard`, `§3.6`). Lo que difiera se escribe en `SYSADMIN.md` como diferencia conocida.
5. **Nunca `compose` en el servidor** (`ADR-028`, `CLAUDE.md §9`) y **nunca Garage** (`ADR-076 §2.2`).
6. `RARQ-CLOUD-005` pide cuatro entornos. Con este ADR existen **dos**: desarrollo y staging. Preproducción y producción no existen; se dice así en `SYSADMIN.md` (`OPEN-STG-10`).

### 3.3 Qué prueba staging que desarrollo no puede

| Área | Qué se verifica en staging | Por qué no en WSL2 ni en CI |
|---|---|---|
| TLS | Certificado **comodín** por DNS-01 emitido y renovado por Traefik; redirección a HTTPS; HSTS; cookie de sesión `Secure` y `SameSite` bajo subdominios de centro reales (`ADR-014`, `ADR-025`); CSP y cabeceras con el dominio de verdad | Un comodín solo se emite por DNS-01 (**V**, documentación de Traefik) y exige dominio y DNS con API |
| Traefik real | IP pública; IP de cliente correcta en auditoría y en `admin_action_logs`; lista blanca del *backoffice*; `TRUSTED_PROXIES` restringido (`§3.7`); proxy de socket delante de Traefik (`ADR-037 §6.3`) | En local la IP de cliente es siempre interna |
| Quadlet y sistema | SELinux `enforcing` con `:Z`; `linger` y arranque tras reiniciar la máquina; las tres pruebas obligatorias de la skill `contenedores-y-red`; segunda réplica `api@2` y recreación de una en una (`ADR-037 §6.4`, `RARQ-DEP-001`); `plataforma-migrate` una vez por despliegue | WSL2 no tiene SELinux ni arranque real del sistema |
| Despliegue | Descarga desde GHCR en un host ajeno; **reversión probada** a la etiqueta anterior (`RARQ-DEP-013`); *workers* que procesan trabajos encolados por la versión anterior (`RARQ-DEP-006`); planificador (#453) | La reversión solo se probó en WSL2 |
| S3 real (`ADR-076 §2.3`, `ADR-083`) | Test de conformidad de `ADR-076 §5` contra el proveedor; TLS del *endpoint*; valor de las sumas de verificación del SDK; versionado; cifrado en servidor; Object Lock y retención si `OPEN-STO-08` lo exige; ciclo de vida; bloqueo de acceso público; aislamiento de `OPEN-STO-03` | Garage no lo implementa (según su documentación, `ADR-076 §10`) |
| Operación | Cortafuegos del host, acceso SSH solo por clave, actualizaciones del sistema, espacio en disco tras semanas | No existe en local |

**Lo que staging tampoco prueba** y no debe darse por probado: rendimiento y dimensionado (máquina mínima, datos sintéticos), copias y restauración (`OPEN-10`, `0.10d`), la reputación y entregabilidad del **dominio de producción** (staging envía desde un subdominio del dominio de staging, `§3.6`), y nada que dependa de datos reales.

### 3.4 Máquina y sistema operativo

**Tamaño propuesto: 2 vCPU, 4 GB de RAM, 40 GB de disco**, el escalón más pequeño en el que cabe con sentido la pila (PostgreSQL, Redis, una o dos réplicas de API, dos *workers*, nginx de estáticos, Traefik y, cuando exista, el servicio de PDF de `ADR-078`). **No está medido**: es una estimación a confirmar en el primer arranque; si no cabe, se sube un escalón (operación de minutos en cualquier proveedor de `§7`). El E2E no se ejecuta en el host: Playwright corre fuera y apunta a staging.

Sistema operativo:

| Opción | Podman | SELinux | Valoración |
|---|---|---|---|
| **AlmaLinux o Rocky Linux (familia RHEL)** | Versión **SV** (no se consultó el índice de paquetes) | `enforcing` de serie | **Recomendada**: es la familia del destino de `ADR-027` (RHEL 10) y la única de la tabla que prueba `:Z` de verdad |
| Debian 13 | 5.4.2 (**V**) | No (AppArmor) | Podman reciente, pero deja sin probar SELinux, que es uno de los motivos de tener staging |
| Ubuntu 24.04 LTS | 4.9.3 (**V**) | No (AppArmor) | Podman más antiguo que el de desarrollo; riesgo de que alguna clave de Quadlet usada (`Notify=healthy`, plantillas `api@`) se comporte distinto. **SV** |
| Fedora | Reciente (**SV**) | `enforcing` | Ciclo de vida de unos 13 meses: demasiado mantenimiento para un entorno desatendido |

Condición de la recomendación: que el proveedor elegido ofrezca la imagen (IONOS la anuncia, **V**; OVHcloud y Hetzner **SV**) y que su Podman admita las unidades tal como están. Se comprueba el primer día con la validación en seco de `ADR-037 §6.5` (`OPEN-STG-04`).

**Con o sin privilegios.** `ADR-037 §6.3` dice «Podman *rootless*»; `install.sh` instala por defecto como unidades de sistema (`/etc/containers/systemd`) y deja `--user` para WSL2, y `RUNBOOK.md` habla de «systemd de sistema» en producción. **Las dos cosas no casan y no se ha conciliado aquí.** Propuesta: staging se ejecuta **sin privilegios**, con usuario dedicado, `linger` e `install.sh --user`, porque es la mitigación que `ADR-037 §6.3` da por supuesta para el socket; exige resolver los puertos 80 y 443 sin privilegios. Queda como `OPEN-STG-05` y se señala en el informe como contradicción entre `ADR-037` y `RUNBOOK.md`/`install.sh` (documentación y código ajenos a este encargo).

### 3.5 TLS, dominio y DNS

- Traefik emite y renueva el comodín con su resolutor ACME por DNS-01. El proveedor de DNS necesita API. Con Cloudflare (propuesta del usuario), Traefik usa el proveedor `cloudflare` de Lego con un *token* de permisos `Zone/Zone/Read` y `Zone/DNS/Edit` (**V**). El *token* se acota a la zona de staging y vive en el `EnvironmentFile` del host, nunca en Git (`CLAUDE.md §8`).
- **Cloudflare solo como DNS, sin su proxy.** Con el proxy activo, Traefik dejaría de ver la IP del cliente y la lista blanca del *backoffice* compararía contra la IP de Cloudflare sin dar síntoma alguno (lo advierte el comentario de `traefik.container`). Poner un CDN delante es otra decisión (`RARQ-INF-004`), no de este ADR.
- Durante la puesta a punto se usa el **entorno de pruebas de Let's Encrypt** (`https://acme-staging-v02.api.letsencrypt.org/directory`, **V**; sus certificados no son de confianza para el navegador) y se pasa al de producción cuando la emisión funciona, para no tropezar con sus límites.
- `acme.json` vive en un volumen con `:Z`, permisos `0600`. Perderlo solo obliga a reemitir.
- **Qué dominio**: `OPEN-STG-02`. La recomendación es un **dominio registrable distinto del comercial**, no un subdominio suyo: una cookie emitida para el dominio padre viaja a todos sus subdominios, y un staging abierto a pruebas no debe poder leer ni fijar cookies del dominio de producción (`ADR-025` sitúa la sesión «bajo el mismo dominio raíz»).

### 3.6 Exposición, acceso y servicios auxiliares

- **Staging es público en Internet.** Corrección (revisión C2-16, comprobado en el repositorio el 2026-11-13, `develop` en `786c94e`): hoy **no hay credenciales de demostración en el repositorio**. `REQ-SEED` (`PLAN 1.15b`) no está implementado, y el centro del E2E real lo crea `apps/api/tests/Support/e2e-real-tenant.php` con contraseñas aleatorias por ejecución (`'E2e-'.Str::random(20)`). El riesgo es de futuro: que una semilla de demostración llegue con contraseñas fijas. Regla: **en staging ninguna cuenta tiene una contraseña escrita en el repositorio**; el *backoffice* mantiene su lista blanca de IP. Mientras no haya forma de preparar el centro con contraseñas generadas, staging **no se expone**: lista blanca de IP en Traefik (`OPEN-STG-09`, bloqueante).
- **Cómo se prepara el centro para el E2E real en staging** (se define en `0.10e-c`, `CS-17`). Hecho comprobado que lo condiciona: `tests/Support` **no viaja en la imagen de producción** (`apps/api/.containerignore`: «no debe viajar en la imagen de producción», `CA-PERM-133`), así que no basta con «SSH + el mismo script» sobre el contenedor desplegado. Opciones: (a) por SSH, un contenedor de un solo uso con **la misma imagen** y el guion montado en solo lectura desde el *checkout* del mismo commit, con `E2E_ALLOW_DESTRUCTIVE` solo en ese contenedor; (b) la semilla de `REQ-SEED` con contraseñas generadas para el entorno, cuando exista (`1.15b`). **Recomendación: (a) hasta que exista (b)**; las contraseñas generadas viajan por la salida del guion, nunca a un fichero del repositorio ni a los registros. Que montar el guion en el contenedor de producción sea compatible con SELinux y `:Z` es **SV** y se comprueba en `0.10e-c`.
- **Correo** (revisión C2-01): staging **no puede usar `MAIL_MAILER=log`**. `MailEnvironmentGuard` aborta el arranque con `log` o `array` fuera de `local`/`testing` (`apps/api/app/Support/Mail/MailEnvironmentGuard.php`, comprobado el 2026-11-13) y staging corre con `APP_ENV=production` (es la configuración que se ensaya). Staging usa **SMTP real al proveedor de `OPEN-079-01`** (`ADR-079 §4.2`, decidido por el usuario: Scaleway, con Brevo de reserva), en un **subdominio de envío del dominio de staging** (`OPEN-STG-02`) y dentro de la franja gratuita del proveedor (cifras **SV**, `ADR-079 §8.1`). Sirve además para probar SPF, DKIM y DMARC antes de producción (`CS-19`). El guard no se toca. **No se añade Mailpit**: no está en `compose.yaml`, sería una pieza nueva, y un buzón de pruebas accesible desde Internet es otra superficie que proteger.
- **Sentry, Uptime Kuma y similares: no, por ahora.** Ninguno hace falta para los criterios de `§6`; un monitor en el mismo host cae con él; y la observabilidad (`RARQ-CLOUD-003`, `-007`) merece su propia decisión con datos reales a la vista. Añadirlos aquí es complejidad sin beneficio proporcional (`OPEN-STG-07`).
- **Servicio de PDF (`ADR-078`, `OPEN-PDF-12`)**: entra en staging cuando exista, en la red interna de `§3.7`.
- **Pasarela de pago (`ADR-081`, en `PROPUESTA`; petición de su `§15`)**, cuando exista `REQ-FIN`: staging usa **solo las claves de prueba** del proveedor, en el gestor de secretos del entorno; nunca claves reales fuera de producción (`ADR-081 §10.2`). El proveedor necesita alcanzar **una** ruta pública de notificaciones (`ADR-081 §10.3` y `§7.4`): con la lista blanca de IP de `OPEN-STG-09` activa, esa ruta, y solo esa, queda fuera de la lista blanca en Traefik, porque su defensa es la firma del proveedor y no el origen. Requiere además salida a Internet desde `api` y `worker` hacia el proveedor, que se declara en las redes de `§3.7` al especificar `REQ-FIN`. Lo que no se puede probar sin un banco real se declara no probado, como el S3 real (`ADR-081 §10.3`).

### 3.7 Redes (concreta `ADR-028 §2`; ejecuta la decisión del usuario sobre #484)

Hoy hay una red. El usuario decidió el 2026-11-05 que `api@` solo sea alcanzable desde Traefik, y `ADR-078` pide una red sin salida para el PDF. Propuesta, a detallar en la especificación de `0.10e`:

| Red | Miembros | Salida a Internet | Para qué |
|---|---|---|---|
| `plataforma-edge` (nueva) | Traefik, `api@`, `web` | Sí | Único camino de entrada. `TRUSTED_PROXIES` pasa a ser **esta** subred, donde no hay *workers* ni servicios de datos |
| `plataforma-net` (la actual) | `api@`, *workers*, planificador, PostgreSQL, Redis | Sí (la API y los *workers* llaman al S3 y al correo) | Datos y colas |
| `plataforma-pdf` (nueva, cuando exista el servicio) | `worker` (y `worker-pdf` si `ADR-078` pasa a Q2), `pdf`. **`api@` no**: ningún PDF se genera en la petición (`ADR-078 §5.1`; revisión C2-13) | **No** (`Internal=true`) | Control principal contra SSRF de `ADR-078` |

Las cuatro reglas de `ADR-028` se aplican a **cada** red: externa, subred declarada, nunca destruida, referencias por nombre. Ninguna IP escrita a mano: no se fija la IP de Traefik para confiar solo en ella. Límite conocido: `web` comparte `plataforma-edge` y podría, en teoría, falsear la cabecera; es un nginx de estáticos sin código propio y se acepta. Docker y Podman ofrecen ambos redes internas, así que el nivel 1 de `§5` no cambia esto. Esta separación **bloquea el primer dato real, no el arranque de staging** (`OPEN-STG-08`).

### 3.8 Cómo se despliega en staging

| Opción | Ventajas | Riesgos | Revertir |
|---|---|---|---|
| **D1 · A mano por SSH: `install.sh sha-<7>`** | Es exactamente el procedimiento de producción (`RUNBOOK.md`), que es lo que se quiere ensayar; ninguna credencial del servidor en GitHub; nada nuevo que mantener | Depende de que alguien lo lance | — |
| D2 · GitHub Actions por SSH al promocionar | Automático | Pone una llave de acceso al servidor en GitHub Actions, justo lo que `ADR-037 §5.4` descartó para producción; staging y producción dejarían de desplegarse igual | Baja: borrar el flujo y la llave |
| D3 · El host se actualiza solo (`AutoUpdate=registry` de Quadlet con etiqueta móvil, **V** que la clave existe) | Sin credenciales en GitHub | Etiqueta móvil frente a la regla de versión exacta; el orden con `plataforma-migrate` no lo gobierna la actualización automática (**SV**); se despliega sin que nadie mire | Baja |

**Recomendación: D1.** La propuesta del 2026-11-05 era D2; se desaconseja porque contradice el razonamiento de `ADR-037 §5.4` y porque el valor de staging es ensayar el despliegue de producción, no tener uno distinto y más cómodo. Con una promoción por semana o menos, automatizar no ahorra nada. Revisable si la frecuencia sube (`OPEN-STG-06`).

### 3.9 Proveedor del VPS

La decisión es del usuario (`OPEN-STG-01`). Datos de `§7`. Recomendación: **OVHcloud VPS-1**, condicionada a comprobar al contratar que hay centro de datos en la UE, imagen de la familia RHEL y que no impone permanencia. Motivos: es el precio verificado más bajo para 2 vCPU y 4 GB; incluye copia diaria; `ADR-083` propone OVHcloud para el S3 (una cuenta y, llegado el caso, una sola cadena de encargo); y las páginas de Hetzner mostraban el 2026-11-12 **todos sus planes en la nube como no disponibles para contratar**. Si Hetzner vuelve a admitir altas, es equivalente y fue la propuesta inicial del usuario. Que staging y el S3 compartan proveedor **no** vale para las copias: `OPEN-10` exige un proveedor distinto del del host.

## 4 · Decisión B: rama `staging` y flujo de promoción

### 4.1 Lo ya decidido y lo que queda por decidir

El usuario decidió el 2026-11-05: ramas `develop` → `staging` → `main`; promociones con *merge commit* o *fast-forward*, **nunca *squash***. Este ADR no reabre eso. Queda por decidir **cuál de las dos formas es la norma** y cómo se ejecuta.

Objeción que debe constar (`CLAUDE.md §0`): para un solo desarrollador, una rama `staging` no es imprescindible; bastaría desplegar en el entorno de staging un commit concreto de `develop` (opción B4). La rama añade un paso y un puntero que mantener. Lo que aporta a cambio es real: una referencia estable de «qué hay en staging» mientras `develop` sigue avanzando, y un punto del que cortar la versión. Con la forma recomendada (B1) el coste de esa rama es casi nulo, así que la decisión del usuario se mantiene y se ejecuta.

### 4.2 Opciones

| Opción | Ventajas | Riesgos | Coste de revertir |
|---|---|---|---|
| **B1 · Solo avance rápido (*fast-forward*)**: `staging` y `main` son punteros que avanzan a un commit que ya está en `develop` | El commit desplegado en staging es **el mismo** que llegará a `main`, con el mismo hash: la imagen `sha-<7>` probada es la del commit que se publica; historia lineal; no hay divergencia posible ni fusiones de vuelta; el CI de ese commit ya pasó en `develop` | La interfaz de PR de GitHub no hace avance rápido (su «rebase» reescribe los commits): la promoción se hace con un `git push` sin forzar, fuera del botón de mezclar; un arreglo urgente hecho directamente en `staging` rompe el avance rápido hasta reconciliar (`§4.4`) | Nulo: pasar a B2 es empezar a mezclar con PR |
| B2 · PR con *merge commit* en cada promoción | Todo queda en la interfaz de GitHub, con su PR y su CI | Cada promoción crea un commit que no está en la rama de origen: `staging` va siempre «por delante» de `develop`, los hashes difieren entre ramas y la imagen se reconstruye por rama; basta un *squash* por descuido (es el botón por defecto del proyecto) para crear la divergencia que el usuario quiere evitar | Bajo |
| B3 · *Merge commit* hacia `staging` y avance rápido hacia `main` | Un PR visible en el primer salto | Hereda los inconvenientes de B2 en el primer salto y mezcla dos mecánicas | Bajo |
| B4 · Sin rama `staging`: se despliega en staging un `sha` de `develop` | Lo más simple | Contradice la decisión del usuario; no hay referencia de qué está en staging salvo mirar el host | — |

**Recomendación: B1**, con B2 como excepción documentada para reconciliar tras un arreglo urgente. Motivo: es la única opción en la que «lo que probé» y «lo que publico» son el mismo commit, y la que hace imposible la divergencia en vez de confiar en no pulsar el botón equivocado. El *squash* se mantiene donde está: de rama de trabajo a `develop` (`CLAUDE.md §4`, skill `espera-ci`).

### 4.3 Procedimiento de promoción (propuesta)

1. **`develop` → `staging`**. Con el CI del commit candidato en verde en `develop`: comprobar que `staging` es ancestro del candidato y ejecutar `git push origin <sha>:refs/heads/staging`, **sin `--force`**. Git rechaza por sí solo un *push* que no sea avance rápido; ese rechazo es la protección, y forzarlo está prohibido.
2. **Despliegue en staging** de `sha-<7>` (`§3.8`), y comprobaciones: las tres pruebas de `contenedores-y-red`, el E2E contra staging y lo que la entrega pida verificar allí (`ADR-076 §2.3`).
3. **`staging` → `main`** (versión): mismo avance rápido, `git push origin <sha>:refs/heads/main`, y etiqueta anotada `vX.Y.Z` sobre ese commit, que dispara la imagen de versión (`ADR-037 §5.2`).
4. Quién lo hace: la sesión orquestadora o `janitor`, **solo cuando el usuario lo pide**. Promocionar a `main` es publicar una versión; no entra en la autonomía de PR y mezclas.
5. Se puede abrir un PR `develop` → `staging` solo para dejar constancia; GitHub lo marca como mezclado cuando la rama base ya contiene sus commits. **Nunca se pulsa el botón de mezclar en un PR de promoción.**

Sin comprobar (**SV**): si existe protección de ramas en `develop` y `main` (el *token* no pudo leerla) y si, con comprobaciones obligatorias activas, GitHub acepta el *push* de un commit que ya las pasó en otra rama. Se comprueba en la primera promoción, antes de dar el flujo por bueno. El repositorio permite hoy *merge commit*, *squash* y *rebase* (**V**, API de GitHub).

### 4.4 Arreglos urgentes

- **Norma**: el arreglo va por `develop` como cualquier otro (`fix/…`, *squash*) y se promociona.
- **Excepción**, solo si `develop` contiene algo que no puede ir a staging todavía: rama `fix/…` desde `staging`, PR a `staging` con ***merge commit***, y **en la misma sesión** un PR `staging` → `develop` con *merge commit* (nunca *squash*) para que `staging` vuelva a ser ancestro de `develop`. Hasta que ese segundo PR se mezcle no hay más promociones. Se registra en `memory.md`.

### 4.5 Imágenes y CI

- **`build-images.yml` no cambia.** Con B1 el commit de `staging` ya tiene su imagen `sha-<7>` desde que entró en `develop`. La etiqueta móvil `develop` deja de usarse para desplegar. La política de retención de `ADR-037 §5.1` debe conservar los `sha-` a los que apuntan `staging` y `main`: hoy conserva «los últimos N de `develop`», así que N tiene que cubrir la distancia entre `develop` y `main` (ajuste a especificar en `0.10e`).
- **CI**: se añade `staging` a `pull_request.branches` de los flujos (para los PR de `§4.4`). No hace falta en `push`: el commit ya pasó el CI.
- **Renovate** sigue apuntando a `develop`.

### 4.6 Puesta en marcha

`main` tiene hoy un commit que `develop` no tiene (`e2c6456`); `origin/main` **no es ancestro** de `develop`, que lleva 289 commits por delante (comprobado el 2026-11-13 con `git rev-list --left-right --count origin/main...origin/develop` y `git merge-base --is-ancestor`). Antes de la primera promoción hay que reconciliar una vez: PR `main` → `develop` con *merge commit*. Después, `staging` se crea apuntando al commit de `main` y avanza. El gancho `block-protected-branch.sh` y las skills se actualizan según `§11`.

## 5 · Decisión C: runtime de contenedores, nivel 1

### 5.1 Acoplamiento real (comprobado)

La aplicación, las imágenes (`Containerfile`, formato OCI) y `compose.yaml` ya son neutros. Lo que ata a Podman:

| Dónde | Qué | Entra en el nivel 1 |
|---|---|---|
| `apps/web/scripts/e2e-real-{api,alum,prl}.sh`, `landing-shots.sh`, `lib/e2e-garage.sh`, `lib/e2e-purge.sh`, `infra/garage/init.sh`, `infra/containers/postgres/bases-test-paralelo.sh`, `apps/web/e2e/curso-rollover.spec.ts` | Invocaciones `podman exec`, `podman start`, `podman compose` (unas 18 líneas) | **Sí** |
| `apps/api/tests/Pest.php`, comentarios de `compose.yaml` y de los `Containerfile` | Mensajes y comentarios | Sí, solo el texto |
| Migración `2026_10_07_100200_…` | Un comentario | **No**: una migración ya aplicada no se edita por un comentario |
| `infra/quadlet/`, `infra/install.sh`, `infra/compose/compose.prodlike.yaml`, la validación de Quadlet en CI | Producción y su banco de pruebas | **No**: son la implementación de referencia |
| Unos 35 documentos `.md` | Ejemplos con `podman` | No se reescriben |

### 5.2 Opciones

| Opción | Ventajas | Riesgos | Coste | Revertir |
|---|---|---|---|---|
| C0 · No hacer nada | Cero coste; un solo camino, siempre probado | Un colaborador o un host sin Podman no puede ejecutar los guiones; el acoplamiento crece sin que nadie lo vea | Nulo | — |
| **C1 · Nivel 1: variable `CONTAINER_RUNTIME` en los guiones y contrato portable escrito** | Coste pequeño; frena el acoplamiento nuevo; deja dicho qué es portable y qué no | El camino con otro runtime no se prueba en ningún sitio y puede romperse sin aviso | Bajo: una entrega de un tramo | Trivial: la variable tiene valor por defecto |
| C2 · Nivel 2: segunda definición de producción (`compose.prod.yaml` para Docker) | Despliegue en hosts sin Podman | Dos definiciones de producción que divergen; una de ellas nunca se ejecuta; duplica las pruebas de `§6` | Alto y permanente | Medio |
| C3 · Manifiestos de Kubernetes ya | Portabilidad real entre proveedores | `ADR-024` lo sitúa a partir de 3-5 centros; hoy es operar un orquestador para cero usuarios | Muy alto | Alto |

**Recomendación: C1, con límites escritos.** C2 y C3 se rechazan hasta que exista un cliente o un host que lo exija: mantener dos definiciones de producción sin ejecutar una es deuda segura.

### 5.3 Qué es el nivel 1

1. **Variable `CONTAINER_RUNTIME`**, valor por defecto `podman`. Valores admitidos: `podman` y `docker`; cualquier otro aborta el guion con un mensaje (lista cerrada, para no ejecutar lo que venga en una variable de entorno). Los guiones de `§5.1` usan `"$CONTAINER_RUNTIME" exec …` y `"$CONTAINER_RUNTIME" compose …`.
2. **Contrato portable**, el único que el proyecto promete a otro runtime: imágenes OCI publicadas en el registro, variables de entorno documentadas en `SYSADMIN.md`, volúmenes con nombre, redes con nombre de `§3.7`, chequeo de salud por servicio. Quien despliegue con otro orquestador parte de ahí.
3. **Quadlet es la implementación de referencia de producción y la única que se prueba** (staging, `§6`). `ADR-027` y `ADR-037` no cambian.
4. **Podman sigue siendo el runtime por defecto y el único verificado** en desarrollo (`ADR-030` no cambia; su descarte de Docker Desktop sigue en pie). Con `docker` el soporte es **de mejor esfuerzo y sin verificar**: no hay trabajo de CI que lo ejecute, y así se dice donde se documente. Declarar «compatible con Docker» sin ejecutarlo sería una afirmación no verificada (`CLAUDE.md §0`).
5. **Regla hacia delante**: ningún guion nuevo escribe `podman` a mano; un guion que necesite algo exclusivo de Podman lo dice en su cabecera.
6. La documentación no se reescribe: una nota en `CLAUDE.md §1` (`§11`).

No añade dependencias, no toca invariantes y no cambia nada en el servidor. La red interna del PDF (`OPEN-PDF-12`) no se ve afectada: es una función común a ambos runtimes y, en producción, la define Quadlet.

## 6 · Criterios de salida de `0.10e`

### 6.1 Entregas (cada una cabe en ≤3 tramos, `CLAUDE.md §2.7`; estimación sin medir)

| Entrega | Contenido | Depende de | Clasificación |
|---|---|---|---|
| `0.10e-a` | Rama `staging`, gancho, skills, CI y reconciliación de `main` (`§4`) | `OPEN-STG-03` | Ordinario |
| `0.10e-b` | Nivel 1 de runtime (`§5`) | `OPEN-STG-11` | Ordinario |
| `0.10e-c` | Host, sistema, Quadlet sin privilegios, TLS comodín, cortafuegos, despliegue y reversión | `OPEN-STG-01`, `-02`, `-04`, `-05`, `-09` | **Crítico** (servidor expuesto, secretos, TLS) |
| `0.10e-d` | Redes de `§3.7`, proxy de socket, segunda réplica, planificador (#453) | `0.10e-c`, `OPEN-STG-08` | **Crítico** |
| `0.10e-e` | S3 real: *bucket* y claves de staging, conformidad y columna derecha de `ADR-076 §2.3` | `0.10e-c`, `ADR-083` aceptado, `OPEN-STO-01`, `-03`, `-08`, `-11`, `OPEN-083-02` | **Crítico** |

`0.10e-a` y `0.10e-b` **no necesitan VPS ni dominio** y pueden hacerse en cuanto se acepte este ADR.

### 6.2 Criterios (cada uno se ejecuta en staging y se anota con su resultado literal; ninguno se da por cumplido sin ejecutarlo)

| Id | Criterio | Entrega |
|---|---|---|
| `CS-01` | Un *push* que no sea avance rápido a `staging` o `main` es rechazado; el gancho bloquea commits directos en `staging` y cualquier `--force` | a |
| `CS-02` | Una promoción completa `develop` → `staging` → `main` con etiqueta `v0.x.y` de ensayo deja las tres ramas en la misma línea y publica la imagen de versión | a |
| `CS-03` | Los E2E reales pasan con `CONTAINER_RUNTIME` sin definir (Podman). El resultado con `docker`, si alguien lo ejecuta, se anota; si no, queda «sin verificar» | b |
| `CS-04` | El host arranca la pila sola tras reiniciar la máquina, sin sesión abierta (`linger`), con SELinux en `enforcing` y sin denegaciones en el registro de auditoría de SELinux | c |
| `CS-05` | `https://<centro>.<dominio de staging>` sirve con certificado comodín de confianza; HTTP redirige a HTTPS; HSTS presente; cookie de sesión con `Secure`, `HttpOnly` y `SameSite`; CSP sin infracciones en el recorrido del E2E | c |
| `CS-06` | La renovación del certificado funciona (forzada o esperada) sin intervención | c |
| `CS-07` | Las tres pruebas obligatorias de `contenedores-y-red` pasan en el host | c |
| `CS-08` | Despliegue de una etiqueta nueva y **reversión a la anterior** ejecutados con `install.sh`, con el tiempo medido y un trabajo encolado por la versión anterior procesado por la nueva (`RARQ-DEP-006`, `-013`) | c |
| `CS-09` | Solo los puertos 22, 80 y 443 responden desde fuera; SSH solo con clave; PostgreSQL y Redis no son alcanzables desde Internet | c |
| `CS-10` | La IP registrada en auditoría y en `admin_action_logs` es la del cliente; una petición a la API desde un contenedor de `plataforma-net` con `X-Forwarded-For` falso no cambia la IP registrada ni salta la lista blanca del *backoffice* | d |
| `CS-11` | Traefik funciona con el proxy de socket y sin acceso directo al socket (`ADR-037 §6.3`) | d |
| `CS-12` | Con `api@1` y `api@2`, recrear una de cada vez no devuelve ningún error a un cliente que hace peticiones en bucle (`RARQ-DEP-001`) | d |
| `CS-13` | El planificador ejecuta una tarea programada conocida en el host (#453) | d |
| `CS-14` | El test de conformidad de `ADR-076 §5` pasa contra el proveedor real por TLS; queda anotado el valor de las sumas de verificación que funciona | e |
| `CS-15` | Versionado, cifrado en servidor y, si `OPEN-STO-08` lo exige, Object Lock y retención: activados y comprobados con una operación real (sobrescribir y recuperar la versión anterior; cabecera de cifrado; borrado rechazado dentro del plazo) | e |
| `CS-16` | El *bucket* rechaza el acceso anónimo; la clave de staging no ve otros *buckets*; el aislamiento de `OPEN-STO-03` se comprueba tal como se decida | e |
| `CS-17` | E2E real completo contra staging, con el centro preparado según `§3.6` (contenedor de un solo uso con la misma imagen, o semilla con contraseñas generadas) y ninguna contraseña escrita en el repositorio | c y e |
| `CS-18` | `SYSADMIN.md` y `RUNBOOK.md` dicen qué está probado en staging, con fecha, y qué sigue sin probar | todas |
| `CS-19` | La API y los *workers* arrancan con `APP_ENV=production` y `MAIL_MAILER=smtp` al proveedor de `OPEN-079-01`; un correo transaccional del E2E (p. ej. restablecer contraseña) llega a un buzón de prueba con SPF, DKIM y DMARC en `pass` según sus cabeceras `Authentication-Results` (`§3.6`) | c |

Cuando existan, se añaden sin reabrir este ADR: el servicio de PDF en su red sin salida (`ADR-078`); los registros SPF, DKIM y DMARC de los subdominios de envío en el dominio de staging y el Web Push, que exige TLS real (`ADR-079`).

**`0.10e` se cierra con `CS-01` a `CS-19` ejecutados.** Un criterio que no se pueda ejecutar se deja escrito como no verificado y el paso queda `[~]`, no `[x]`.

## 7 · Coste mensual

Precios **sin IVA**, leídos en la página del proveedor el 2026-11-12 (`§12`). Cambian: se vuelven a mirar al contratar.

| Concepto | Opción | Precio | Estado |
|---|---|---|---|
| VPS 2 vCPU, 4 GB | OVHcloud VPS-1 (40 GB NVMe, copia diaria incluida) | 3,81 €/mes | **V** precio el 2026-11-12; **SV** centros de datos, imágenes y permanencia. El revisor C2 (2026-11-13) avisa de que la ficha 2 vCPU/4 GB/40 GB puede no coincidir con la gama real: **volver a comprobar al contratar** |
| | Hetzner CX23 (40 GB NVMe, 20 TB, IPv4 incluida) | 5,99 €/mes | **V** precio; la página lo mostraba **no disponible para contratar**, igual que toda la gama CX, CAX y CPX; **SV** en su consola |
| | IONOS VPS M+ (120 GB NVMe; centro de datos en España; AlmaLinux y Rocky) | 4 €/mes tres meses, después 12 €/mes; alta de 10 € | **V**; el precio posterior a la promoción se deduce del ahorro anunciado |
| | Scaleway | — | **SV**: su web rechazó la consulta automática |
| S3 real | *Bucket* de staging en el proveedor de `ADR-083` | Céntimos: con el orden de magnitud de `ADR-083` (unos 14,6 €/TB al mes, pendiente de su petición P-12), 10 GB no llegan a 0,20 €/mes | Derivado de `ADR-083`, **SV** aquí |
| Dominio | Uno registrable para staging | **SV** (depende del TLD; no se da cifra de memoria) | `OPEN-STG-02` |
| DNS con API | Cloudflare, plan gratuito | 0 € según la propuesta del 2026-11-05 | **SV** el plan; **V** que Lego admite un *token* acotado |
| Certificados | Let's Encrypt | 0 € | **V** |
| Registro de imágenes | GHCR | 0 €: «gratis para paquetes públicos» y «el almacenamiento y el ancho de banda del registro de contenedores son actualmente gratuitos» | **V** el texto; **SV** que los paquetes del proyecto sean públicos |
| Sentry, monitor, buzón de pruebas | — | 0 €: no se añaden (`§3.6`) | — |

**Total previsto: entre 4 y 7 € al mes sin IVA, más el dominio**, con las dos primeras opciones de VPS. Techo propuesto: **15 € al mes**; por encima se vuelve a decidir (`OPEN-STG-12`). El coste que no aparece en la tabla es el tiempo: un servidor más que parchear, aproximadamente una revisión mensual.

## 8 · Consecuencias, riesgos y reversión

**Consecuencias**

- Lo «escrito y no ejecutado» de `ADR-037` y la columna derecha de `ADR-076 §2.3` pasan a tener dónde verificarse, con criterios numerados.
- Hay una tercera rama permanente. `CLAUDE.md §4`, el gancho y dos skills cambian (`§11`).
- El proyecto declara por escrito qué promete a otro runtime y qué no.
- `RARQ-CLOUD-005` y `RARQ-DEP-012` siguen sin cumplirse del todo, y queda dicho.

**Riesgos**

| Riesgo | Mitigación |
|---|---|
| Un servidor público desatendido con credenciales conocidas (hoy no las hay en el repositorio; riesgo si una semilla futura las fija, `§3.6`) | `§3.6`, `CS-09`, `OPEN-STG-09`; solo datos sintéticos; se destruye sin pérdida |
| Que alguien cargue datos reales «para probar» | Regla 1 de `§3.2`, sin excepción; se repite en `SYSADMIN.md` y `CLAUDE.md §11` |
| Dar por probado en staging lo que no se ejecutó | `§6.2`: resultado literal por criterio; `CS-18` |
| Staging mínimo que oculta problemas de dimensionado | No se usa para medir rendimiento (`§3.3`) |
| Un *squash* o un `--force` en una promoción | B1 no pasa por el botón de mezclar; gancho; `CS-01` |
| El camino con `docker` se rompe sin que nadie lo vea | Se declara de mejor esfuerzo (`§5.3`); no se promete |
| Subidas de precio o falta de disponibilidad del proveedor (en 2026 ha ocurrido, según las páginas consultadas) | Nada del producto depende del proveedor; cambiar es reinstalar con `install.sh` |
| `ADR-083` se acepta con otro proveedor o tarda | `0.10e-e` espera; `a` a `d` no dependen de él |

**Reversión**

- Staging: cancelar el VPS y borrar la zona DNS, el *bucket* y sus claves. Ningún otro entorno lo nota.
- Rama `staging`: volver a dos ramas es dejar de promocionar a `staging`, restaurar `CLAUDE.md §4` y el gancho, y borrar la rama con un ADR que sustituya `§4` (borrar ramas permanentes no se hace sin él).
- Nivel 1: la variable tiene valor por defecto; ignorarla devuelve al estado actual.

## 9 · Alternativas descartadas

- **PaaS para staging** (A5): descartado por el usuario; no valida lo que se quiere validar.
- **Garage o SeaweedFS en staging** (propuesta del 2026-11-05): sustituida por `ADR-076 §2.2` (Garage nunca en un servidor) y `ADR-083 §2` punto 4 (staging y producción, mismo proveedor y clase).
- **Despliegue por GitHub Actions con SSH** (D2) y **actualización automática** (D3): `§3.8`.
- **Cloudflare como proxy o CDN delante de Traefik**: `§3.5`.
- **Mailpit, Sentry, Uptime Kuma** en esta entrega: `§3.6`.
- **Fijar la IP de Traefik** para confiar solo en ella: prohibido por `ADR-028 §4`.
- **Staging como subdominio del dominio comercial**: no se descarta del todo; se desaconseja por las cookies (`§3.5`, `OPEN-STG-02`).
- **Terraform u otra herramienta de infraestructura como código** para un solo VPS: `RARQ-CLOUD-002` la pide, pero con un host es más herramienta que problema, por el mismo argumento con que `ADR-037 §5.4` rechazó Ansible. Se revisa con el segundo host. Queda como incumplimiento conocido de `RARQ-CLOUD-002`, no como olvido.
- **Segunda definición de producción y Kubernetes ya** (C2, C3): `§5.2`.
- **Reescribir los documentos que mencionan Podman** y **editar la migración** por un comentario: `§5.1`.

## 10 · Preguntas abiertas y lo no verificado

**Decidida por el usuario (2026-11-12)**: `OPEN-STG-01`, según la recomendación de este ADR: VPS de staging en OVHcloud (VPS-1, 2 vCPU y 4 GB; precio y suficiencia de 4 GB sin comprobar contra un alta real). Techo de coste propuesto: 15 €/mes. Siguen abiertas `OPEN-STG-02`, `-03`, `-04`, `-05` y `-09`, que también bloquean. El ADR continúa en `PROPUESTA`.

**Decidida también el 2026-11-12**: `OPEN-STG-03`, según la recomendación (B1 de `§4.2`): la promoción a `staging` es **solo por avance rápido** (`git push <sha>:staging`), sin botón de mezclar. Quedan abiertas `OPEN-STG-02`, `-04`, `-05` y `-09`.

**Decididas por el usuario (2026-11-12), según la recomendación**: `OPEN-STG-02` (dominio de staging: un dominio registrable aparte, no subdominio del comercial, con Cloudflare solo como DNS y sin proxy). **Decididas por el usuario el 2026-11-14**: `OPEN-STG-04` (AlmaLinux o Rocky, la versión que ofrezca el proveedor y valide las unidades en seco; el usuario prefiere la misma familia en producción), `OPEN-STG-05` (Podman **sin privilegios** con `linger` e `install.sh --user`; hay que corregir la documentación que diga otra cosa y resolver los puertos 80 y 443) y `OPEN-STG-09` (**lista blanca de IP en Traefik**; staging no se expone sin ella). El ADR sigue en `PROPUESTA` hasta que el usuario lo acepte.

### 10.1 Bloquean la implementación

| Id | Pregunta | Opciones | **Recomendada** | Bloquea | Decide |
|---|---|---|---|---|---|
| `OPEN-STG-01` | Proveedor y plan del VPS | OVHcloud VPS-1; Hetzner CX23; IONOS VPS M+; otro europeo | **OVHcloud VPS-1**, tras comprobar región UE, imagen de la familia RHEL y ausencia de permanencia (`§3.9`). Impacto: unos 4 €/mes; misma cuenta que el S3 propuesto | `0.10e-c` | Usuario |
| `OPEN-STG-02` | Dominio de staging y proveedor de DNS | (a) dominio registrable aparte; (b) subdominio del dominio comercial, que exige cerrar antes `OPEN-08`; (c) sin dominio propio: inviable, no hay comodín sin DNS-01. DNS: Cloudflare solo DNS, o el DNS del registrador si tiene API admitida por Lego (**SV**) | **(a) con Cloudflare solo como DNS** y *token* acotado a la zona. Impacto: desacopla staging de `OPEN-08` y aísla las cookies; un dominio más que pagar | `0.10e-c` | Usuario |
| `OPEN-STG-03` | Forma de la promoción | B1 solo avance rápido; B2 PR con *merge commit*; B3 mixto | **B1** (`§4.2`). Impacto: las promociones no pasan por el botón de mezclar | `0.10e-a` | Usuario |
| `OPEN-STG-04` | Sistema operativo del host | AlmaLinux o Rocky; Debian 13; Ubuntu 24.04; Fedora | **AlmaLinux o Rocky**, la versión que ofrezca el proveedor con Podman que valide las unidades en seco (`§3.4`) | `0.10e-c` | Técnico; lo confirma el usuario |
| `OPEN-STG-05` | Podman sin privilegios o con ellos en el servidor | Sin privilegios con `linger` (`ADR-037 §6.3`); unidades de sistema (lo que hoy dicen `install.sh` y `RUNBOOK.md`); de sistema con `User=` | **Sin privilegios**, y corregir la documentación que diga otra cosa. Impacto: hay que resolver los puertos 80 y 443 | `0.10e-c` | Técnico; lo confirma el usuario |
| `OPEN-STG-09` | Cómo se protege un staging público frente a credenciales conocidas (hoy no hay ninguna en el repositorio, `§3.6`) | Contraseñas de semilla generadas por entorno; lista blanca de IP en Traefik para todo staging; autenticación básica delante; VPN | **Contraseñas generadas por entorno** si `REQ-SEED` lo admite; mientras no, **lista blanca de IP**. Una VPN es desproporcionada. La especificación de `REQ-SEED` (rama del PR #646, `funcional.md §13.2`, releída el 2026-11-13) **no la resuelve**: la deja abierta como `OPEN-SEED-05` (recomienda `staging` en la lista de entornos solo al aceptar este ADR, con contraseña obligatoria del gestor de secretos y sin cuenta compartida publicada), que decide el usuario | Exponer staging (`0.10e-c`) | Usuario, con el técnico |
| `OPEN-STO-01`, `-03`, `-08`, `-11`, `OPEN-083-02` | Proveedor S3, aislamiento, versionado y retención, cifrado, cuenta de prueba | En `ADR-083` y `docs/cumplimiento/` (solo en la rama de `ADR-083`; **dependencia de mezcla: este ADR se mezcla con `ADR-083`, PR #642, o después**) | Las de `ADR-083`; este ADR no las decide | `0.10e-e` | Los que allí se indican |

### 10.2 No bloquean el arranque

| Id | Pregunta | **Recomendada** | Impacto | Decide |
|---|---|---|---|---|
| `OPEN-STG-06` | Mecanismo de despliegue en staging | **D1, a mano con `install.sh`** | Revisable si se promociona más de una vez por semana | Usuario |
| `OPEN-STG-07` | Sentry, monitor de salud y buzón de pruebas en staging | **No por ahora**; se decide con la observabilidad (`RARQ-CLOUD-003`, `-007`) y con `ADR-079` | Staging sin alertas: si cae, se ve al usarlo | Usuario |
| `OPEN-STG-08` | Modelo de tres redes de `§3.7` | **Aceptarlo** como concreción de `ADR-028` y de la decisión sobre #484 | **Bloquea el primer dato real**, no staging | Usuario |
| `OPEN-STG-10` | `RARQ-CLOUD-005` pide cuatro entornos y `RARQ-DEP-012` un ensayo con datos anonimizados | **Aceptar por escrito que hoy hay dos entornos** y que preproducción se decide con `OPEN-11`; no fabricar datos «anonimizados» a partir de reales en staging | Requisitos incumplidos de forma declarada hasta que haya producción | Usuario |
| `OPEN-STG-11` | Nivel 1 de runtime y si `docker` se verifica en CI | **Nivel 1 sin trabajo de CI para `docker`** (mejor esfuerzo, declarado) | Bloquea solo `0.10e-b` | Usuario |
| `OPEN-STG-12` | Staging permanente o destruido entre ensayos, y techo de gasto | **Permanente, techo de 15 €/mes** | Unos 50 a 85 € al año | Usuario |
| `OPEN-STG-13` | ¿Se promociona la imagen ya construida a versión (misma imagen, etiqueta nueva) en vez de reconstruirla al etiquetar `v*`? | **No ahora**: cambiaría `ADR-037 §5.2`; anotarlo para la primera versión real | Hoy la imagen de versión se reconstruye del mismo commit, no es idéntica byte a byte a la probada | Técnico |

### 10.3 No verificado en esta sesión

- **Nada se ha ejecutado**: ni un VPS, ni un certificado, ni un *push* a `staging`, ni un guion con `CONTAINER_RUNTIME`. Todo `§6` son criterios por cumplir.
- Disponibilidad real de alta en Hetzner (solo se leyó la página pública) y en OVHcloud; centros de datos, imágenes de sistema y permanencia de OVHcloud; precios de Scaleway; precio del dominio; condiciones del plan gratuito de Cloudflare.
- Versión de Podman en AlmaLinux, Rocky y Fedora; desde qué versión existen `Notify=healthy` y las plantillas (la documentación actual las describe, **V**, sin decir versión); si las unidades actuales validan con Podman 4.9.3.
- Protección de ramas del repositorio y comportamiento del *push* de avance rápido con comprobaciones obligatorias; visibilidad de los paquetes de GHCR.
- Si 4 GB bastan para la pila con la semilla de 300 alumnos.
- Si `REQ-SEED` permite fijar contraseñas por entorno y si los guiones de E2E real admiten apuntar a un host remoto.
- Que `Internal=true` de Quadlet y la red interna de Docker se comporten igual para el servicio de PDF.
- El recuento de `§5.1` (unas 18 invocaciones) sale de `grep -c -i podman` por fichero e incluye algún comentario.
- `ADR-078`, `ADR-079` y `ADR-083` se leyeron en borrador, en lo que afecta a staging; pueden cambiar tras su revisión.

### 10.4 Hallazgos fuera del ámbito de este encargo (no se corrigen aquí)

1. `ADR-037 §6.3` dice Podman sin privilegios; `infra/install.sh` instala por defecto unidades de sistema y `RUNBOOK.md` habla de «systemd de sistema» en producción (`OPEN-STG-05`). Contradicción entre código, documento y ADR: Media (`CLAUDE.md §6.6`).
2. `ADR-037 §5.1` razona la cuota de GHCR con repositorio privado; el repositorio es público.
3. `PLAN-IMPLEMENTACION.md`, paso `0.10e`, sigue diciendo «S3 de pruebas (Garage o SeaweedFS)», contrario a `ADR-076 §2.2`.
4. `SYSADMIN.md`: `TRUSTED_PROXIES` sigue pendiente de añadirse a los `.env.example`.

## 11 · Cambios propuestos en ficheros compartidos

Los aplica la sesión orquestadora al integrar, y solo los que correspondan a lo que el usuario acepte.

| Fichero | Cambio |
|---|---|
| `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md §18` | Fila `ADR-080` en el índice (estado `PROPUESTA`). En «Decisiones abiertas vivas»: `OPEN-11` añade «staging no la cierra: solo datos sintéticos (`ADR-080 §3.2`)»; `OPEN-08` añade «el dominio de staging se decide aparte (`OPEN-STG-02`)». Historial de versiones |
| `PLAN-IMPLEMENTACION.md` | `0.10e` se parte en `0.10e-a` a `0.10e-e` con el contenido, las dependencias y la clasificación de `§6.1`, y deja de depender de `0.10` (`OPEN-11`); se retira «Garage o SeaweedFS»; `a` y `b` en [SONNET], `c` a `e` críticos. Nota: el cierre exige `CS-01` a `CS-18` |
| `CLAUDE.md §1` | Fila «Contenedores», **mientras no exista**: añadir «`CONTAINER_RUNTIME` en los guiones: **elegido y no implementado** (`0.10e-a`, `ADR-080 §5`). Quadlet es la referencia de producción y lo único que se prueba». Comprobado el 2026-11-13: ningún guion del repositorio lee `CONTAINER_RUNTIME` (solo lo citan `memory.md` y `docs/proceso/`). **Al cerrar `0.10e-a`**, sustituir por «Los guiones aceptan `CONTAINER_RUNTIME` (por defecto `podman`; `docker` de mejor esfuerzo, sin verificar)» (regla de `CLAUDE.md §1`: la tabla dice qué está elegido, no qué está instalado) |
| `CLAUDE.md §4` | Ramas permanentes: `main`, **`staging`** y `develop`. «`staging` y `main` solo avanzan por promoción de avance rápido, sin *squash*, sin `--force` y sin el botón de mezclar (`ADR-080 §4.3`); `main` solo recibe lo que ya está en `staging`; nunca se commitea en ninguna de las tres». Excepción de arreglo urgente de `§4.4`. Promocionar a `main` solo a petición del usuario |
| `CLAUDE.md §9` | Añadir: «Staging: solo datos sintéticos; mismo procedimiento que producción; se puede destruir (`ADR-080 §3.2`)» |
| `CLAUDE.md §11` | La prohibición de datos reales en desarrollo nombra también staging |
| `.claude/hooks/block-protected-branch.sh` | Tratar `staging` como rama protegida para commits; permitir el *push* a `staging` y `main` únicamente con la forma `<sha>:refs/heads/<rama>` y sin `--force`, `-f` ni `+` en la referencia |
| `.claude/skills/espera-ci`, `pr-check` | `espera-ci`: «no para promociones (`develop` → `staging` → `main`)»; nunca *squash* sobre un PR cuya base sea `staging` o `main`. `pr-check`: la base de un PR de trabajo es `develop`; a `staging` solo el arreglo urgente de `§4.4` |
| `.claude/skills/contenedores-y-red` | Regla 2 en plural (las redes de `§3.7`, las mismas reglas para cada una); ejemplos con el runtime por defecto y mención de la variable |
| `SYSADMIN.md` | Sección de staging: proveedor, sistema, usuario sin privilegios, variables propias, DNS y *token*, tabla «probado en staging / sin probar» con fecha (`CS-18`); entornos que existen de verdad (`OPEN-STG-10`); `CONTAINER_RUNTIME` en la tabla de variables |
| `RUNBOOK.md` | Procedimiento de promoción (`§4.3`), arreglo urgente (`§4.4`), despliegue y reversión en staging con el tiempo medido (`CS-08`), destrucción y recreación del entorno |
| `ARCHITECTURE.md` | Línea 71 («entornos dev, staging, prod»): reflejar que staging se despliega con Quadlet, no con `compose`; diagrama de redes de `§3.7`; nota en la decisión de hospedaje sobre staging y `OPEN-11` |
| `README.md`, `CHANGELOG.md` | Tabla de versiones y entrada de la ola |
| `memory.md` | Decisiones pendientes del usuario (`§10.1`), hallazgos de `§10.4` y la nota de que `0.10e-a` y `-b` no esperan al VPS |
| `.github/workflows/ci-*.yml`, `dependency-scan.yml` (código, lo hace `implementer` en `0.10e-a`) | `staging` en `pull_request.branches` |
| Issues a abrir (`CLAUDE.md §5`) | Los hallazgos 1 (Media) y 2 a 4 (Bajas) de `§10.4` |

## 12 · Fuentes

Consultadas el **2026-11-12**.

| Dato | Fuente |
|---|---|
| Precios y disponibilidad de Hetzner (CX, CAX, CPX) | `https://www.hetzner.com/cloud/cost-optimized`, `https://www.hetzner.com/cloud/regular-performance` |
| Precios de OVHcloud VPS | `https://www.ovhcloud.com/es-es/vps/` |
| Precios, centros de datos e imágenes de IONOS VPS | `https://www.ionos.es/servidores/vps` |
| Podman en Debian 13 y Ubuntu 24.04 | `https://packages.debian.org/trixie/podman`, `https://packages.ubuntu.com/noble/podman` |
| Claves de Quadlet (`Notify=healthy`, plantillas, `AutoUpdate=`, rutas sin privilegios) | `https://docs.podman.io/en/latest/markdown/podman-systemd.unit.5.html` |
| Comodín solo por DNS-01; almacenamiento `acme.json` | `https://doc.traefik.io/traefik/reference/install-configuration/tls/certificate-resolvers/acme/` |
| Proveedor `cloudflare` de Lego y permisos del *token* | `https://go-acme.github.io/lego/dns/cloudflare/` |
| Entorno de pruebas de Let's Encrypt | `https://letsencrypt.org/docs/staging-environment/` |
| GHCR: paquetes públicos y registro de contenedores | `https://docs.github.com/en/billing/concepts/product-billing/github-packages` |
| Métodos de mezcla, historia lineal y efecto del *squash* entre ramas de larga vida | `https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/configuring-pull-request-merges/about-merge-methods-on-github` |
| Estado del repositorio | `gh repo view`, API de GitHub (métodos de mezcla), `git` sobre el clon local en `b7995bc` |
| Sin poder leer | Scaleway (`403` a la consulta automática); la página de ajuste de precios de Hetzner (`404`) |
| Correcciones de la revisión C2 (consultado el **2026-11-13**, `develop` en `786c94e`) | `apps/api/app/Support/Mail/MailEnvironmentGuard.php`; `apps/api/tests/Support/e2e-real-tenant.php` (contraseñas `Str::random`); `apps/api/.containerignore` (excluye `tests/Support`); `apps/web/scripts/e2e-real-api.sh`; `PLAN-IMPLEMENTACION.md` (`1.15b` sin hacer); `git rev-list`/`merge-base` sobre `origin/main` y `origin/develop` |

Fuentes internas: memorias del usuario del 2026-11-05 sobre staging y runtime; `PLAN-IMPLEMENTACION.md` `0.10e`; `ADR-027`, `ADR-028`, `ADR-030`, `ADR-037`, `ADR-076`; borradores de `ADR-078`, `ADR-079` y `ADR-083`; skill `contenedores-y-red`; issues #453 y #484.
