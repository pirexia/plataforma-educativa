# ADR-090 · Gestión de vulnerabilidades y versiones, parcheo de contenedores y del host, y ventana de mantenimiento

**Estado**: **PROPUESTA** (2026-11-17). Pendiente de `OPEN-090-01` a `OPEN-090-12` (`ADR-090-anexo-plan-parcheo-y-mantenimiento.md §6`). Nada de este ADR está implementado.
**Fecha**: 2026-11-17
**Origen**: objetivo del usuario del 2026-11-17: plataforma segura, parcheada y en últimas versiones, comprobando que todo sigue funcionando; todo cambio se prueba primero en desarrollo, después en staging y, cuando exista, en producción.
**Anexo**: [`ADR-090-anexo-plan-parcheo-y-mantenimiento.md`](ADR-090-anexo-plan-parcheo-y-mantenimiento.md) (procedimientos, listas de comprobación, entregas y preguntas abiertas). El encargo pedía el plan en `docs/proceso/`; el ámbito de escritura de `architect` es `docs/adr/`, así que se deja como anexo del ADR y se señala para que la sesión decida si lo mueve.
**No sustituye** ningún ADR. Se apoya en `ADR-037` (imágenes, Quadlet, retención de GHCR), `ADR-080` (staging destruible y sin datos reales, promoción `develop` → `staging` → `main`), `ADR-087`, `ADR-088` y `ADR-061` (subida mayor de PostgreSQL, precedente de cambio de versión mayor con ADR propio).
**Fuera de alcance**: el plan de copias de seguridad (`REQ-BKP`, `OPEN-10`), **aparcado por decisión del usuario**. Este ADR señala dónde dependería de él (anexo `§4.8`) y no lo diseña.

---

## 1 · Contexto

Hechos verificados el 2026-11-17 (no se reabren aquí):

