# ADR-080 · Entorno de staging barato, rama `staging` con promoción sin *squash* y nivel 1 de runtime de contenedores agnóstico

**Estado**: **PROPUESTA** (2026-11-12). La acepta el usuario; hasta entonces no se implementa nada de `0.10e`.
**Fecha**: 2026-11-12
**Origen**: decisiones del usuario del 2026-11-05 (staging barato con dominio propio; flujo `develop` → `staging` → `main` sin *squash*; separación de redes de #484; proyecto agnóstico al runtime de contenedores, aplazado hasta tratar staging) y `PLAN-IMPLEMENTACION.md`, paso `0.10e`.
**Afecta a**: `PLAN-IMPLEMENTACION.md` (`0.10e`), `CLAUDE.md §1`, `§4` y `§9`, `infra/quadlet/`, `infra/install.sh`, `.github/workflows/`, `.claude/hooks/block-protected-branch.sh`, skills `espera-ci`, `pr-check` y `contenedores-y-red`, `SYSADMIN.md`, `RUNBOOK.md`, los guiones de `apps/web/scripts/` e `infra/` que invocan `podman`. Este ADR **no toca ninguno**: los cambios se proponen en `§11`.
**Concreta sin sustituir**: `ADR-028` (sus reglas se aplican a cada red de `§3.7`), `ADR-030` (staging hereda «solo datos sintéticos»; Podman sigue siendo el runtime por defecto), `ADR-037` (`§6.3`, `§6.4`, `§6.5` punto 3 y la tabla de `§8`: este ADR dice dónde y cuándo se verifican), `ADR-076 §2.3` (columna «Staging contra el proveedor real»).
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
4. **Mismo procedimiento que producción**: unidades Quadlet de `infra/quadlet/`, `install.sh <tag>` con etiqueta exacta `sha-<7>`, `EnvironmentFile` propio, descarga desde GHCR. Lo que difiera se escribe en `SYSADMIN.md` como diferencia conocida.
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

**Lo que staging tampoco prueba** y no debe darse por probado: rendimiento y dimensionado (máquina mínima, datos sintéticos), copias y restauración (`OPEN-10`, `0.10d`), entrega real de correo (`OPEN-09`), y nada que dependa de datos reales.

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

- **Staging es público en Internet y las credenciales de demostración de `REQ-SEED` están en un repositorio público.** Sin más medidas, cualquiera entraría, subiría ficheros al *bucket* y usaría el entorno. Medida mínima propuesta: en staging la semilla se ejecuta con **contraseñas generadas en el momento**, no las de por defecto, y el *backoffice* mantiene su lista blanca de IP. No se ha comprobado que `REQ-SEED` permita hoy fijar las contraseñas (**SV**; B4 lo especifica en esta misma ola). Si no lo permite, staging **no se expone** hasta que lo haga: se limita por lista blanca de IP en Traefik (`OPEN-STG-09`, bloqueante).
- **Correo**: `MAIL_MAILER=log` hasta que `ADR-079` decida. **No se añade Mailpit**: no está en `compose.yaml`, sería una pieza nueva, y un buzón de pruebas accesible desde Internet es otra superficie que proteger.
- **Sentry, Uptime Kuma y similares: no, por ahora.** Ninguno hace falta para los criterios de `§6`; un monitor en el mismo host cae con él; y la observabilidad (`RARQ-CLOUD-003`, `-007`) merece su propia decisión con datos reales a la vista. Añadirlos aquí es complejidad sin beneficio proporcional (`OPEN-STG-07`).
- **Servicio de PDF (`ADR-078`, `OPEN-PDF-12`)**: entra en staging cuando exista, en la red interna de `§3.7`.

### 3.7 Redes (concreta `ADR-028 §2`; ejecuta la decisión del usuario sobre #484)

Hoy hay una red. El usuario decidió el 2026-11-05 que `api@` solo sea alcanzable desde Traefik, y `ADR-078` pide una red sin salida para el PDF. Propuesta, a detallar en la especificación de `0.10e`:

| Red | Miembros | Salida a Internet | Para qué |
|---|---|---|---|
| `plataforma-edge` (nueva) | Traefik, `api@`, `web` | Sí | Único camino de entrada. `TRUSTED_PROXIES` pasa a ser **esta** subred, donde no hay *workers* ni servicios de datos |
| `plataforma-net` (la actual) | `api@`, *workers*, planificador, PostgreSQL, Redis | Sí (la API y los *workers* llaman al S3 y al correo) | Datos y colas |
| `plataforma-pdf` (nueva, cuando exista el servicio) | `api@`, *workers*, `pdf` | **No** (`Internal=true`) | Control principal contra SSRF de `ADR-078` |

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
