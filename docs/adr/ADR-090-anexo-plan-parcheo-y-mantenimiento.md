# Anexo de ADR-090 · Plan de parcheo y mantenimiento

**Estado**: **PROPUESTA** (2026-11-17), con `ADR-090`. Nada de este plan está implementado. Pendiente de `OPEN-090-01` a `OPEN-090-12` (`§6`).
**Ubicación**: el encargo lo pedía en `docs/proceso/plan-parcheo-y-mantenimiento.md`; el ámbito de escritura de `architect` es `docs/adr/`. Si la sesión lo mueve, debe actualizar los enlaces de `ADR-090` y de la sección 18 del documento de requisitos.
**Convención de marcas**: **(E)** cifra de estimación, sin medir; **(NV)** no verificado hoy, se comprueba en la entrega indicada antes de apoyarse en ello; **(V)** verificado el 2026-11-17 en el repositorio o por el usuario.
**Copias de seguridad**: aparcadas por decisión del usuario. `§4.8` dice qué reversión existe **sin** copias y dónde dependería de ellas.

---

## 1 · Política de gestión de vulnerabilidades y versiones

### 1.1 Ámbitos y fuente de detección

| Ámbito | Qué incluye | Detección hoy | Detección propuesta | Corrección |
|---|---|---|---|---|
| Dependencias PHP | `composer.lock` | Renovate (semanal + alertas a cualquier hora), Dependabot alertas, Trivy `fs` en PR **(V)** | Igual + Trivy `fs` también en el escaneo programado | PR de Renovate/Dependabot |
| Dependencias JS | `apps/web/package-lock.json` (y `apps/landing` si tiene *lockfile*) | Igual **(V)** | Igual | Igual |
| Imágenes propias | `plataforma-api`, `plataforma-web` en GHCR | Nada en CI; escaneo manual del 2026-11-17 **(V)** | Trivy de imagen en `build-images.yml` + escaneo programado de lo desplegado (`§2.1`, `§2.2`) | Reconstruir por PR de *digest* (`§2.5`) |
| Imágenes base | `dunglas/frankenphp`, `node` (solo construcción), `nginx` | Nada | Renovate por *digest* (`§2.5`) + escaneo de la imagen propia | PR de Renovate |
| Imágenes de terceros desplegadas | `postgres`, `redis`, Traefik, Mailpit; Garage solo en desarrollo y CI | Renovate solo para Garage y `compose.yaml` **(V)** | Renovate por *digest* en Quadlet, `install-lib.sh` y `compose*.yaml` + escaneo programado | PR de Renovate y despliegue en ventana |
| Intérprete PHP | PHP embebido en `frankenphp` | Ninguna (Trivy no lo ve) **(V)** | Comprobación de `PHP_VERSION` contra php.net + avisos oficiales (`§2.3`) | Nueva imagen de FrankenPHP (vía *digest*) |
| Node | Solo en construcción de la web; no viaja en la imagen de producción (`nginx:1-alpine`) **(V)** | Renovate (`node:26-bookworm-slim`) | *Digest* | PR de Renovate |
| Acciones de CI | `.github/workflows/*.yml` | Renovate (`github-actions` de `config:recommended`), Dependabot alertas | Igual; fijar por SHA es `OPEN-090-10` | PR de Renovate |
| Host | AlmaLinux 10.2, paquetes RPM | Ninguna | `dnf-automatic` (seguridad) + ventana (`§3`) | `dnf` por el usuario |
| Podman y pila de contenedores del host | `podman`, `crun`, `conmon`, `netavark`, `aardvark-dns`, `passt`, `container-selinux` | Ninguna | Ventana (`§3.2`) | Usuario, en ventana |
| Núcleo | `kernel` del VPS | Ninguna | `dnf-automatic` lo instala; `needs-restarting -r` decide el reinicio en ventana (`§3`) | Reinicio en ventana |

### 1.2 Severidad y plazos objetivo

La severidad de partida es la de la fuente (Trivy/GHSA/errata de AlmaLinux). Se **sube** a Crítica si el fallo es explotable a distancia en un componente expuesto (Traefik, `nginx`, PHP, `frankenphp`, OpenSSH) o si hay explotación activa conocida; se **baja** a «no aplica» solo con motivo escrito (p. ej. `linux-libc-dev`: cabeceras del núcleo que no se ejecutan en el contenedor). Todo «no aplica» lleva *issue* (`§1.4`).

| Severidad | Detección máx. | Corregido en staging | Corregido en producción (cuando exista) | Revisión si no hay arreglo |
|---|---|---|---|---|
| Crítica | 24 h (escaneo diario) | 3 días naturales **(E)** | 7 días naturales **(E)** | cada 7 días; caducidad máx. 14 días **(E)** |
| Alta | 24 h | siguiente ventana semanal | 30 días **(E)** | cada 30 días; caducidad máx. 30 días **(E)** |
| Media | 7 días | ventana mensual o la siguiente reconstrucción | 90 días **(E)** | caducidad máx. 90 días **(E)** |
| Baja | 7 días | en la reconstrucción periódica | sin plazo propio | sin *issue* salvo «no aplica» |

`RSEC-PENT-004` exige críticas y altas remediadas antes del *go-live*: antes del primer dato real no puede quedar ninguna Crítica ni Alta **con arreglo** sin aplicar, y las que no tengan arreglo necesitan aceptación escrita del usuario.