1. **Detección actual.** Renovate (app de Mend, `renovate.json`: PR agrupados, horario «before 6am on monday», `vulnerabilityAlerts` «at any time», `constraints npm <12`, *Dependency Dashboard* por `config:recommended`) y Dependabot **solo alertas y security updates** (sin `dependabot.yml`, por decisión: no duplicar a Renovate; ya abrió #732 y #733). Trivy en CI (`dependency-scan.yml`, `aquasecurity/trivy-action@v0.36.0`) **solo en modo `fs`** sobre `composer.lock` y `package-lock.json`, `HIGH,CRITICAL`, `ignore-unfixed`, y **solo en `pull_request`**: no escanea imágenes ni hay escaneo programado. `.trivyignore` con caducidad en preparación (#735).
2. **Escaneo manual de lo desplegado en staging** (Trivy 0.76.2): API (Debian 12.15, PHP 8.4.27 ZTS, FrankenPHP v1.13.5) 5 CRITICAL (1 con arreglo, `linux-libc-dev`; 4 sin arreglo: `libaom3`, `libsqlite3`, `libxml2`, `zlib1g`) y 317 HIGH (los 123 con arreglo son `linux-libc-dev`); web (Alpine 3.24.4) 3 HIGH con arreglo (`libxslt`); `postgres:18` (Debian 13.7) 2 CRITICAL y 148 HIGH (26 del binario `gosu`, Go stdlib 1.24.6, con arreglo; el resto Debian sin arreglo); `redis:8-alpine`, Traefik v3.7.19 y Mailpit v1.33.1, 0. **Trivy no evalúa el intérprete PHP embebido en el binario `frankenphp`**: que no salga ningún CVE de PHP no prueba que no lo haya.
3. **Imágenes.** Bases con etiqueta flotante (`dunglas/frankenphp:1-php8.4-bookworm`, `node:26-bookworm-slim` solo en construcción, `nginx:1-alpine`, `postgres:18`, `redis:8-alpine`); Traefik (`infra/install-lib.sh`), Mailpit y Garage por versión o *digest*. El parche de una base solo llega al **reconstruir y desplegar**, y hoy no hay cadencia de reconstrucción. Comprobado en el repositorio: `infra/quadlet/postgres.container` y `redis.container` llevan `Image=` con etiqueta flotante, y Podman **no vuelve a descargar** una etiqueta que ya tiene en local al arrancar la unidad; lo que corre en staging es lo que se descargó la primera vez, ni reproducible ni actualizado. Debian 12 (*bookworm*) es *oldstable*; Debian 13 (*trixie*) es la estable.
4. **Retención de GHCR** (`build-images.yml`, `ADR-037 §5.1`): se conservan las 10 `sha-<7>` más recientes de `develop`, las de los commits a los que apuntan `staging` y `main`, y todas las `vX.Y.Z`. La imagen anterior de staging deja de estar protegida en cuanto `staging` avanza.
5. **VPS de staging** (`adagiox-stg-lis-01`, AlmaLinux 10.2, 2 vCPU, 4 GB, Podman sin privilegios con Quadlet y `linger`, SELinux `Enforcing`): `sudo` pide contraseña, así que lo que exige root lo ejecuta el usuario. Despliegue: `./infra/install.sh sha-<7> --user --with-mailpit`, `daemon-reload`, `restart plataforma-migrate.service`, reinicio de `api@1`, `web`, `traefik`, `worker` y `worker-long`. Trampas: el `bootcmd` de `cloud-init` ponía SELinux en `permissive` en cada arranque (quitado de `/etc/cloud/cloud.cfg` por el usuario, copia `.bak-selinux`; una actualización de `cloud-init` podría devolverlo); Quadlet descarta en silencio un `Label=` con espacios; `plataforma-migrate` es *oneshot* con `RemainAfterExit` (`restart`, nunca `start`); sin `pcntl` los *workers* no paran; mientras `api@` reinicia, `/api` cae a la SPA con 200 unos segundos. Reinicio del host ya ensayado (`CS-04`): 8 contenedores `healthy` y orden correcto. `/api/health` **no toca la base de datos** (`CS-07`, prueba 2).
6. **Entornos**: staging solo con datos sintéticos, certificados de pruebas y destruible (`ADR-080`); hoy tiene 0 centros y 0 usuarios y `demo:seed` no existe (`1.15b`). Producción no existe. El E2E real contra la API solo corre en local; `CS-17` (E2E real contra staging) necesita especificación propia.
7. **Requisitos que condicionan**: `RNF-MANT-004` (CI con escaneo de seguridad), `RSEC-PENT-004` (críticas y altas remediadas antes del *go-live*), `RARQ-DEP-001`/`-008`/`-013` (sin parada, sondas, reversión probada) y la nota de `§8.4` del documento de requisitos: «un centro con 900 familias no tiene ventana de mantenimiento aceptable en horario lectivo».

El problema: la detección cubre las dependencias de aplicación pero **no** lo que corre (imágenes, PHP embebido, host), no hay cadencia que convierta un parche disponible en un parche desplegado, y no hay procedimiento para actualizar y reiniciar el host sin romper SELinux, `cloud-init` o Quadlet.

## 2 · Opciones consideradas

Por eje; el detalle y la evaluación completa están en el anexo.

**A. Detección en imágenes.** (A1) Trivy de imagen en el CI de construcción más escaneo programado diario de lo desplegado, con resultados SARIF en *code scanning* de GitHub. (A2) Solo escaneo programado. (A3) Otra herramienta (Grype, Docker Scout). (A4) Seguir a mano.

**B. Cadencia de reconstrucción.** (B1) Fijar cada imagen base y de terceros por **etiqueta + *digest*** y dejar que Renovate abra un PR agrupado semanal cuando el *digest* cambie: cada reconstrucción es un commit, con su `sha-<7>`, su CI y su reversión. (B2) Flujo programado que reconstruye `develop` sin caché cada semana con la misma etiqueta o con un sufijo. (B3) `podman auto-update` en el host.

**C. Host.** (C1) `dnf-automatic` solo de seguridad, **sin reinicio automático**, con el paquete de contenedores, SELinux, `systemd` y `cloud-init` excluidos y llevados a la ventana. (C2) Todo manual en la ventana. (C3) Actualización completa automática con reinicio automático. (C4) Parcheo del núcleo en caliente (kpatch/KernelCare).

**D. Ventana.** (D1) Ventana semanal fija en staging con listas de comprobación previa y posterior, humo automatizado y registro en un *issue* por ventana; producción, abierta. (D2) Sin ventana: actualizar cuando toque.

## 3 · Decisión (propuesta)

1. **Política** (anexo `§1`): ámbitos con su fuente de detección; plazos objetivo por severidad (**estimación**: Crítica 3 días hasta staging y 7 hasta producción; Alta 30; Media 90; Baja en la reconstrucción periódica); circuito único desarrollo → PR con CI → `develop` → staging (ventana, humo) → `main` → producción, con criterios de promoción; **todo hallazgo aceptado sin arreglo lleva *issue* propio con etiqueta `vuln-seguimiento` y una entrada en el fichero de exclusiones de Trivy con fecha de caducidad**, de modo que al caducar el escaneo vuelve a fallar y obliga a revisarlo.
2. **A1**: Trivy de imagen en `build-images.yml` (falla solo por CRITICAL con arreglo, excluido `linux-libc-dev` con motivo escrito) y escaneo programado diario de las imágenes **tal como están desplegadas en `staging` y `main`** y de las de terceros fijadas, con SARIF en *code scanning*. Para el PHP embebido, comprobación propia de versión (`PHP_VERSION` de la imagen contra la última 8.4.x publicada por php.net) y vigilancia de avisos oficiales (anexo `§2.3`).
3. **B1**: imágenes base y de terceros por etiqueta + *digest* en `Containerfile`, Quadlet, `install-lib.sh` y `compose*.yaml`; Renovate las actualiza en un grupo semanal; las subidas mayores de PostgreSQL quedan fuera (ADR propio, como `ADR-061`). «Bajo demanda por CVE crítico» = forzar ese PR desde el *Dependency Dashboard* o a mano.
4. **C1**: la configuración la aplica **el usuario** (tiene `sudo`) y Claude solo hace comprobaciones de lectura y el despliegue de contenedores sin privilegios. El reinicio, si `needs-restarting -r` lo pide, en la ventana.
5. **D1**: ventana semanal de staging (propuesta: miércoles, 20:00-21:00 Europe/Madrid, 60 min máximo; **estimación**, la decide el usuario), con lista previa, pasos, lista posterior, humo automatizado contra la API real, criterios *go/no-go* y de reversión, y un *issue* por ventana como registro. El humo evoluciona hacia `CS-17`. Para producción, la ventana queda abierta (`OPEN-090-02`) y choca con `RARQ-DEP-001` mientras haya un solo host.
6. **Debian *trixie***: se recomienda pasar la imagen de la API (y la etapa de construcción de la web) a *trixie* en una entrega propia, **después** de tener la línea base de escaneo; no se ejecuta aquí (`OPEN-090-06`).
7. **Entregas** (anexo `§5`): E1 escaneo de imágenes y seguimiento; E2 fijado por *digest* y Renovate; E3 humo, listas de comprobación y registro; E4 host; E5 *trixie*; E6 opcional, imagen de la API sin cabeceras ni `-dev`. Cada una ≤ 3 tramos de `implementer`.

## 4 · Motivo

- **A1 frente a A2-A4.** Trivy ya está en el CI (sin dependencia nueva), escanea imágenes, binarios Go y paquetes del sistema, y su base de datos de vulnerabilidades se actualiza a diario: sin escaneo programado, un CVE publicado después de construir la imagen no aparece nunca. SARIF en *code scanning* (gratuito en repositorio público) da estado, deduplicación y cierre automático sin escribir un gestor de *issues*. A3 añade una herramienta con su propio ruido sin cubrir el hueco del PHP embebido, que ninguna de ellas cubre.
- **Umbral.** El escaneo real de staging da 317 HIGH en la API, de los que los 123 con arreglo son `linux-libc-dev` (cabeceras del núcleo, que no se ejecutan: el núcleo es el del host) y el resto no tiene arreglo en Debian. Fallar por HIGH bloquearía todos los PR sin poder corregir nada; fallar por CRITICAL con arreglo fuera de `linux-libc-dev` da hoy **0 fallos** en las tres imágenes propias y deja los HIGH visibles en *code scanning* y en el escaneo programado.
- **B1 frente a B2 y B3.** Con *digest*, lo desplegado es exactamente lo probado, la reversión es volver a un commit y la etiqueta `sha-<7>` sigue siendo inmutable. B2 sobrescribe `sha-<7>` con otro contenido (la reversión «al tag anterior» dejaría de significar algo) o inventa etiquetas que el *script* de purga no protege. B3 actualiza en producción lo que nadie probó en staging: contradice el circuito que pide el usuario.
- **C1 frente a C2-C4.** Los parches de seguridad de paquetes que no tocan la pila de contenedores (OpenSSL, glibc, OpenSSH...) llegan en horas sin esperar a la ventana; lo que puede romper el producto (Podman, `crun`, `container-selinux`, `selinux-policy`, `systemd`, `cloud-init`, `passt`) pasa por la ventana con comprobaciones. C3 reinicia sin nadie mirando un host cuyo arranque ya falló una vez por `cloud-init` (`CS-04`). C4: no hay constancia de contenido gratuito de kpatch para AlmaLinux y KernelCare es de pago (**no verificado**); con una sola máquina de staging y reinicio de 1-2 min no compensa.
- **D1.** Sin ventana fija, los parches que exigen reinicio se acumulan; con ella, el usuario sabe cuándo se le pedirá `sudo` y la ventana deja evidencia reutilizable para producción.

## 5 · Consecuencias

**Buenas.** Detección continua de todo lo que corre, no solo de los *lockfiles*; cada parche de imagen es un commit con CI, reversión por etiqueta y trazabilidad; los hallazgos sin arreglo no se olvidan (caducan); el host recibe seguridad rápida sin tocar la pila de contenedores fuera de la ventana; la ventana de staging ensaya la de producción.

**Malas.** Más PR de Renovate (uno semanal de *digests*); más ruido visible en *code scanning*; cada `sha-<7>` de base cambia aunque el código no cambie; trabajo semanal del usuario (ventana con `sudo`); `.trivyignore` con caducidad exige mantenimiento.

**Riesgos.** (1) Si una imagen de origen tarda en reconstruirse con los parches de su distribución, el *digest* no cambia y el parche no llega: lo detecta el escaneo programado; si ocurre de forma sostenida, se añade una capa de actualización de paquetes en el `Containerfile` (`OPEN-090-05`). (2) Una actualización de `cloud-init` podría restaurar el `bootcmd`: la lista posterior lo comprueba siempre. (3) Excluir paquetes de `dnf-automatic` puede retrasar un parche urgente de esos paquetes hasta la ventana; un CVE crítico en ellos abre ventana extraordinaria. (4) La imagen anterior de staging puede purgarse de GHCR: la lista previa comprueba que existe en el almacén local del host y E3 propone protegerla también en la purga.

**Requisitos afectados.** Aporta a `RNF-MANT-004` y `RSEC-PENT-004`. No cumple `RARQ-DEP-012` (preproducción con datos anonimizados), que sigue sin cubrir (`ADR-080 §3.2`). El reinicio del host de producción con un solo host **contradice `RARQ-DEP-001`**: no se resuelve aquí (`OPEN-090-02`).

**Lo que no se ha verificado** (cada punto lleva su marca en el anexo): versión de `dnf` en AlmaLinux 10.2 y nombre exacto de los paquetes `dnf-automatic`/`needs-restarting`; opciones `excludepkgs` y `reboot` en `automatic.conf` de esa versión; disponibilidad de kpatch en AlmaLinux; si `/etc/cloud/cloud.cfg` es `%config(noreplace)`; si Euronodes ofrece instantáneas; etiquetas `-trixie` de FrankenPHP; soporte de Renovate para ficheros Quadlet; frecuencia de reconstrucción de las imágenes de origen; respuesta del API JSON de versiones de php.net desde el CI.

## 6 · Alternativas descartadas

- **Añadir `dependabot.yml`**: duplicaría PR de Renovate (decisión ya tomada).
- **Fallar el CI por HIGH**: rojo permanente sin acción posible (cifras de `§1.2`).
- **Reconstrucción programada sin commit (B2)** y **`podman auto-update` (B3)**: ver `§4`.
- **Actualización completa con reinicio automático del host (C3)**: ver `§4`.
- **Parcheo del núcleo en caliente (C4)**: coste o disponibilidad no verificados frente a un reinicio corto en ventana.
- **Cambiar `postgres:18` por la variante Alpine para quitar `gosu`**: cambia la libc y con ella la ordenación de texto (*collation*) de los índices; riesgo de corrupción lógica de índices para quitar un hallazgo que la imagen oficial corregirá al reconstruirse.
- **Regla `sudoers` para que la cuenta de despliegue ejecute `dnf` o el reinicio**: amplía lo que una clave SSH robada puede hacer como root; se deja como opción no recomendada (`OPEN-090-09`).
- **Herramientas de monitorización (Uptime Kuma, Sentry...)**: `ADR-080` ya las descartó por ahora; el humo de la ventana no las necesita.
