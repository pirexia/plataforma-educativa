# ADR-087 · Mailpit opcional en staging

**Estado**: **ACEPTADA** (2026-11-16; decisión expresa del usuario, **contra la recomendación de `architect`**, cuya objeción consta en `§3`). La condición de retirada (`§6`, `OPEN-087-01`) queda pendiente de decisión del usuario.
**Fecha**: 2026-11-16
**Origen**: decisión del usuario del 2026-11-16 durante el tramo 3 de `0.10e-c` (issue #685); implementada en el commit `8048969` antes de este ADR.
**Afecta a**: `infra/quadlet/mailpit.container`, `infra/install.sh` (`--with-mailpit`), `infra/install-lib.sh` (`render_units`), `infra/test-install-env.sh`; entorno del VPS de staging. **No afecta** a producción, a `apps/api` ni a las plantillas `*.env.example`.
**Sustituye en parte a**:
- `ADR-080 §3.6`, viñeta «Correo», **solo** la frase «No se añade Mailpit»: Mailpit se añade como herramienta **opcional** de staging, con el alcance de `§2`. El resto de la viñeta (staging con `APP_ENV=production`, SMTP real al proveedor de `OPEN-079-01`, el *guard* no se toca) **sigue vigente**.
- `ADR-080 §10.2`, `OPEN-STG-07`, **solo** en lo que toca al «buzón de pruebas»: se decide aquí. Sentry y el monitor de salud siguen en «no por ahora».

`ADR-080` no se modifica (es inmutable); esta discrepancia se registra aquí.

**No sustituye**: `CS-19` de `ADR-080 §6.2`, que sigue exigiendo el proveedor real (`§4`).

---

## 1 · Contexto

1. Staging corre con `APP_ENV=production` y `MailEnvironmentGuard` (`apps/api/app/Support/Mail/MailEnvironmentGuard.php`) aborta el arranque con `MAIL_MAILER=log` o `array` fuera de `local`/`testing` (`ADR-080 §3.6`). Staging necesita, por tanto, un SMTP alcanzable.
2. El proveedor de correo real (`OPEN-079-01`: Scaleway, Brevo de reserva) **no está contratado ni configurado**. Según la nota viva de `0.10e-c` en `memory.md`, el VPS usa hoy un valor provisional (`127.0.0.1:2525`) a la espera del relay.
3. Sin un SMTP que acepte el correo, los flujos que envían correo (restablecer contraseña, invitaciones, entre ellas la de `bo:create-admin`, `SYSADMIN.md`) no se pueden ejercitar en staging.
4. `ADR-080 §3.6` decidió «No se añade Mailpit» por tres razones: no está en `compose.yaml`, sería una pieza nueva y un buzón de pruebas accesible desde Internet es otra superficie que proteger.

## 2 · Decisión

Mailpit se instala en staging como **herramienta opcional**, con estas condiciones, todas ya implementadas en `8048969` y que este ADR fija como contrato:

| Condición | Cómo se cumple |
|---|---|
| **Opt-in** | Solo con `install.sh <tag> --with-mailpit`. Sin la opción, `render_units` no renderiza la unidad ni se anuncia; una unidad ya instalada no se borra. |
| **Nunca en producción** | La unidad lo dice en su cabecera; ninguna plantilla `*.env.example` (que son las de producción) apunta a Mailpit; la configuración `MAIL_HOST=mailpit`/`MAIL_PORT=1025` se pone **a mano** en el entorno del VPS de staging. |
| **SMTP no publicado** | El puerto 1025 solo es alcanzable desde la red de contenedores (`plataforma.network`). |
| **Interfaz solo en loopback** | `PublishPort=127.0.0.1:8025:8025`; sin router en Traefik; se consulta por túnel SSH. Responde a la tercera razón de `ADR-080 §3.6`: el buzón **no** es accesible desde Internet. |
| **Sin volumen** | El correo capturado vive en memoria y se pierde al reiniciar: no se acumulan en disco enlaces de restablecimiento ni invitaciones. |
| **Imagen fijada** | `docker.io/axllent/mailpit:v1.33.1@sha256:aef2…61ce` (versión y *digest*), como `ADR-076 §2.2` hace con Garage. |
| **Sin relay de salida** | La unidad no configura reenvío: nada sale de staging hacia buzones reales. Activar el reenvío de Mailpit queda **prohibido** por este ADR (convertiría la herramienta en un relay sin SPF/DKIM). |

## 3 · Objeción de `architect` (registrada, `CLAUDE.md §0`)

`architect` recomendó **no** instalarlo, por dos motivos que se mantienen:

1. **No cierra `CS-19`.** `CS-19` exige que un correo transaccional llegue a un buzón de prueba **a través del proveedor de `OPEN-079-01`** con SPF, DKIM y DMARC en `pass` según sus cabeceras `Authentication-Results`. Mailpit captura en el propio host: no firma DKIM, no pasa por DNS ni por un receptor que evalúe SPF/DMARC, y no genera esas cabeceras. Un correo visto en Mailpit **no es evidencia** de `CS-19`.
2. **Es trabajo a retirar.** Cuando el relay esté en uso, Mailpit sobra para el propósito de `ADR-080 §3.6` y deja una unidad, una opción de `install.sh` y una imagen que mantener.

El usuario decidió instalarlo igualmente. Discrepar no es bloquear: se ejecuta y queda aquí.

## 4 · Alcance: qué cubre y qué no

**Cubre** (mientras no haya relay):
- Que la API y los *workers* arranquen en staging con `MAIL_MAILER=smtp` y `APP_ENV=production` sin depender del proveedor.
- Ver el contenido, el idioma y los enlaces de los correos transaccionales (restablecer contraseña, invitaciones) y completar a mano flujos que los necesitan, como el canje de la invitación de `bo:create-admin`.
- Que staging no envíe correo a direcciones reales por error.

**No cubre**:
- **`CS-19`**, que **sigue pendiente del relay real** (`OPEN-079-01`). `0.10e` no se cierra con `[x]` por Mailpit (`ADR-080 §6.2`: «un criterio que no se pueda ejecutar se deja escrito como no verificado»).
- SPF, DKIM, DMARC, rebotes, quejas ni límites del proveedor (`ADR-079 §4.9`).
- Entregabilidad. Nota para cuando se configure el relay: la zona `adagiox.eu` publica en el ápice SPF `-all` y DMARC `p=reject` (`ADR-086 §4`); el subdominio de envío de staging tendrá que publicar su propio SPF y DKIM alineados, o el correo se rechazará, que es lo que se quiere probar en `CS-19`.
- El E2E automatizado (`CS-17`): Playwright corre fuera del host y la interfaz solo escucha en loopback. Usar la API de Mailpit desde el E2E exigiría un túnel y es otra decisión.

## 5 · Dependencia nueva (`CLAUDE.md §1`, `RNF-MANT-007`)

| Comprobación | Resultado | Cómo |
|---|---|---|
| **Interfaz propia** | **Cumple**: el contrato es SMTP a través del *mailer* `smtp` de Laravel (`MAIL_*`). La aplicación no conoce Mailpit; sustituirlo por el relay es cambiar `MAIL_HOST`/`MAIL_PORT`/credenciales, sin tocar código. | Lectura de `mailpit.container` y de `plataforma.env.example` |
| Licencia | MIT, según el conocimiento previo del proyecto Mailpit (`axllent/mailpit`). **SV** en esta sesión: la imagen no está en local (`podman image inspect` falla) y no se consultó la red. | — |
| Mantenimiento activo y frecuencia de *releases* | Proyecto activo con *releases* frecuentes según el conocimiento previo. **SV** en esta sesión, por lo mismo. | — |
| Actualizaciones | `renovate.json` **no** tiene un gestor que lea `infra/quadlet/*.container`: la imagen no se actualizará sola. Si el gestor integrado de Renovate cubre ficheros Quadlet es **SV**. | Lectura de `renovate.json` |

Al estar fijada por *digest* y no entrar en producción, el riesgo de la dependencia es bajo; aun así, la licencia y el mantenimiento se comprueban contra el repositorio de origen **antes de la próxima actualización de la imagen**.

## 6 · Condición de retirada

Cuando el relay de `OPEN-079-01` esté configurado en staging y `CS-19` haya pasado, **se decide** si Mailpit se retira (borrar la unidad, la opción `--with-mailpit`, su prueba en `test-install-env.sh` y su mención en `RUNBOOK.md`/`SYSADMIN.md`) o se mantiene como herramienta de captura para pruebas manuales. `OPEN-087-01`.

## 7 · Consecuencias

- **Buenas**: staging arranca y ejercita los flujos con correo sin esperar al contrato del proveedor; ningún correo de staging llega a terceros.
- **Malas**: una unidad y una opción más en `install.sh`; una imagen fuera del alcance de Renovate; el riesgo de que «se ve en Mailpit» se tome como `CS-19` cumplido.
- **Reversión**: `systemctl --user disable --now mailpit.service`, borrar la unidad del directorio de Quadlet y devolver `MAIL_*` al valor anterior. Nada del producto depende de Mailpit.
- **Superficie local**: la interfaz en `127.0.0.1:8025` no tiene autenticación; cualquier proceso del host puede leer los enlaces de restablecimiento de las cuentas sintéticas. Es el mismo supuesto de host de un solo operador que `ADR-088 §5.1`.

## 8 · Alternativas descartadas

- **No instalar nada y esperar al relay** (recomendación de `architect`): sin coste nuevo y sin trabajo a retirar. Descartada por el usuario, que quiere usar staging antes de tener el proveedor.
- **Mailpit con router en Traefik**: interfaz accesible desde Internet; es justo la superficie que `ADR-080 §3.6` rechazó.
- **Mailpit con volumen**: conserva correos con enlaces válidos entre reinicios sin necesidad.
- **`MAIL_MAILER=log`**: bloqueado por `MailEnvironmentGuard`; el *guard* no se toca (`ADR-080 §3.6`).

## 9 · Preguntas abiertas

| Id | Pregunta | **Recomendada** | Decide |
|---|---|---|---|
| `OPEN-087-01` | ¿Se retira Mailpit cuando `CS-19` pase con el relay real? | **Retirarlo**: deja de aportar lo que justificó su entrada y elimina una imagen sin seguimiento | Usuario |

## 10 · No verificado

- Licencia, mantenimiento y frecuencia de *releases* de Mailpit (sin red ni imagen local).
- Que la unidad arranque con Quadlet real: el commit `8048969` declara que no se arrancó (solo `test-install-env.sh` y `bash -n`).
- Si Renovate cubre los ficheros Quadlet con su gestor integrado.
