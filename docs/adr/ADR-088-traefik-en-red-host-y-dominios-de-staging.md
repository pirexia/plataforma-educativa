# ADR-088 · Traefik en red `host` con *backends* en loopback, sustituciones de `install.sh` y dominios de staging

**Estado**: **PROPUESTA** (2026-11-16). Parte ya decidida por el usuario: los dominios de staging (`§4`, «opción A» del 2026-11-16). La red `host` está implementada y verificada a mano en staging (tramos 1 y 2 de `0.10e-c`, PR #689 y #692) **sin ADR previo**; este ADR la recoge para que el usuario la acepte o la rechace, junto con el tratamiento de los dos riesgos Medios de `security-reviewer` (`§5`).
**Fecha**: 2026-11-16
**Origen**: tramos 1 y 2 de `0.10e-c` (issues #685 y #690); nota viva de `0.10e-c` en `memory.md`; comentario de cabecera de `infra/quadlet/traefik.container`; `RUNBOOK.md` («Traefik en red host»).
**Afecta a**: `infra/quadlet/traefik.container`, `api@.container`, `web.container`, `infra/install-lib.sh` (`render_units`), `infra/test-install-env.sh`; el host de staging (sysctl); `TRUSTED_PROXIES`; la especificación de `0.10e-d`.
**Al aceptarse, sustituye en parte a** (hasta entonces, la desviación está implementada pero sin respaldo de ADR):
- `ADR-080 §3.7`, fila `plataforma-edge`: Traefik **no** pertenece a ninguna red de contenedores; está en la red del host (`§2.1`). La premisa «`TRUSTED_PROXIES` pasa a ser esta subred, donde no hay *workers*» no se puede cumplir tal cual y se replantea en `0.10e-d` (`§3`).
- `ADR-037 §6.1`, «lo único que difiere [...] (c) el *tag* de la imagen [...] con un `sed` de una línea»: la lista de sustituciones pasa a ser la lista cerrada de `§2.3`.
- `ADR-028 §4` y `ADR-037 §6.2`, «ninguna IP escrita a mano», **solo** para la dirección de loopback `127.0.0.1` de `PublishPort` (`§2.2`): no es una IP de contenedor ni de servicio, es la interfaz del host.

`ADR-080`, `ADR-037` y `ADR-028` no se modifican (son inmutables).
**No sustituye**: `ADR-046 §4.4` / `RN-BO-49`, que este ADR aplica (`§4`). No cierra `OPEN-08`.

---

## 1 · Contexto

1. Con Podman **sin privilegios** (`OPEN-STG-05`, decidida el 2026-11-14) y Traefik en una red *bridge* con `PublishPort`, el reenvío de puertos (*rootlessport*) **enmascara la IP de origen**: Traefik ve una dirección de la red de contenedores (`10.89.10.x`), no la del cliente. Comprobado en el VPS de staging (comentario de `traefik.container`). Es el riesgo B1 de `docs/modulos/REQ-FAM-UNIT/operacion.md §13.3`.
2. Consecuencia sin síntoma: la lista blanca del *backoffice* (`ipallowlist` de Traefik, `RN-BO-06`/`-07`) y la IP de auditoría (`admin_action_logs`, `audit_logs`) comparan contra una dirección interna. `ADR-080 §3.3` lo listaba como lo que staging tenía que probar (`CS-10`).
3. `ADR-080 §3.7` y `ADR-037` suponían Traefik dentro de una red de contenedores.
4. Para generar las unidades de staging, `install.sh` sustituye ya doce *tokens*, no solo `__TAG__` (`render_units` en `infra/install-lib.sh`).
5. `RN-BO-49` (`ADR-046 §4.4`) prohíbe que el *host* del *backoffice* sea subdominio de `TENANCY_BASE_DOMAIN`, porque `TenantHost::slugFrom()` tomaría su etiqueta izquierda como *slug* de un centro.

## 2 · Decisión

### 2.1 · Traefik en red `host`

`traefik.container` usa `Network=host`, sin `PublishPort`, con `--providers.docker.useBindPortIP=true`. Enlaza 80 y 443 directamente en el host (lo que exige `net.ipv4.ip_unprivileged_port_start=80`, `§5.2`) y ve la IP real del cliente. El *entrypoint* interno del *ping* escucha en `127.0.0.1:8080` y no se abre en el cortafuegos.

Sigue vigente de `ADR-028`: Traefik descubre los *backends* por el socket de Podman, no por una IP cacheada (`useBindPortIP` usa el puerto publicado que el socket informa).

### 2.2 · *Backends* publicados solo en loopback

`api@` publica en `127.0.0.1:1800<instancia>` (la fórmula vale para las instancias 1 a 9) y `web` en `127.0.0.1:18080`. En *rootless*, la IP del contenedor no es alcanzable desde el host, así que Traefik llega por el puerto publicado. Los *backends* no son alcanzables desde fuera del host.

### 2.3 · Sustituciones de `install.sh`: lista cerrada

Se admite el mismo `sed` de `ADR-037 §6.1` con esta lista **cerrada** de *tokens*: `__TAG__`, `__TENANCY_BASE_DOMAIN__`, `__TENANCY_BASE_DOMAIN_ESCAPED__`, `__TRAEFIK_IMAGE__`, `__ACME_EMAIL__`, `__ACME_CA_SERVER__`, `__TRAEFIK_ENV_FILE__`, `__ENV_DIR__`, `__POSTGRES_INIT_DIR__`, `__BACKOFFICE_HOST__`, `__BACKOFFICE_ALLOWED_IPS__`, `__TENANT_ALLOWED_IPS__`. Reglas:

- Sigue sin haber sistema de plantillado: ni condicionales, ni bucles, ni *includes* dentro de las unidades. La única lógica es la exclusión de una unidad opcional entera (`mailpit.container`, `ADR-087`).
- Cada *token* se valida en `install-lib.sh` antes de sustituirlo, y `test-install-env.sh` comprueba que no queda ninguno sin sustituir (hoy lo hace: `grep '__[A-Z_]*__'`).
- **`__TENANT_ALLOWED_IPS__` (`TENANT_ALLOWED_IPS`, #693; decidido por el usuario el 2026-11-16)**: lista blanca de IP del *ingress* para las rutas de los **centros** (`plataforma-api` y `plataforma-web`, middleware `tenant-ipallowlist`), aparte de la del *backoffice*. **Cerrada por defecto** (`INV-002`): `install.sh` **aborta** si falta o está vacía; no hay valor por defecto. Staging pone las IP permitidas; producción la pone a `0.0.0.0/0,::/0` de forma **explícita**, nunca por omisión. Misma validación de IP/CIDR (IPv4 e IPv6) que la del *backoffice*. Resuelve el hallazgo de `OPEN-STG-09` sobre las rutas de los centros. Sin probar aún con Traefik real (200/403 desde una IP permitida y otra no).
- **Un *token* nuevo exige actualizar este apartado con un ADR nuevo** o, si es una ruta o valor del mismo tipo que los de la lista, al menos su validación y su prueba. Si la lista crece hasta pedir lógica, es la señal de que hace falta otra decisión, no más `sed`.

Motivo: `ADR-037 §6.1` buscaba que no hubiera nada que mantener; doce sustituciones literales, validadas y probadas, siguen sin serlo. Un gestor de plantillas sí lo sería.

## 3 · Lo que esto rompe de `ADR-080 §3.7` y de `CS-10`

Con Traefik en red `host`, la conexión que recibe la API llega desde *rootlessport*, con una dirección de `plataforma-net` (`10.89.10.x`). Por eso `TRUSTED_PROXIES=10.89.10.0/24` sigue funcionando. Pero:

- Esa subred es la de **todos** los servicios de datos y colas. Cualquier contenedor de `plataforma-net` que llame a la API con un `X-Forwarded-For` falso cambia la IP registrada. **La segunda mitad de `CS-10` no puede pasar con la configuración actual.** No es una regresión: `CS-10` es de `0.10e-d` y la separación de redes no está hecha.
- La red `plataforma-edge` de `ADR-080 §3.7` pierde su miembro principal. Restringir `TRUSTED_PROXIES` a una sola dirección contradice «ninguna IP escrita a mano» y no se ha comprobado que la dirección de *rootlessport* sea estable (**SV**).

Se decide **replantearlo en la especificación de `0.10e-d`** (`OPEN-088-04`), no aquí: este ADR solo deja escrito que la premisa de `§3.7` ya no se cumple tal cual. Sigue siendo bloqueante para el primer dato real (`OPEN-STG-08`).

## 4 · Dominios (decididos por el usuario, 2026-11-16, «opción A»)

| Entorno | `TENANCY_BASE_DOMAIN` | `BACKOFFICE_HOST` | Certificados |
|---|---|---|---|
| Staging | `centros.adagiox.eu` | `panel.adagiox.eu` | Comodín `*.centros.adagiox.eu` (con el propio dominio base como SAN) y uno **aparte** para `panel.adagiox.eu`, ambos por DNS-01 (emitidos en el entorno de pruebas de Let's Encrypt; el paso a producción de ACME lo decide el usuario) |
| Producción | **Por decidir** (`OPEN-08`) | **Por decidir** (`OPEN-08`) | — |

- **`RN-BO-49` se cumple** en staging: `panel.adagiox.eu` no termina en `.centros.adagiox.eu`. `install-lib.sh` exige las dos variables, pero no comprueba el sufijo (`§9`).
- **`RN-BO-49` rige también en producción.** Si la plataforma se publica bajo `adagiox.com`, el *backoffice* no puede ser `<algo>.<TENANCY_BASE_DOMAIN>`. Recomendación (`OPEN-088-03`, depende de `OPEN-08`): repetir la forma de staging, con el dominio base de centros en un subdominio propio (p. ej. `centros.adagiox.com`) y el *backoffice* en otro hermano. Así el ápice y `www` siguen siendo de la landing (`ADR-072`, `ADR-086`) y nunca se interpretan como *slug* de un centro, lo que pasaría si `TENANCY_BASE_DOMAIN` fuera el propio `adagiox.com`.
- `OPEN-STG-02` pedía un dominio registrable distinto del comercial: `adagiox.eu` lo es respecto de `adagiox.com`, aunque en su ápice y `www` sirva las redirecciones de la landing (`ADR-086`). Los registros de staging son DNS sin proxy de Cloudflare (`ADR-080 §3.5`); el *token* de ACME tiene permiso de edición sobre toda la zona `adagiox.eu`, incluidas esas redirecciones.

## 5 · Riesgos Medios de `security-reviewer`

Ninguno de los dos afecta a un atacante remoto: los dos exigen ejecutar código en el host. Un proceso con el **mismo UID** que el despliegue no necesita ninguno de los dos, porque ya controla Podman (puede entrar en cualquier contenedor). El riesgo real es **otro UID local** (otro usuario, un demonio del sistema comprometido).

### 5.1 · *Backends* en `127.0.0.1` llamables sin pasar por Traefik

Cualquier proceso local puede llamar a `127.0.0.1:18001`/`18080` (y a `8025` de Mailpit y `8080` del *ping*) saltándose Traefik y su lista blanca. La lista blanca de la aplicación (`platform_ip_allowlist`) tampoco lo para: la conexión llega desde `10.89.10.x`, que está en `TRUSTED_PROXIES`, así que un `X-Forwarded-For` y un `Host` inventados se aceptan.

- **Staging: se acepta** (propuesta). Supuestos: host de un solo operador, sin más usuarios ni servicios, solo datos sintéticos (`ADR-080 §3.2`), acceso SSH solo por clave (`CS-09`).
- **Antes del primer dato real: no se acepta.** Opciones (`OPEN-088-01`):
  - (a) **Regla de `nftables` por UID** en la cadena de salida: solo el UID del despliegue puede conectar a los puertos de loopback de los *backends*. Barata, fuera del código, reversible; cubre exactamente el riesgo real (otro UID). Que *rootlessport* y Traefik conecten con ese UID es **SV**: se prueba en staging. **Recomendada.**
  - (b) Cabecera secreta que Traefik añade y la API exige: protege también contra el mismo UID, que no lo necesita; es código nuevo y un secreto más que rotar.
  - (c) Aceptarlo también en producción: no, con datos de menores.

### 5.2 · `net.ipv4.ip_unprivileged_port_start=80`

El ajuste es de todo el host: cualquier usuario local puede enlazar cualquier puerto desde el 80, incluidos 80 y 443. Si Traefik cae o se reinicia, otro proceso podría ocuparlos antes. Para servir HTTPS sin aviso le haría falta un certificado válido; en el 80 podría servir una redirección falsa a quien no haya recibido antes HSTS. `install.sh` no fija el ajuste: es un paso manual (`RUNBOOK.md`); si falta, Traefik no arranca, y eso **sí** se nota.

- **Staging: se acepta** (propuesta), con los mismos supuestos que `§5.1`.
- **Antes del primer dato real** (`OPEN-088-02`):
  - (a) **Redirección de `nftables`** del 80 y el 443 a dos puertos altos donde escucha Traefik, con el ajuste del sistema en su valor por defecto (1024). La redirección conserva la IP de origen (**SV** en este host, se prueba en staging). Quita la relajación de todo el host a cambio de dos reglas persistentes. **Recomendada.**
  - (b) Mantener el ajuste y aceptar el riesgo en un host de un solo operador.
  - (c) Traefik con privilegios: contradice `OPEN-STG-05` y pone como `root` justo el componente expuesto. Descartada.

### 5.3 · Otros, ya conocidos (no cambian con este ADR)

- Socket de Podman montado en Traefik: un Traefik comprometido controla el Podman del usuario. Lo resuelve el proxy de socket de `0.10e-d` (`ADR-037 §6.3`).
- Si algún día hay un proxy delante de Traefik, hay que añadir `forwardedHeaders.trustedIPs` en los dos *entrypoints* (comentario de `traefik.container`).

## 6 · Opciones consideradas para ver la IP del cliente

| Opción | A favor | En contra | Coste | Reversibilidad |
|---|---|---|---|---|
| **H1 · Red `host` + *backends* en loopback** (implementada) | Funciona y está verificada en staging (la lista blanca deja pasar una IP permitida y da 403 con otra); sin código nuevo | Riesgos de `§5`; aparta de `§3.7` | Hecho | Alta: volver a `Network=plataforma.network` y `PublishPort` |
| H2 · Red *bridge* con *rootlessport* | Lo que decían los ADR | Pierde la IP de origen (comprobado): lista blanca y auditoría inútiles sin síntoma | — | — |
| H3 · Traefik con red `pasta` | Podría conservar la IP de origen | Un contenedor en `pasta` no está a la vez en la *bridge*; llegar a los *backends* seguiría pasando por el host; comportamiento **SV** | Medio, sin probar | Alta |
| H4 · Traefik con privilegios | Puertos bajos sin ajustes | El componente expuesto como `root`; contradice `OPEN-STG-05` | Bajo | Alta |

**Recomendación: H1**, con las mitigaciones de `§5` antes del primer dato real. Es la única probada, no añade código y se deshace cambiando dos líneas por unidad.

## 7 · Consecuencias

- **Buenas**: la lista blanca del *backoffice* y la IP de auditoría funcionan en staging (primera mitad de `CS-10`, verificada a mano, no automatizada); sin cambios en `apps/`.
- **Malas**: la separación de redes de `0.10e-d` hay que rehacerla sobre esta base (`§3`); dos ajustes del host (sysctl y, si se aceptan, reglas de `nftables`) que `install.sh` no aplica y hay que documentar; la fórmula de puertos de `api@` limita a nueve instancias.
- **Requisitos afectados**: `RN-BO-06`/`-07`, `RN-BO-49`, `INV-003` (IP de auditoría), `RARQ-DEP-001` (segunda réplica, `0.10e-d`).

## 8 · Preguntas abiertas

| Id | Pregunta | **Recomendada** | Bloquea | Decide |
|---|---|---|---|---|
| `OPEN-088-01` | Mitigación de `§5.1` antes del primer dato real | **(a) `nftables` por UID**, probada antes en staging | Primer dato real | Usuario |
| `OPEN-088-02` | Mitigación de `§5.2` antes del primer dato real | **(a) redirección de `nftables`** a puertos altos | Primer dato real | Usuario |
| `OPEN-088-03` | Dominios de producción con `RN-BO-49` | **Base de centros en un subdominio propio de `adagiox.com` y *backoffice* en otro hermano**, nunca el ápice como base | Producción (`OPEN-08`) | Usuario |
| `OPEN-088-04` | Cómo se restringe `TRUSTED_PROXIES` sin red `plataforma-edge` (`§3`, `CS-10`) | Se decide en la especificación de `0.10e-d` | `CS-10`, primer dato real | `architect` + usuario |
| `OPEN-088-05` | Aceptar este ADR (red `host`, lista cerrada de `§2.3`, riesgos aceptados en staging) | **Aceptarlo** | Cierre de `0.10e-c` | Usuario |

## 9 · No verificado y hallazgos fuera del ámbito

- **SV**: que la dirección de *rootlessport* sea estable; que una regla de `nftables` por UID o una redirección conserven el funcionamiento y la IP de origen en este host; el comportamiento de `pasta` (H3).
- La verificación de la lista blanca en staging fue **manual**, no automatizada.
- `install-lib.sh` exige `TENANCY_BASE_DOMAIN` y `BACKOFFICE_HOST`, y su mensaje cita `RN-BO-49`, pero **no comprueba** que el segundo no termine en `.<primero>`. La API tiene una defensa en profundidad parcial (`BackofficeHostLabel`: ningún *slug* de centro puede coincidir con la etiqueta del *host* de plataforma), no una validación del sufijo. Se señala; no se corrige aquí.