### 1.3 Circuito y criterios de promoción

1. **Desarrollo (WSL2).** El cambio (PR de Renovate o manual) se construye y se prueba: ficheros afectados, suite completa antes del PR y E2E real contra la API local cuando el cambio toca la imagen de la API, de la web o la base (`CLAUDE.md §3`).
2. **PR a `develop`.** CI en verde (suite, linters, Trivy `fs` y Trivy de imagen sin CRITICAL con arreglo nuevos). Revisores según `CLAUDE.md §2.3`.
3. **`develop` → `staging`.** Promoción por avance rápido (`ADR-080 §4.3`) y despliegue en la **ventana** (`§4`). Criterio: lista previa completa, humo en verde, lista posterior completa. Desde que exista `CS-17`, además E2E real contra staging.
4. **`staging` → `main` → producción** (futuro). Criterio propuesto: la misma etiqueta lleva **al menos 48 h en staging (E)** sin incidencias (para un parche Crítico, 24 h **(E)**), humo y E2E de staging en verde, y orden del usuario (`CLAUDE.md §4`).

Un cambio de versión **mayor** de un componente de datos (PostgreSQL, Redis con cambio de formato) no entra por este circuito: lleva ADR propio, como `ADR-061`.

### 1.4 Seguimiento de lo que no tiene arreglo

1. **Un *issue* por hallazgo aceptado** (o por paquete, si un mismo paquete arrastra varios CVE con el mismo motivo), etiqueta `vuln-seguimiento` más la de severidad, con: identificador(es), imagen o *lockfile*, motivo de aceptación, **fecha de revisión** y **fecha de caducidad**.
2. **Una entrada en el fichero de exclusiones de Trivy con la misma caducidad** y el número del *issue* en el comentario. Trivy deja de ignorar la entrada al caducar y el escaneo vuelve a marcarla: es el mecanismo que impide olvidarlo, sin depender de que alguien mire un tablero. #735 introduce ya la caducidad en `.trivyignore`; la sintaxis exacta (`exp:` en `.trivyignore` o `expired_at` en `.trivyignore.yaml`) se comprueba en E1 **(NV)**.
3. **Lo que Trivy no ve** (PHP embebido, host) se sigue igual con *issue* y fecha de revisión; el recordatorio es la lista previa de cada ventana (`§4.3`, punto 9), que lista los *issues* `vuln-seguimiento` con revisión vencida (`gh issue list --label vuln-seguimiento`).
4. Los hallazgos **con** arreglo no llevan *issue*: los recoge *code scanning* (SARIF) y se cierran solos cuando el escaneo deja de verlos.

---

## 2 · Contenedores

### 2.1 Trivy de imagen en el CI de construcción

- En `build-images.yml`, tras construir `plataforma-api` y `plataforma-web` (también en PR, donde hoy se construye sin publicar **(V)**), Trivy en modo `image` sobre la imagen recién construida (cargada en el *runner*, sin publicar).
- **Falla** solo por: `CRITICAL`, con arreglo disponible (`ignore-unfixed`), tipos `os` y `library`, excluido `linux-libc-dev` por motivo escrito (`§2.4`).
- **Informa** del resto (`HIGH` y `CRITICAL` sin arreglo) en SARIF subido a *code scanning* con una categoría por imagen (`github/codeql-action/upload-sarif`; requiere `security-events: write` en ese job solamente).
- Trivy analiza también los binarios Go (`frankenphp` es Caddy, en Go; `gosu` en `postgres`) **(V por el propio escaneo de `postgres:18`)**; no analiza el PHP enlazado en `frankenphp` **(V)**.

### 2.2 Escaneo programado de lo desplegado

- Flujo nuevo con `schedule` diario **(E: 06:30 Europe/Madrid, tras la actualización diaria de la base de Trivy)** y `workflow_dispatch`.
- Escanea **lo desplegado, no `develop`**: las `sha-<7>` de los commits a los que apuntan `staging` y `main` (misma lectura con `git ls-remote` que la purga de `build-images.yml` **(V)**) y las imágenes de terceros **por el *digest* fijado** en Quadlet e `install-lib.sh`. Motivo: lo que corre puede ir por detrás del repositorio (hoy el Quadlet fija Mailpit v1.34.0 y staging corre v1.33.1 **(V)**).
- Escanea también los *lockfiles* en modo `fs` de esos mismos commits.
- Salida: SARIF a *code scanning*. El flujo **no abre *issues* automáticos** por hallazgo (sería spam con 317 HIGH); sí falla (marca roja en Actions, que GitHub notifica al propietario por correo) si aparece un `CRITICAL` con arreglo o si caduca una exclusión.

### 2.3 PHP embebido en FrankenPHP

Trivy no lo detecta **(V)**. Medidas, sin herramientas nuevas:

1. **Comprobación de versión** en el escaneo programado y en `build-images.yml`: ejecutar la imagen con `php -r 'echo PHP_VERSION;'` y compararla con la última 8.4.x del API de versiones de php.net (`https://www.php.net/releases/index.php?json&version=8.4`, documentado por php.net; respuesta desde el CI **(NV)**). Si la imagen va por detrás más de **7 días (E)** desde la publicación de la nueva 8.4.x, el flujo falla con el motivo. Si php.net no responde, aviso, no fallo.
2. **Avisos oficiales**, por suscripción del usuario en GitHub («Watch → Custom → Releases / Security alerts»): `php/php-src` (avisos de seguridad GHSA de PHP) y el repositorio de FrankenPHP (`php/frankenphp`, antes `dunglas/frankenphp` **(NV)**; versiones). php.net publica además las notas de cada versión con las correcciones de seguridad. No hace falta otra herramienta.
3. **Cómo llega el arreglo**: FrankenPHP publica una imagen nueva con la 8.4.x corregida → la etiqueta `1-php8.4-bookworm` cambia de *digest* → Renovate abre el PR (`§2.5`). Si FrankenPHP tarda, la comprobación del punto 1 lo hace visible; la salida es esperar o compilar PHP en una imagen propia, que **se descarta** salvo Crítica explotable sin imagen corregida (sería mantener un PHP propio).

### 2.4 Umbral y motivo

Con el escaneo real de staging **(V)**: la API tiene 1 CRITICAL con arreglo (`linux-libc-dev`) y 4 sin arreglo; 317 HIGH, de los que los 123 con arreglo son `linux-libc-dev`. La web tiene 3 HIGH con arreglo. Con el umbral de `§2.1`:

- API: 0 fallos (`linux-libc-dev` excluido); 4 CRITICAL sin arreglo y los HIGH en SARIF; los 4 CRITICAL sin arreglo abren *issue* `vuln-seguimiento` con revisión semanal.
- Web: 0 fallos; los 3 HIGH de `libxslt` en SARIF; se espera que desaparezcan con el siguiente *digest* de `nginx:1-alpine` **(NV)**.
- `postgres:18`: no se construye aquí; el escaneo programado lo informa. Los 26 HIGH de `gosu` (Go stdlib) dependen de que la imagen oficial se reconstruya.

`linux-libc-dev` está en la imagen porque `install-php-extensions` deja los paquetes `-dev` (`infra/containers/api/Containerfile`: un intento anterior de purgarlos se llevó `libpq.so.5`/`libzip.so.4` **(V)**). Quitarlos bien exige compilar las extensiones en una etapa y copiar solo los `.so` y sus bibliotecas: es E6, opcional.

Revisión del umbral: si tras 4 semanas **(E)** el SARIF muestra HIGH con arreglo que no se corrigen con los PR de *digest*, se considera subir el umbral de fallo a `HIGH` con arreglo (`OPEN-090-04`).

### 2.5 Fijar por *digest* y Renovate

**Recomendación**: etiqueta legible **más** *digest* (`imagen:etiqueta@sha256:…`) en todas las referencias: `FROM` de los dos `Containerfile`, `Image=` de `postgres.container` y `redis.container`, `TRAEFIK_IMAGE` de `install-lib.sh`, `compose.yaml` y `compose.prodlike.yaml` (que hoy usa `traefik:v3`, desalineado con `install-lib.sh` **(V)**). Mailpit y Garage ya siguen esta forma **(V)**.

Consecuencias para Renovate:

