# ADR-090 · Gestión de vulnerabilidades y versiones, parcheo de contenedores y del host, y ventana de mantenimiento

**Estado**: **ACEPTADA** (2026-11-17, el usuario, con `OPEN-090-01` a `-12` decididas: anexo `§6.1` y `§7` de este ADR). Redactada como **PROPUESTA** el mismo día. Nada de este ADR está implementado: se ejecuta en el paso `0.10g` (`0.10g-a`..`-g`, anexo `§5`). `OPEN-090-11` (copia de los secretos del VPS) queda **aceptada en alcance, pendiente de especificación** (`OPEN-090-20` a `-25`). Las preguntas de E1 (`OPEN-090-13` a `-19`) se resuelven al empezar E1 y no bloquean la aceptación.
**Fecha**: 2026-11-17
**Origen**: objetivo del usuario del 2026-11-17: plataforma segura, parcheada y en últimas versiones, comprobando que todo sigue funcionando; todo cambio se prueba primero en desarrollo, después en staging y, cuando exista, en producción.
**Anexo**: [`ADR-090-anexo-plan-parcheo-y-mantenimiento.md`](ADR-090-anexo-plan-parcheo-y-mantenimiento.md) (procedimientos, listas de comprobación, entregas y preguntas abiertas). El encargo pedía el plan en `docs/proceso/`; el ámbito de escritura de `architect` es `docs/adr/`, así que se deja como anexo del ADR y se señala para que la sesión decida si lo mueve.
**No sustituye** ningún ADR. Se apoya en `ADR-037` (imágenes, Quadlet, retención de GHCR), `ADR-080` (staging destruible y sin datos reales, promoción `develop` → `staging` → `main`), `ADR-087`, `ADR-088` y `ADR-061` (subida mayor de PostgreSQL, precedente de cambio de versión mayor con ADR propio).
**Fuera de alcance**: el plan de copias de seguridad (`REQ-BKP`, `OPEN-10`), **aparcado por decisión del usuario**. Este ADR señala dónde dependería de él (anexo `§4.8`) y no lo diseña.

---

## 1 · Contexto

Hechos verificados el 2026-11-17 (no se reabren aquí):

1. **Detección actual.** Renovate (app de Mend, `renovate.json`: PR agrupados, horario «before 6am on monday», `vulnerabilityAlerts` «at any time», `constraints npm <12`, *Dependency Dashboard* por `config:recommended`) y Dependabot **solo alertas y security updates** (sin `dependabot.yml`, por decisión: no duplicar a Renovate; ya abrió #732 y #733). Trivy en CI (`dependency-scan.yml`, `aquasecurity/trivy-action@v0.36.0`) **solo en modo `fs`** sobre `composer.lock` y `package-lock.json`, `HIGH,CRITICAL`, `ignore-unfixed`, y **solo en `pull_request`**: no escanea imágenes ni hay escaneo programado. `.trivyignore` con caducidad ya en `develop` (#735, mezclado el 2026-11-17): una entrada, `CVE-2026-118664 exp:2026-12-17` (`decode-uri-component`, solo marcado del *lockfile*), con *issue* de seguimiento #734 que caduca el 2026-12-17. Es el primer caso del mecanismo de `§3`, punto 1.
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

## 3 · Decisión

1. **Política** (anexo `§1`): ámbitos con su fuente de detección; plazos objetivo por severidad (**estimación**: Crítica 3 días hasta staging y 7 hasta producción; Alta 30; Media 90; Baja en la reconstrucción periódica); circuito único desarrollo → PR con CI → `develop` → staging (ventana, humo) → `main` → producción, con criterios de promoción; **todo hallazgo aceptado sin arreglo lleva *issue* propio con etiqueta `vuln-seguimiento` y una entrada en el fichero de exclusiones de Trivy con fecha de caducidad**, de modo que al caducar el escaneo vuelve a fallar y obliga a revisarlo.
2. **A1**: Trivy de imagen en `build-images.yml` (falla solo por CRITICAL con arreglo, excluido `linux-libc-dev` con motivo escrito) y escaneo programado diario de las imágenes **tal como están desplegadas en `staging` y `main`** y de las de terceros fijadas, con SARIF en *code scanning*. Para el PHP embebido, comprobación propia de versión (`PHP_VERSION` de la imagen contra la última 8.4.x publicada por php.net) y vigilancia de avisos oficiales (anexo `§2.3`).
3. **B1**: imágenes base y de terceros por etiqueta + *digest* en `Containerfile`, Quadlet, `install-lib.sh` y `compose*.yaml`; Renovate las actualiza en un grupo semanal; las subidas mayores de PostgreSQL quedan fuera (ADR propio, como `ADR-061`). «Bajo demanda por CVE crítico» = forzar ese PR desde el *Dependency Dashboard* o a mano.
4. **C1** (`OPEN-090-03`): la configuración la aplica **el usuario** (tiene `sudo`); OpenSSH entra en `dnf-automatic` (`OPEN-090-08`). Claude usa una clave **sin `sudo` dedicada a mantenimiento**: lectura, despliegue de contenedores sin privilegios, humo y registro; todo lo que exige root lo ejecuta el usuario (`OPEN-090-09`). El reinicio, si `needs-restarting -r` lo pide, en la ventana. `cloud-init`: fichero `/etc/cloud/cloud.cfg.d/99-plataforma.cfg` sin `bootcmd` y comprobación en cada ventana (`OPEN-090-07`).
5. **D1**: ventana semanal de staging, **miércoles 20:00-21:00 Europe/Madrid, 60 min máximo** (`OPEN-090-01`), con lista previa, pasos, lista posterior, humo automatizado contra la API real, criterios *go/no-go* y de reversión, y un *issue* por ventana como registro. El humo evoluciona hacia `CS-17`. **Producción: ninguna ventana** hasta resolver `OPEN-11` (alojamiento), que decidirá con cuántos hosts se cumple `RARQ-DEP-001` (`OPEN-090-02`).
6. **Debian *trixie***: la imagen de la API y la etapa de construcción de la web pasan a *trixie* en E5, **después** de E1 y E2 (`OPEN-090-06`).
7. **Umbral de imagen** (`OPEN-090-04`): CRITICAL con arreglo, excluido `linux-libc-dev`; HIGH solo informa; revisión a las 4 semanas. Sin capa `apt`/`apk upgrade` en las imágenes propias; se decide con 4 semanas de datos (`OPEN-090-05`). Acciones de CI fijadas por SHA en E2 (`OPEN-090-10`). Plazos de `§3`, punto 1, aceptados para staging y revisados antes de producción (`OPEN-090-12`).
8. **Secretos del VPS** (`OPEN-090-11`): **no** se usan instantáneas del proveedor (coste). Se hará una **copia cifrada y restaurable, guardada en S3, solo de los secretos y la configuración local del VPS** que no están en Git (ficheros de `~/.config/plataforma/` y similares); **sin datos de base de datos** (los de staging son sintéticos y se recrean con `REQ-SEED`). Aceptada en alcance, **pendiente de especificación** (anexo `§6.3`, `OPEN-090-20` a `-25`; entrega `0.10g-g`).
9. **Entregas** (anexo `§5`, paso `0.10g`): E1 escaneo de imágenes y seguimiento; E2 fijado por *digest* y Renovate; E3 humo, listas de comprobación y registro; E4 host; E5 *trixie*; E6 opcional, imagen de la API sin cabeceras ni `-dev`; E7 copia de los secretos del VPS. Cada una ≤ 3 tramos de `implementer` y **cada una deja la comprobación que demuestra que su control falla cuando debe** (norma del usuario del 2026-11-17).

## 4 · Motivo

- **A1 frente a A2-A4.** Trivy ya está en el CI (sin dependencia nueva), escanea imágenes, binarios Go y paquetes del sistema, y su base de datos de vulnerabilidades se actualiza a diario: sin escaneo programado, un CVE publicado después de construir la imagen no aparece nunca. SARIF en *code scanning* (gratuito en repositorio público) da estado, deduplicación y cierre automático sin escribir un gestor de *issues*. A3 añade una herramienta con su propio ruido sin cubrir el hueco del PHP embebido, que ninguna de ellas cubre.
- **Umbral.** El escaneo real de staging da 317 HIGH en la API, de los que los 123 con arreglo son `linux-libc-dev` (cabeceras del núcleo, que no se ejecutan: el núcleo es el del host) y el resto no tiene arreglo en Debian. Fallar por HIGH bloquearía todos los PR sin poder corregir nada; fallar por CRITICAL con arreglo fuera de `linux-libc-dev` da hoy **0 fallos** en las tres imágenes propias y deja los HIGH visibles en *code scanning* y en el escaneo programado.
- **B1 frente a B2 y B3.** Con *digest*, lo desplegado es exactamente lo probado, la reversión es volver a un commit y la etiqueta `sha-<7>` sigue siendo inmutable. B2 sobrescribe `sha-<7>` con otro contenido (la reversión «al tag anterior» dejaría de significar algo) o inventa etiquetas que el *script* de purga no protege. B3 actualiza en producción lo que nadie probó en staging: contradice el circuito que pide el usuario.
- **C1 frente a C2-C4.** Los parches de seguridad de paquetes que no tocan la pila de contenedores (OpenSSL, glibc, OpenSSH...) llegan en horas sin esperar a la ventana; lo que puede romper el producto (Podman, `crun`, `container-selinux`, `selinux-policy`, `systemd`, `cloud-init`, `passt`) pasa por la ventana con comprobaciones. C3 reinicia sin nadie mirando un host cuyo arranque ya falló una vez por `cloud-init` (`CS-04`). C4: no hay constancia de contenido gratuito de kpatch para AlmaLinux y KernelCare es de pago (**no verificado**); con una sola máquina de staging y reinicio de 1-2 min no compensa.
- **D1.** Sin ventana fija, los parches que exigen reinicio se acumulan; con ella, el usuario sabe cuándo se le pedirá `sudo` y la ventana deja evidencia reutilizable para producción.

## 5 · Consecuencias

**Buenas.** Detección continua de todo lo que corre, no solo de los *lockfiles*; cada parche de imagen es un commit con CI, reversión por etiqueta y trazabilidad; los hallazgos sin arreglo no se olvidan (caducan); el host recibe seguridad rápida sin tocar la pila de contenedores fuera de la ventana; la ventana de staging ensaya la de producción.

**Malas.** Más PR de Renovate (uno semanal de *digests*); más ruido visible en *code scanning*; cada `sha-<7>` de base cambia aunque el código no cambie; trabajo semanal del usuario (ventana con `sudo`); `.trivyignore` con caducidad exige mantenimiento.

**Riesgos.** (1) Si una imagen de origen tarda en reconstruirse con los parches de su distribución, el *digest* no cambia y el parche no llega: lo detecta el escaneo programado; si ocurre de forma sostenida, se añade una capa de actualización de paquetes en el `Containerfile` (`OPEN-090-05`). (2) Una actualización de `cloud-init` podría restaurar el `bootcmd`: la lista posterior lo comprueba siempre. (3) Excluir paquetes de `dnf-automatic` puede retrasar un parche urgente de esos paquetes hasta la ventana; un CVE crítico en ellos abre ventana extraordinaria. (4) La imagen anterior de staging puede purgarse de GHCR: la lista previa comprueba que existe en el almacén local del host y E3 propone protegerla también en la purga. (5) **Riesgo aceptado temporalmente por el usuario (2026-11-17)**: mientras no exista la copia de `OPEN-090-11`, los secretos de `~/.config/plataforma/` **solo existen en el VPS**; si el VPS se pierde o hay que reaprovisionarlo, se regeneran o se vuelven a emitir. Cuando exista, «copia de secretos hecha y verificada» es requisito previo de toda ventana con reinicio (anexo `§4.3`). (6) Hasta la primera promoción a `main`, el escaneo programado no corre (`OPEN-090-19`).

**Requisitos afectados.** Aporta a `RNF-MANT-004` y `RSEC-PENT-004`. No cumple `RARQ-DEP-012` (preproducción con datos anonimizados), que sigue sin cubrir (`ADR-080 §3.2`). El reinicio del host de producción con un solo host **contradice `RARQ-DEP-001`**: no se resuelve aquí (`OPEN-090-02`).

**Lo que no se ha verificado**: lista completa en el anexo `§7`; cada punto lleva su marca **(NV)** y se resuelve en la entrega que lo necesita.

**Contacto con `ADR-080`.** La copia de secretos de `OPEN-090-11` **no** contradice `ADR-080`: `§3.3` dice que staging no prueba «copias y restauración (`OPEN-10`, `0.10d`)», es decir, las copias de **datos** del producto (`REQ-BKP`), y esta copia no es un ensayo de ellas; además `§3.2`, regla 3, exige que «nada vive solo allí», que hoy los secretos incumplen y la copia corrige. No hace falta ADR que sustituya a `ADR-080`. La frase de `CLAUDE.md §9` «en él no se ensayan copias ni restauración (`ADR-080`)» resume `§3.3` y puede leerse como prohibición general: se señala a la sesión para que la precise; no se cambia aquí.

## 6 · Alternativas descartadas

- **Añadir `dependabot.yml`**: duplicaría PR de Renovate (decisión ya tomada).
- **Fallar el CI por HIGH**: rojo permanente sin acción posible (cifras de `§1.2`).
- **Reconstrucción programada sin commit (B2)** y **`podman auto-update` (B3)**: ver `§4`.
- **Actualización completa con reinicio automático del host (C3)**: ver `§4`.
- **Parcheo del núcleo en caliente (C4)**: coste o disponibilidad no verificados frente a un reinicio corto en ventana.
- **Cambiar `postgres:18` por la variante Alpine para quitar `gosu`**: cambia la libc y con ella la ordenación de texto (*collation*) de los índices; riesgo de corrupción lógica de índices para quitar un hallazgo que la imagen oficial corregirá al reconstruirse.
- **Regla `sudoers` para que la cuenta de despliegue ejecute `dnf` o el reinicio**: amplía lo que una clave SSH robada puede hacer como root (`OPEN-090-09`).
- **Instantáneas del proveedor para proteger los secretos y el host**: coste; el usuario las descarta (`OPEN-090-11`).
- **Copia de PostgreSQL o WAL de staging**: datos sintéticos que se recrean con `REQ-SEED`; no entra (`OPEN-090-11`).
- **Herramientas de monitorización (Uptime Kuma, Sentry...)**: `ADR-080` ya las descartó por ahora; el humo de la ventana no las necesita.

## 7 · Decisiones del usuario (2026-11-17)

| Pregunta | Decisión |
|---|---|
| `OPEN-090-01` | Semanal, miércoles 20:00-21:00 Europe/Madrid, 60 min máx. (recomendada) |
| `OPEN-090-02` | Se decide al resolver `OPEN-11`; entretanto, ninguna ventana de producción (recomendada) |
| `OPEN-090-03` | C1: `dnf-automatic` solo seguridad, sin reinicio, con exclusiones (recomendada) |
| `OPEN-090-04` | Falla solo por CRITICAL con arreglo, excluido `linux-libc-dev`; HIGH informa; revisión a las 4 semanas (recomendada) |
| `OPEN-090-05` | Sin capa `apt`/`apk upgrade` ahora; se decide con 4 semanas de datos (recomendada) |
| `OPEN-090-06` | Debian *trixie* en E5, tras E1 y E2 (recomendada) |
| `OPEN-090-07` | `cloud.cfg.d/99-plataforma.cfg` sin `bootcmd` + comprobación en cada ventana; ticket opcional a Euronodes (recomendada) |
| `OPEN-090-08` | OpenSSH dentro de `dnf-automatic` (recomendada) |
| `OPEN-090-09` | Clave de Claude sin `sudo` dedicada a mantenimiento; el usuario ejecuta lo que exige root (recomendada) |
| `OPEN-090-10` | Acciones de CI fijadas por SHA en E2 (recomendada). Dato verificado: el compromiso de `aquasecurity/trivy-action` fue real (19-20 de marzo de 2026, GHSA-69fq-xp46-6x23 / CVE-2026-33634: etiquetas anteriores a `0.35.0` redirigidas por *force-push* a un *payload* que robaba credenciales del *runner*); **`v0.36.0` no está afectada** (publicada el 2026-04-22, inmutable y firmada); `dependency-scan.yml` la usa desde su primer commit (`c46d4cb`, 2026-08-14) con solo `contents: read` y ningún secreto. Fuentes: <https://github.com/aquasecurity/trivy/discussions/10425>, <https://www.aquasec.com/blog/trivy-supply-chain-attack-what-you-need-to-know/> |
| `OPEN-090-11` | **Distinta de la recomendada.** Sin instantáneas del proveedor (coste). Copia cifrada y restaurable, en S3, **solo de los secretos y la configuración local del VPS** no versionados; sin datos de base de datos. Aceptada en alcance, pendiente de especificación (`OPEN-090-20` a `-25`) |
| `OPEN-090-12` | Plazos aceptados para staging; se revisan antes de producción (recomendada) |