- Gestores: `dockerfile` (los `Containerfile` no se llaman `Dockerfile`: hay que declarar el patrón **(NV)**), `docker-compose` (ya lo cubre **(V)**), y `customManagers` de tipo *regex* para Quadlet e `install-lib.sh`, como ya se hizo con Garage en `ci-api.yml` **(V)**. Si Renovate tiene ya gestor de Quadlet, se usa ese **(NV)**.
- Regla de grupo «imágenes base y de terceros», horario semanal (el mismo lunes), `pinDigests: true`.
- Subidas **mayores** de `postgres` desactivadas (`enabled: false` para `major`): van por ADR. Redis mayor y Traefik mayor, PR propio sin grupo (como #717 para composer/npm).
- `node` y `frankenphp` solo dentro de su línea (`26`, `1-php8.4`): el cambio a otra mayor o a *trixie* es una decisión (`§2.8`).
- Volumen esperado: un PR semanal agrupado **(E)**.

**Alternativa**: fijar solo versión menor sin *digest* (`postgres:18.1`). Más legible, pero la imagen de una misma etiqueta cambia con cada reconstrucción de Debian y no se sabe qué corre. Se descarta.

### 2.6 Cadencia de reconstrucción y redespliegue

- **Semanal**: el PR agrupado de Renovate (lunes) se mezcla tras CI en verde; `develop` publica `sha-<7>` nuevas; se promueve a `staging` y se despliega en la ventana del miércoles (`§4`).
- **Bajo demanda por CVE Crítico**: forzar el PR desde el *Dependency Dashboard* de Renovate o abrirlo a mano, y **ventana extraordinaria** (`§4.1`).
- **Redespliegue** de imágenes propias: `install.sh` con la nueva `sha-<7>` (procedimiento de `RUNBOOK.md §3b`). Para `postgres` y `redis`, el *digest* nuevo llega con el mismo `install.sh` (cambia el `Image=`) y se reinicia **solo** esa unidad; `CS-07` prueba 2 ya demostró que API y *workers* sobreviven al reinicio de PostgreSQL **(V)**.
- **Nunca** se reconstruye sobre la misma `sha-<7>` (`ADR-090 §4`).

### 2.7 Por componente

| Componente | Cómo se detecta | Cómo se corrige | Atención |
|---|---|---|---|
| PHP | `§2.3` | *Digest* nuevo de FrankenPHP | Menor de PHP (8.4 → 8.5) = decisión aparte |
| Extensiones PHP | Trivy (bibliotecas del sistema que enlazan) | Reconstrucción | `pcntl` imprescindible para los *workers* **(V)** |
| PostgreSQL | Escaneo programado; notas de versión de postgresql.org | *Digest* nuevo dentro de 18 | Las menores no cambian el formato en disco (documentación de PostgreSQL); leer las notas por si piden `REINDEX` |
| Redis | Escaneo programado | *Digest* nuevo dentro de 8 | Reinicio corto; sesiones y caché se pierden si no hay persistencia **(NV)** |
| `nginx` (web) | Trivy de imagen | *Digest* nuevo de `1-alpine` | — |
| Traefik | Escaneo programado; versiones en GitHub de `traefik/traefik` | Renovate en `install-lib.sh` | Única pieza con `label=disable` (provisional hasta `0.10e-d`) **(V)** |
| Mailpit | Escaneo programado | Ya por *digest* | Solo staging (`ADR-087`) |
| Garage | Renovate (ya) | — | Nunca en servidores (`ADR-076`) |

### 2.8 Debian *bookworm* frente a *trixie*

**Recomendación: pasar a *trixie*** la imagen de la API (`dunglas/frankenphp:1-php8.4-trixie`, existencia **(NV)**) y la etapa de construcción de la web (`node:26-trixie-slim`, **(NV)**), en una entrega propia (E5) **después** de E1 y E2, para comparar con la línea base de escaneo.

- **A favor**: *bookworm* es *oldstable*; según el calendario publicado por Debian su soporte de seguridad ordinario terminaba hacia junio de 2026 y sigue después solo el de Debian LTS, con menos paquetes cubiertos **(NV: comprobar fechas en debian.org en E5)**. Los 4 CRITICAL sin arreglo pueden tenerlo en *trixie* **(NV)**. `postgres:18` ya corre sobre Debian 13 **(V)**.
- **Riesgos**: cambian glibc, ICU (`intl`: ordenación y formatos de fecha y número en los cuatro idiomas), `libpq`, `libxml2`, `gd`/`libjpeg`; la compilación de extensiones con `install-php-extensions` puede fallar. Ninguno toca datos persistentes: la imagen de la API no guarda estado y la de la base no cambia.
- **Cómo se prueba**: suite completa, E2E real local (con generación de PDF cuando exista y exportaciones), revisión de los textos con fecha y número en los cuatro idiomas, despliegue en staging en ventana y **reversión probada** a la `sha-<7>` anterior.
- **Reversible**: sí, por etiqueta.

---

## 3 · Host del VPS (AlmaLinux 10.2)

### 3.1 Estrategia recomendada (C1)

1. **Automático, a diario**: `dnf-automatic` **solo seguridad** (`upgrade_type = security`), **instala**, **sin reinicio** (`reboot = never` o equivalente **(NV)**), con exclusiones (`§3.2`). Los avisos de seguridad de AlmaLinux (ALSA) alimentan `--security` **(NV en el VPS: `dnf updateinfo list --security`)**.
2. **En la ventana semanal**: lo excluido, el resto de actualizaciones (`dnf upgrade`), y el reinicio **solo si** `dnf needs-restarting -r` lo pide (el núcleo instalado por `dnf-automatic` no se usa hasta reiniciar).
3. **Ventana extraordinaria** si llega un aviso Crítico de un paquete excluido o del núcleo.

### 3.2 Paquetes que van aparte (ventana, no automático)

`podman`, `crun`, `runc` (si está), `conmon`, `netavark`, `aardvark-dns`, `passt` (red sin privilegios), `containers-common`, `container-selinux`, `selinux-policy*`, `systemd*`, `cloud-init`. Motivo: un cambio en cualquiera de ellos puede dejar la pila sin arrancar, con SELinux denegando o con la red de Quadlet distinta, y debe ir seguido de la lista posterior.

**No** se excluyen `openssh*`, `openssl*`, `glibc`, `kernel*` (solo se instala; se activa al reiniciar). `openssh-server` reinicia `sshd` al actualizarse y las sesiones abiertas sobreviven **(NV en AlmaLinux 10)**; si se prefiere prudencia, va a la ventana (`OPEN-090-08`).

La opción de exclusión en `automatic.conf` (sección `[base]`, `excludepkgs`) existe en `dnf` 4 **(NV en la versión de AlmaLinux 10.2: comprobar `dnf --version` y `man dnf-automatic`)**.

### 3.3 Sin romper SELinux, `cloud-init` ni Quadlet

- **SELinux**: tras toda actualización y todo reinicio, `getenforce` = `Enforcing`, `grep '^SELINUX=' /etc/selinux/config` = `enforcing`, y `sudo ausearch -m avc,user_avc -ts boot` (o `-ts recent`) sin denegaciones nuevas (lo ejecuta el usuario).
- **`cloud-init`**: `grep -c bootcmd /etc/cloud/cloud.cfg` = `0` **(V: comprobación pedida por el usuario)**. Además, en E4 se comprueba si `/etc/cloud/cloud.cfg` es `%config(noreplace)` (`rpm -qc cloud-init`; `rpm -V cloud-init`) **(NV)**: si lo es, una actualización deja `cloud.cfg.rpmnew` y no lo pisa, y la comprobación pasa a ser también «no hay `.rpmnew` sin revisar». Mitigación más fuerte, a decidir (`OPEN-090-07`): fijar la lista de módulos sin `bootcmd` en `/etc/cloud/cloud.cfg.d/99-plataforma.cfg` (sobrevive a la actualización del paquete) o desactivar `cloud-init` tras el aprovisionamiento (`/etc/cloud/cloud-init.disabled`), con el riesgo de que Euronodes configure la red con él en cada arranque **(NV)**.
- **Quadlet sin privilegios**: tras actualizar `podman`/`systemd`, validar el generador en seco (`/usr/lib/systemd/user-generators/podman-user-generator -dryrun`, como `quadlet-lint` del CI **(NV la ruta en AlmaLinux 10)**), `systemctl --user daemon-reload` y comprobar que ninguna unidad generada falta (la trampa del `Label=` con espacios demuestra que Quadlet descarta en silencio **(V)**). `loginctl show-user <usuario> -p Linger` = `yes`.

### 3.4 Quién hace qué

| Acción | Usuario (con `sudo`) | Sesión de Claude por SSH, sin `sudo` |
|---|---|---|
| Instalar y configurar `dnf-automatic` (una vez) | Sí | No; prepara el fichero de configuración en el repositorio y el procedimiento |
| `dnf upgrade`, reinicio, `ausearch`, `journalctl` del sistema | Sí | No |
| Leer estado: `rpm -q`, `dnf check-update` con la caché existente **(NV sin root)**, `dnf needs-restarting -r` **(NV sin root)**, `getenforce`, `grep` en `/etc/cloud/cloud.cfg`, `uname -r`, `df` | Puede | Sí |
| Despliegue de contenedores (`install.sh --user`, `systemctl --user`, `podman pull`) | Puede | Sí |
| Humo, listas previa y posterior, registro de la ventana | Revisa | Sí |

La clave `claude-staging` está pendiente de retirarse del VPS al terminar `0.10e-c` **(V, `memory.md`)**: si se retira, todo lo de la columna derecha lo ejecuta el usuario con los guiones de E3 (`OPEN-090-09`).

### 3.5 Herramientas evaluadas

| Herramienta | Uso | Mantenimiento y licencia | Decisión |
|---|---|---|---|
| `dnf-automatic` (dnf 4) / plugin `automatic` de dnf 5 | Seguridad diaria sin reinicio | Parte de DNF, mantenida por Red Hat/Fedora, GPL; viene con la distribución | **Se usa**. Versión de dnf en AlmaLinux 10.2 **(NV)** |
| `dnf needs-restarting` (dnf-plugins-core) | Decidir si hace falta reiniciar | Igual | **Se usa** |
| Temporizadores de `systemd` | Lo que dispara `dnf-automatic` | Del sistema | **Se usan** (los de `dnf-automatic`) |
| kpatch | Parche del núcleo en caliente | En RHEL requiere suscripción; contenido gratuito para AlmaLinux **(NV)** | Descartado |
| KernelCare (TuxCare) | Ídem | Comercial | Descartado (coste, dependencia nueva) |
| `podman auto-update` | Actualizar contenedores desde el registro | Parte de Podman | Descartado: salta el circuito de staging |
| `dnf history undo` | Reversión de una transacción | Parte de DNF | Vía de reversión, depende de que los RPM antiguos sigan en el repositorio **(NV)** |

---

## 4 · Ventana de mantenimiento

### 4.1 Calendario y duración

- **Staging**: semanal, **miércoles 20:00-21:00 Europe/Madrid (E, a decidir por el usuario, `OPEN-090-01`)**, duración máxima **60 min (E)** incluida la reversión; si a los 40 min no hay *go*, se revierte. Elegida tras el PR semanal de Renovate (lunes) para tener uno o dos días de CI y revisión.
- **Extraordinaria**: por CVE Crítico (`§1.2`), cualquier día, con las mismas listas.
- **Producción**: abierta (`OPEN-090-02`). Condicionantes: fuera del horario lectivo (nota de `§8.4` de requisitos); con un solo host, el reinicio es una parada y contradice `RARQ-DEP-001`; las actualizaciones de imágenes sí pueden hacerse sin parada si existe la segunda réplica de `0.10e-d`.

### 4.2 Registro y comunicación

- **Un *issue* por ventana** (`Ventana staging AAAA-MM-DD`, etiqueta `mantenimiento`), abierto al empezar, con lista previa, pasos, salida del humo, lista posterior y decisión *go*/reversión; se cierra al terminar. Plantilla en E3.
- Una línea en `memory.md` con el enlace (nota viva del paso, `CLAUDE.md §3`).
- Staging no tiene usuarios externos: no hay aviso a terceros. En producción, aviso a los centros con antelación a decidir (`OPEN-090-02`).

### 4.3 Lista previa (*no-go* si falla cualquiera)

1. `develop` con CI en verde en el commit a promover; Trivy de imagen sin CRITICAL con arreglo.
2. Ningún despliegue ni promoción en curso; ningún PR de promoción abierto a medias.
3. **Etiqueta actual anotada** para revertir: `sha-<7>` desplegada (de las unidades en `~/.config/containers/systemd/`) y *digests* de `postgres`, `redis` y Traefik (`podman inspect --format '{{.ImageDigest}}'`).
4. La imagen anterior existe **en el almacén local** (`podman image exists ghcr.io/…:sha-<anterior>`) y en GHCR; no se ejecuta `podman image prune` hasta la ventana siguiente.
5. Espacio en disco: `df -h /` y `podman system df`; umbral **≥ 5 GB libres (E)**.
6. Estado de partida sano: 8 contenedores `healthy`, `systemctl --user --failed` vacío, `getenforce` = `Enforcing`, `grep -c bootcmd /etc/cloud/cloud.cfg` = `0`.
7. Lista de paquetes pendientes (`dnf check-update`) y si hace falta reinicio (`dnf needs-restarting -r`).
8. Humo en verde **antes** de tocar nada (línea base).
9. *Issues* `vuln-seguimiento` con revisión vencida (`§1.4`), para decidir en la ventana.

### 4.4 Pasos

1. Promoción `develop` → `staging` (`ADR-080 §4.3`) si hay imágenes nuevas.
2. Despliegue de contenedores: `install.sh sha-<7> --user --with-mailpit`, `daemon-reload`, `restart plataforma-migrate.service` (comprobar `Result=success`), reinicio de `api@1`, `web`, `traefik`, `worker`, `worker-long`; y de `postgres`/`redis` **solo** si cambió su *digest*.
3. Humo intermedio.
4. Host (usuario): `sudo dnf upgrade` (o solo lo excluido de `dnf-automatic`); revisar `.rpmnew`.
5. Si `needs-restarting -r` lo pide: `sudo systemctl reboot` y esperar el arranque solo (`linger`).
6. Lista posterior y humo final.

### 4.5 Lista posterior

1. 8 contenedores `healthy` (9 con Mailpit si aplica; contar contra la lista de unidades, no contra un número fijo).
2. `systemctl --user --failed` = 0 y, tras reinicio, `systemctl --failed` = 0 (usuario).
3. `getenforce` = `Enforcing`; `/etc/selinux/config` en `enforcing`; `ausearch` sin denegaciones nuevas (usuario).
4. `grep -c bootcmd /etc/cloud/cloud.cfg` = `0` y sin `cloud.cfg.rpmnew` pendiente.
5. `plataforma-migrate`: `Result=success` y `migrate:status` sin pendientes (desde el contenedor de la API; también prueba la conexión a la base, que `/api/health` no prueba **(V)**).
6. HTTP: la web responde 200 por HTTPS en un centro; `/api/health` 200; una ruta de la API sin sesión devuelve el código esperado (no la SPA con 200, trampa conocida **(V)**); el *backoffice* devuelve 403 fuera de la lista blanca.
7. Cola: `core:queue-health` en `worker` y `worker-long` (es su `HealthCmd` **(V)**) y un trabajo de prueba procesado.
8. Correo: un correo de prueba llega a Mailpit (mientras no haya *relay*, `CS-19`).
9. Tiempos: del reinicio a 8/8 `healthy` (referencia `CS-04`) y de la API, anotados en el *issue*.
10. Versiones resultantes: `sha-<7>`, *digests*, `uname -r`, PHP (`php -v` en la API).

### 4.6 Regresión

- **Mínimo automatizable (E3)**: un guion de humo sin privilegios, ejecutable desde el VPS o desde fuera, que cubra `§4.5` puntos 5-8, con salida legible y código de salida distinto de 0 si algo falla. Sin datos de prueba: hoy staging tiene 0 centros **(V)**; el humo prueba lo que existe sin sesión.
- **Hacia `CS-17`**: cuando exista la especificación y la implementación del E2E real contra staging (contenedor de un solo uso, decisión del usuario **(V)**), sustituye al humo como criterio de *go* para cambios que tocan la imagen de la API o de la web; el humo sigue para cambios solo de host.
- **Fuera de alcance, propuesta**: una sonda de disponibilidad que compruebe base de datos y Redis (`RARQ-DEP-008`) daría al humo y a `HealthCmd` una señal mejor que `/api/health`. Necesita especificación; no se diseña aquí.

### 4.7 *Go*/*no-go* y reversión

- ***Go***: lista posterior completa y humo en verde.
- **Reversión de imágenes propias**: `install.sh <sha-anterior> --user` y reinicio de unidades (`RUNBOOK.md §3b.3`); `CS-08` midió 23,2 s **(V)**. Migraciones: **nunca** `migrate:rollback`; las migraciones son *expand/contract* y la imagen anterior funciona con el esquema nuevo.
- **Reversión de `postgres`/`redis`**: volver al *digest* anterior (anotado en la lista previa). Dentro de la misma versión mayor de PostgreSQL, el formato en disco no cambia y volver a la menor anterior es posible (documentación de PostgreSQL); leer las notas por si la nueva pidió `REINDEX`.
- **Reversión de paquetes del host**: `sudo dnf history undo <id>` **(NV: depende de que los paquetes antiguos sigan en el repositorio)**; núcleo: arrancar el anterior desde el menú de arranque (se conservan varios **(NV el número en AlmaLinux 10)**) con la consola del proveedor.
- **Si el reinicio falla** (no responde por SSH en **10 min (E)**): consola web o VNC de Euronodes **(NV si existe y cómo se accede)**; comprobar SELinux y `cloud-init` primero (causa conocida de `CS-04`); si no arranca, núcleo anterior. Como último recurso, staging se reconstruye desde cero: es destruible (`ADR-080`), pero el reaprovisionamiento trae de vuelta el `bootcmd` y `ssh_pwauth` del `user-data` **(V)** y los ficheros de `~/.config/plataforma/` (secretos) solo existen en el VPS **(V)**: se pierden si no hay copia (`§4.8`).

### 4.8 Reversión sin copias de seguridad: qué existe y qué falta

**Existe hoy**: imágenes por etiqueta en GHCR (retención de `ADR-037 §5.1`) y en el almacén local; definiciones Quadlet versionadas en Git y regeneradas por `install.sh`; *digests* anteriores en el historial de Git (tras E2); `dnf history`; núcleos anteriores; datos de staging sintéticos (hoy ninguno; recreables con `REQ-SEED` cuando exista, `1.15b`). Instantáneas del proveedor: **(NV, `OPEN-090-11`)**.

**Dependería de copias** (aparcadas): pérdida del disco o reinstalación del VPS (volúmenes de PostgreSQL y secretos de `~/.config/plataforma/`); actualización de PostgreSQL que dañe el clúster; cualquier cambio de versión mayor de PostgreSQL (`ADR-061`); y, en producción, todo lo anterior con datos reales. **En producción no se aplica este plan sin `REQ-BKP`/`OPEN-10` resueltos**: sin copia, una ventana fallida con datos reales no tiene reversión.

---

## 5 · Entregas

Cada entrega ≤ 3 tramos de `implementer` (`CLAUDE.md §2.7`), con Codex primero (`ADR-069`). La numeración en `PLAN-IMPLEMENTACION.md` la decide la sesión (propuesta: un paso `0.10g` con sufijos `-a`..`-f`).

| Entrega | Contenido | Depende de | Tramos (E) | Clasificación y revisores | Terminado cuando | En el VPS |
|---|---|---|---|---|---|---|
| **E1** | Trivy de imagen en `build-images.yml` (umbral `§2.1`), flujo programado diario sobre lo desplegado (`§2.2`), SARIF, exclusiones con caducidad (sobre #735), etiqueta y plantilla `vuln-seguimiento`, *issues* de los 4 CRITICAL sin arreglo de la API, comprobación de `PHP_VERSION` (`§2.3`) | #735 | 1-2 | **Crítico** por la regla de la duda (permisos `security-events: write` y cadena de suministro): Codex + `security-reviewer` | El PR muestra SARIF de las dos imágenes; un `CRITICAL` con arreglo simulado hace fallar el job; una exclusión caducada vuelve a marcarse; el programado corre a mano (`workflow_dispatch`) en verde | Nada |
| **E2** | *Digests* en `Containerfile`, Quadlet, `install-lib.sh`, `compose*.yaml` (incluida la alineación de Traefik en `compose.prodlike.yaml`); reglas de Renovate (`§2.5`); acciones por SHA si `OPEN-090-10` lo decide | E1 (para comparar la línea base) | 1-2 | Ordinario; `security-reviewer` solo si Codex trae Crítico o Alto | Renovate detecta todos los `Image=`/`FROM` (comprobado en su *Dependency Dashboard*); suite y E2E real local en verde; `test-install-env.sh` en verde | Primer despliegue con *digests* en ventana: `podman inspect` coincide con lo fijado; reversión al *digest* anterior de `redis` ensayada |
| **E3** | Guiones sin privilegios de lista previa, humo y lista posterior; plantilla de *issue* de ventana; sección de `RUNBOOK.md`/`SYSADMIN.md`; proteger en la purga de GHCR también la `sha-<7>` anterior de `staging` | E2 | 1-2 | Ordinario | Los guiones pasan en local (`compose.prodlike.yaml`) y fallan con código ≠ 0 al parar un servicio | **Primera ventana real** completa con registro |
| **E4** | Host: configuración de referencia de `dnf-automatic` y procedimiento que aplica el usuario; comprobaciones de `cloud-init` (`%config`, `.rpmnew`, mitigación de `OPEN-090-07`); procedimiento de reinicio y de fallo | E3, `OPEN-090-03`, `-07`, `-08` | 1 | **Crítico** (host expuesto, SELinux): Codex + `security-reviewer` | Procedimiento revisado; marcas **(NV)** de `§3` resueltas en el VPS | El usuario instala `dnf-automatic`; ventana con reinicio real y lista posterior completa (repite `CS-04`) |
| **E5** | Debian *trixie* en la API y en la construcción de la web (`§2.8`) | E1, E2, `OPEN-090-06` | 1-2 | Ordinario; `db-reviewer` no (no hay migraciones) | Suite, E2E real local y textos de los cuatro idiomas comprobados; Trivy compara contra la línea base | Despliegue en ventana y **reversión probada** a la imagen *bookworm* |
| **E6** (opcional) | Imagen de la API sin `-dev` ni `linux-libc-dev` (extensiones en etapa aparte) | E5 | 1-2 | Ordinario | Mismas extensiones cargadas (`php -m`), suite y E2E en verde, Trivy sin `linux-libc-dev` | Despliegue y reversión en ventana |

`CS-17` sigue su propio camino (especificación con `spec-writer`) y no es una entrega de este plan; cuando exista, entra en `§4.6`.

---

## 6 · Preguntas abiertas

La opción recomendada va primero y marcada. Ninguna está decidida.

- **`OPEN-090-01` · Ventana de staging.** **(Recomendada) Semanal, miércoles 20:00-21:00, 60 min máx.** — tras el PR de Renovate del lunes; exige al usuario estar disponible para `sudo` una hora a la semana. / Quincenal — la mitad de trabajo, parches de host hasta 14 días tarde. / Sin fija, cuando haya algo — menos carga, se acumulan reinicios.
- **`OPEN-090-02` · Ventana de producción.** **(Recomendada) Decidir al resolver `OPEN-11` (alojamiento del piloto), con dos hosts o réplica que permitan actualizar sin parada (`RARQ-DEP-001`); entretanto, ninguna.** / Ventana nocturna de fin de semana con parada aceptada — contradice `RARQ-DEP-001` y exige aceptarlo por escrito.
- **`OPEN-090-03` · Automatización del host.** **(Recomendada) C1: `dnf-automatic` solo seguridad, sin reinicio, con exclusiones; resto y reinicio en ventana.** / C2: todo manual en ventana — más simple, parches de OpenSSL/OpenSSH hasta 7 días tarde. / C3: todo automático con reinicio — descartada (`ADR-090 §4`).
- **`OPEN-090-04` · Umbral de fallo de Trivy.** **(Recomendada) Fallar por CRITICAL con arreglo, excluido `linux-libc-dev`; HIGH solo informa; revisión a las 4 semanas.** / Fallar también por HIGH con arreglo — hoy fallaría la web (3 `libxslt`) en cada PR hasta el siguiente *digest*. / No fallar nunca, solo informar — nada obliga a actuar.
- **`OPEN-090-05` · Capa de actualización de paquetes en las imágenes propias.** **(Recomendada) No añadirla ahora; decidir con 4 semanas de datos del escaneo programado.** / Añadir `apt-get upgrade`/`apk upgrade` en el `Containerfile` — parches antes, pero la imagen deja de ser reproducible por *digest* de base y la caché de construcción los congela si no se fuerza.
- **`OPEN-090-06` · Debian *trixie*.** **(Recomendada) Sí, en E5, tras E1 y E2.** / Quedarse en *bookworm* hasta que FrankenPHP deje de publicarla — menos trabajo, más CVE sin arreglo y soporte LTS reducido. / Hacerlo ya, antes del escaneo — sin línea base con la que comparar.
- **`OPEN-090-07` · `cloud-init`.** **(Recomendada) Fichero `cloud.cfg.d/99-plataforma.cfg` sin `bootcmd` + comprobación en cada ventana; ticket opcional a Euronodes para quitar `bootcmd`, `ssh_pwauth` y `chpasswd` del `user-data`.** / Desactivar `cloud-init` tras el aprovisionamiento — más robusto, riesgo de perder la red si el proveedor la configura con él **(NV)**. / Solo la comprobación — lo mínimo, depende de que nadie la olvide.
- **`OPEN-090-08` · OpenSSH en automático.** **(Recomendada) Sí, en `dnf-automatic`.** / Llevarlo a la ventana — cero riesgo de corte de SSH fuera de ventana, parche más tarde.
- **`OPEN-090-09` · Qué hace Claude por SSH.** **(Recomendada) Mantener una clave sin `sudo` dedicada a mantenimiento (lectura, despliegue sin privilegios, humo, registro); el usuario ejecuta todo lo que exige root.** / Retirar la clave de Claude y que el usuario ejecute los guiones de E3 — menos superficie, más trabajo del usuario. / Regla `sudoers` limitada a `dnf` y reinicio — descartada: una clave robada obtiene root.
- **`OPEN-090-10` · Acciones de CI fijadas por SHA.** **(Recomendada) Sí (preset de Renovate para fijar *digests* de acciones), en E2.** Motivo: una etiqueta de acción puede moverse a código malicioso; hay avisos públicos de 2025-2026 sobre etiquetas de acciones comprometidas, incluida `trivy-action` **(NV: confirmar en E2 el aviso y si `v0.36.0` está afectada)**. / Seguir con etiquetas — menos PR de Renovate.
- **`OPEN-090-11` · Instantáneas del proveedor.** **(Recomendada) Que el usuario compruebe si Euronodes las ofrece y su coste; si son gratuitas o baratas, una antes de cada ventana con reinicio.** / No usarlas — staging es destruible, pero los secretos de `~/.config/plataforma/` solo están en el VPS.
- **`OPEN-090-12` · Plazos de `§1.2`.** **(Recomendada) Aceptarlos como objetivo para staging y revisarlos antes de producción.** / Plazos más cortos — no sostenibles con una persona.

---

## 7 · Lo que no se ha verificado

Versión de `dnf` en AlmaLinux 10.2 y nombre de los paquetes `dnf-automatic`/`needs-restarting`; opciones `excludepkgs` y `reboot` de `automatic.conf`; que `dnf check-update` y `needs-restarting -r` funcionen sin root; ruta del generador de Quadlet de usuario en AlmaLinux 10; si `cloud.cfg` es `%config(noreplace)`; si Euronodes ofrece instantáneas y consola; kpatch en AlmaLinux; reinicio de `sshd` al actualizar; número de núcleos conservados; etiquetas `-trixie` de FrankenPHP y Node; calendario de soporte de *bookworm*; respuesta del API JSON de php.net desde el CI; sintaxis exacta de caducidad del fichero de exclusiones de Trivy; patrón de Renovate para `Containerfile` y gestor de Quadlet; frecuencia con que se reconstruyen las imágenes de origen; persistencia de Redis en staging; aviso de compromiso de `trivy-action`. Ninguna de estas comprobaciones bloquea aceptar el ADR; cada una se resuelve en la entrega que la necesita.
