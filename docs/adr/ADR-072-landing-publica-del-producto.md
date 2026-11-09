# ADR-072 · Landing pública del producto (`adagiox.com`): sitio estático propio en `apps/landing`, alojado fuera de la plataforma

**Estado**: **ACEPTADA** (2026-11-09; el usuario aceptó el ADR con la recomendación de `architect` para `OPEN-072-01` (B) a `-07`). Las decisiones de fondo las tomó el usuario el 2026-11-09 y este ADR no las reabre (`§1.1`). La forma de cumplirlas (`§4`) y las preguntas de `§10` quedaron aprobadas el mismo día.
**Fecha**: 2026-11-09
**Origen**: decisión del usuario del 2026-11-09 (nombre del producto, dominios, landing, alojamiento de terceros, idiomas).
**Afecta a**: monorepo (nuevo `apps/landing`), `.github/workflows/` (nuevo workflow; `dependency-scan.yml` si la landing tiene dependencias de desarrollo), DNS de los cuatro dominios. **No afecta a ninguna línea de `apps/api` ni de `apps/web`, ni a la infraestructura de la plataforma.**
**Se apoya en**: `ADR-021` (idiomas), `ADR-023` (design system único), `ADR-028` y `ADR-030` (`§7`), `ADR-049` (la interfaz propia de `RNF-MANT-007` para una herramienta es un protocolo documentado), `RNF-UX-002` (WCAG 2.2 AA), `RSEC-OWASP-005`/`-006` (cabeceras, CSP estricta).
**No sustituye ningún ADR.** Ninguno de los ADR `001`-`071` trata un sitio de marketing del producto.

---

## 1 · Contexto

### 1.1 · Decisiones ya tomadas por el usuario (no se reabren)

1. El producto se llama **AdagioX**.
2. Dominios registrados: `adagiox.com` (principal), `adagiox.es`, `adagiox.eu`, `adagiox.net`.
3. `adagiox.com` resuelve a una landing que explica brevemente la propuesta de valor.
4. La landing se usará para solicitar el programa de ayuda a startups de Anthropic.
5. **Alojamiento**: hosting estático gestionado de terceros, separado de la infraestructura de la plataforma.
6. **Idiomas**: es-ES (por defecto), en, de, fr.
7. **Titular legal**: no hay entidad constituida. Qué hacer mientras tanto lo decide el usuario (`OPEN-072-01`).

### 1.2 · Hechos verificados (rama `chore/memoria-renovate-pendientes-568`, `4476010`)

- `apps/` contiene solo `api` y `web`. No existe nada de landing. (Estado previo a este ADR: «AdagioX» solo figura ya en su propia aceptación: `§18`, historial y `CHANGELOG.md`.)
- `.github/workflows/` tiene `build-images.yml`, `ci-api.yml`, `ci-web.yml` y `dependency-scan.yml`. `ci-api.yml`, `ci-web.yml` (`pull_request` y `push` a `develop`/`main`), `dependency-scan.yml` (`pull_request`) y `build-images.yml` se disparan en **todo** PR, **sin filtro `paths`** (`§9`, hallazgo H1). Qué checks son *required checks* en GitHub **no está contrastado**: no se pudo leer la protección de ramas (403 del token de `gh`); `SYSADMIN.md §4` habla de nueve jobs (cuatro de `ci-api`, cuatro de `ci-web` y uno de `dependency-scan`).
- El documento de requisitos no tiene ningún requisito sobre una web del producto. `REQ-WEB` (`§5.14`) es la web pública **de cada centro**, otra cosa. `RUX-DOM-001` (`§10.5`) fija el subdominio por defecto de los centros como `{slug}.plataforma.com`, con un dominio todavía sin nombrar.
- `ADR-015` nombra el centro objetivo inicial. Ese nombre **no** puede aparecer en la landing (`§8`).

### 1.3 · Por qué hace falta un ADR

Introduce una aplicación desplegable nueva en el monorepo, un proveedor externo con tratamiento de datos (las IP de los visitantes), un workflow nuevo y una zona DNS que condiciona decisiones futuras de la plataforma (`OPEN-072-04`). Las cuatro cosas son estructurales.

---

## 2 · Problema

Publicar una página estática, cuatro idiomas, sin datos personales propios, y que no se convierta en una segunda aplicación que mantener. Las reglas del proyecto pensadas para la SPA (`INV-009`, regla de dependencias de `CLAUDE.md §1`, design system único, `§9`) tienen que cumplirse o declararse no aplicables con motivo, no ignorarse.

---

## 3 · Opciones reales

### 3.1 · Ubicación

| | Opción | Coste en solitario | Mantenimiento a 3 años | Invariantes / reglas | Reversibilidad |
|---|---|---|---|---|---|
| **A** | **`apps/landing`** en el monorepo | Bajo | Bajo: misma rama, mismos revisores, mismo Renovate | `apps/` ya es «lo que se despliega»; la landing es desplegable | Alta: un `git mv` o extraerla a otro repo |
| B | Repositorio aparte | Medio: otro repo, otra protección de ramas, otro Renovate, otro `CLAUDE.md` | Medio: dos sitios donde mirar | Pierde la revisión documental y de ADR del monorepo | Alta |
| C | `docs/landing` o `infra/landing` | Bajo | Confuso: `docs/` es documentación y `infra/` es la plataforma | `CLAUDE.md §6` reserva `docs/` para documentación | Alta |

### 3.2 · Construcción

| | Opción | Dependencias nuevas | `INV-009` | Mantenimiento a 3 años | Reversibilidad |
|---|---|---|---|---|---|
| A | HTML/CSS a mano, una copia por idioma | Ninguna | **No se cumple**: literales en el marcado, y cuatro copias que divergen | Alto: cada cambio se hace cuatro veces | Alta |
| **B** | **Plantilla HTML + ficheros de idioma JSON + script de construcción propio en Node sin dependencias** (solo módulos integrados) | **Ninguna en tiempo de construcción** | Se cumple: la plantilla solo lleva marcadores `{{t:clave}}` | Bajo: ~150-200 líneas propias, sin actualizaciones de terceros | Alta: el resultado es HTML plano, migrable a cualquier generador |
| C | Generador estático (Astro, Eleventy) | Una dependencia con árbol transitivo grande y ciclo de *majors* frecuente | Se cumple con su i18n | Medio: subidas mayores periódicas para dos páginas | Media |
| D | Reutilizar Vite + Vue de `apps/web` con prerenderizado (`vite-ssg` o similar) | Una dependencia de SSG más todo Vue | Se cumple | Medio, y acopla la landing a las subidas de la SPA | Baja: arrastra Vue y tienta a importar componentes de `apps/web` |

### 3.3 · Proveedor de alojamiento

Datos según la documentación pública conocida a mediados de 2026. **Precios, límites y condiciones se verifican en la fecha de alta** (`§10`, paso U1): este ADR no los da por ciertos.

| Criterio | **Cloudflare** (Workers *static assets* o Pages) | Netlify | GitHub Pages | Bunny.net (UE) |
|---|---|---|---|---|
| Coste | Gratis; ancho de banda sin límite en el plan gratuito (**no verificado**) | Gratis con cupo de créditos mensual (**no verificado**); al agotarlo el sitio se pausa | Gratis | De pago por uso, con mínimo mensual pequeño |
| Condiciones | Cloudflare Inc. (EE. UU.), DPA con cláusulas tipo, adherida al EU-US Data Privacy Framework (**no verificado; comprobar en U1**) | Netlify Inc. (EE. UU.), DPA y DPF | GitHub/Microsoft (EE. UU.) | BunnyWay d.o.o. (Eslovenia, UE) |
| Datos y RGPD | Trata IP en su red global; sin localización UE en el plan gratuito | Trata IP; red global | Trata IP | Trata IP; empresa UE, sin transferencia internacional por defecto |
| Mantenimiento activo | Sí. Cloudflare orienta los proyectos nuevos a Workers con *static assets*; Pages sigue operativo con menos novedades | Sí | Sí | Sí |
| Cabeceras y CSP propias | Sí, fichero `_headers` en el artefacto | Sí, `_headers` (mismo formato) | **No**: sin cabeceras propias, la CSP solo por `<meta>`, sin HSTS propio ni `frame-ancestors` | Sí, por reglas *edge* en el panel (no en el repo) |
| Redirecciones | `_redirects` en el artefacto, y reglas de redirección por zona para los otros dominios | `_redirects` (mismo formato) y alias de dominio | Limitadas | Reglas *edge* |
| DNS de los cuatro dominios | DNS gratuito con DNSSEC, CAA y aplanado de CNAME en el ápice | DNS propio más limitado; ápice por ALIAS/A | No | DNS propio |
| Portabilidad | Alta: `_headers`/`_redirects` son el formato de facto que también lee Netlify | Alta (mismo formato) | — | Media: reglas en el panel, no en el repo |
| Riesgo específico | **Bloqueos de IP de Cloudflare por operadores españoles durante partidos de LaLiga** (órdenes judiciales públicas; **fecha y vigencia no verificadas**, comprobar en U1). Afecta al público objetivo (centros en España) | Pausa del sitio si se agota el cupo (un pico de tráfico o un abuso lo tumba hasta el mes siguiente) | — | Coste variable, sin plan gratuito |

---

## 4 · Decisión

### 4.1 · Ubicación y construcción (opciones 3.1-A y 3.2-B)

- `apps/landing/` con esta estructura:
  - `src/` · plantillas HTML con marcadores `{{t:clave}}` y una hoja `styles.css` escrita a mano.
  - `locales/es-ES.json`, `en.json`, `de.json`, `fr.json` · **única fuente de todo texto visible**, incluidos `alt`, `title`, `aria-label`, `<title>` y `<meta name="description">`.
  - `scripts/build.mjs` · Node 24 (el mismo que `ci-web.yml`), **solo módulos integrados** (`node:fs`, `node:path`). Genera `dist/` con una página por idioma, `sitemap.xml`, `robots.txt`, `404.html`, `_headers` y `_redirects`.
  - `scripts/check.mjs` · comprobaciones propias de `§4.5`.
  - `scripts/deploy.sh` · único punto que conoce al proveedor (`§4.6`).
  - `README.md` · cómo construir, comprobar, desplegar y revertir.
- `dist/` no se versiona (la entrada en `.gitignore` la añade quien implemente).
- **Sin JavaScript en el sitio publicado.** Ni para el selector de idioma, ni para analítica, ni para animaciones.
- **Sin librería de componentes ni framework CSS.** shadcn-vue son componentes Vue y no aplican a HTML sin JS. La landing no introduce otra librería: CSS propio que **copia los valores de los *tokens*** de `docs/design-system.md` (colores, espaciados, tipografía) para mantener la coherencia visual, sin importar nada de `apps/web` (`INV-007` por analogía).
- **Tipografía del sistema** (`system-ui` y alternativas) o fuentes autoalojadas. **Prohibido Google Fonts u otro CDN de terceros**: transfiere la IP del visitante a un tercero, y hay jurisprudencia alemana que lo considera infracción del RGPD (**sin referencia concreta en este ADR; no verificada**). Además rompería la CSP de `§4.4`.
- **Dependencias de desarrollo**: solo `@lhci/cli` (Lighthouse CI), declarada en `apps/landing/package.json` con *lockfile* y cubierta por Renovate y `dependency-scan.yml`. **Se usa solo en CI y nunca se publica.** Antes de añadirla se hace la comprobación de `CLAUDE.md §1` (mantenimiento, licencia y frecuencia de *releases*) y se anota en el PR. Si no la supera, la alternativa es `@axe-core/playwright` con la misma versión de `@playwright/test` que ya usa `apps/web`. La interfaz propia de `RNF-MANT-007` es, como en `ADR-049`, el **protocolo documentado**: un `npm run check:lighthouse` en el `README.md` de la landing.

### 4.2 · Idiomas y `INV-009`

- **Rutas**: `/` (es-ES), `/en/`, `/de/`, `/fr/`. Página legal en `/legal/`, `/en/legal/`, `/de/legal/` y `/fr/legal/`. Mismo *slug* en todos los idiomas para no tener que traducir rutas.
- Cada página lleva `<html lang>` correcto, `<link rel="canonical">` a sí misma, `<link rel="alternate" hreflang="es-ES|en|de|fr">` a sus equivalentes y `hreflang="x-default"` a `/`. `sitemap.xml` repite las alternativas.
- **Sin redirección automática por `Accept-Language`.** El selector son cuatro enlaces visibles en cada página, cada uno con `lang` y `hreflang`. No hace falta JS, los rastreadores ven todas las versiones y el usuario no queda atrapado en un idioma.
- **Cómo se cumple `INV-009` en un sitio estático**: el literal vive en `locales/*.json` y la plantilla solo tiene marcadores. `check.mjs` **falla la construcción** si:
  1. hay un nodo de texto no vacío en una plantilla fuera de un marcador;
  2. un atributo visible (`alt`, `title`, `aria-label`, `placeholder`, `content` de `description`) no es un marcador;
  3. los cuatro ficheros de idioma no tienen el mismo conjunto de claves, o alguna clave está vacía;
  4. los marcadores internos de un valor difieren entre idiomas;
  5. un valor contiene `<` (el texto de idioma es texto plano; los enlaces y el énfasis van en la plantilla).
  El HTML generado en `dist/` lleva literales por definición: es salida, no código.
- **Traducción**: los textos de marketing los redacta en es-ES la sesión a partir de `§8`, y en, de y fr los traduce `traductor` (son más de 15 claves, `CLAUDE.md §2.9`) con tratamiento formal. **El aviso legal y la política de privacidad no se traducen automáticamente** (`CLAUDE.md §2.9`; `OPEN-072-05`).

### 4.3 · DNS y dominios

- **Canónico**: `https://adagiox.com/` (ápice, sin `www`).
- `www.adagiox.com`, y el ápice y `www` de `adagiox.es`, `adagiox.eu` y `adagiox.net` → **301** a `https://adagiox.com/<misma ruta>`. No se asigna idioma por dominio: `.es` no lleva a `/` ni `.eu` a `/en/`. Es más simple y reversible.
- **TLS** gestionado por el proveedor en los ocho nombres. HTTP → HTTPS forzado.
- **HSTS**: `max-age` creciente (primero `86400`, y `31536000` tras una semana sin incidencias), **sin `includeSubDomains` y sin `preload`** en esta versión. La lista *preload* es casi irreversible, e `includeSubDomains` condiciona cualquier uso futuro de subdominios de `adagiox.com` por la plataforma (`OPEN-072-04`).
- **CAA** en los cuatro dominios, limitado a las autoridades del proveedor elegido (Cloudflare: Let's Encrypt, Google Trust Services y las que su documentación liste en la fecha de alta; Netlify: Let's Encrypt), más `iodef` al correo de contacto cuando exista (`§10`, U4).
- **DNSSEC** activado en los cuatro dominios.
- **Correo** (decisión aparte, `OPEN-072-03`). Mientras no se decida, los cuatro dominios publican configuración **anti-suplantación de dominio que no envía correo**: MX nulo (`MX 0 .`, RFC 7505), `v=spf1 -all` y `_dmarc` con `v=DMARC1; p=reject`. Coste cero, y nadie puede enviar correo creíble en nombre de AdagioX.

### 4.4 · Seguridad y privacidad

- **Cabeceras** (`_headers`, generado y comprobado en CI):
  - `Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; font-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'; upgrade-insecure-requests`. **Sin `script-src`**: hereda `'none'`.
  - `Strict-Transport-Security` según `§4.3`.
  - `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`.
  - `Permissions-Policy` con todas las capacidades denegadas.
  - `Cross-Origin-Opener-Policy: same-origin`, `Cross-Origin-Resource-Policy: same-origin`.
- **Sin cookies, sin analítica, sin recursos de terceros, sin formulario.** Así no hay banner de cookies (LSSI-CE art. 22.2 y Directiva ePrivacy) ni datos propios. La analítica que el proveedor ofrezca en su panel queda **desactivada** si inyecta *script* (la de Cloudflare lo hace).
- **Aun así hay tratamiento de datos**: el proveedor recibe la IP de cada visitante y la guarda en sus registros. Por eso la página `/legal/` incluye la información del art. 13 RGPD (responsable, finalidad, base legal, encargado y transferencia a EE. UU. bajo el DPF, plazo y derechos) y la identificación del art. 10 LSSI-CE. **Las dos cosas exigen un titular** (`OPEN-072-01`). Los datos que faltan van como marcadores pendientes, **nunca inventados**: `[TITULAR]`, `[NIF]`, `[DOMICILIO]`, `[CORREO]`.
- `/.well-known/security.txt` (RFC 9116) en cuanto exista `[CORREO]`.
- No se pide ni se trata ningún dato de menores ni de centros: `INV-008`, `INV-014` y `ADR-030` no tienen objeto aquí, y lo seguirán sin tener mientras la landing no tenga formulario. **Añadir un formulario o analítica exige un ADR nuevo.**

### 4.5 · CI/CD

- **Workflow propio** `.github/workflows/ci-landing.yml`, con `paths: ['apps/landing/**', '.github/workflows/ci-landing.yml']`. **No se amplían `ci-api.yml` ni `ci-web.yml`.** Son *required checks*, y meter ahí la landing haría que un fallo de la landing bloqueara mezclas de la plataforma y al revés.
- `ci-landing` **no** se marca como *required check* mientras use filtro `paths`. Un *required check* filtrado por rutas se queda en `pending` en los PR que no tocan la landing y bloquea la mezcla. Si más adelante se quiere obligatorio, se rehace con un *job* que siempre corre y detecta cambios dentro.
- *Jobs*, en este orden:
  1. `build` (`build.mjs`).
  2. `check` (`check.mjs`): las reglas de `INV-009` de `§4.2`, la paridad de idiomas, **enlaces internos y anclas rotos** sobre `dist/`, `hreflang` recíprocos, presencia y valor exacto de cada cabecera en `_headers`, y **ausencia de `<script>`, de `<form>` y de URL de terceros** en `dist/`. Los enlaces externos no se comprueban en CI porque fallan por causas ajenas; se revisan a mano en cada cambio de contenido.
  3. `lighthouse` (`@lhci/cli` sobre `dist/`, perfil móvil, las cuatro portadas y una página legal). Umbrales: accesibilidad = 100; buenas prácticas, SEO y rendimiento ≥ 95.
  4. `deploy`: solo en `push` a `main`, tras los tres anteriores (`§4.6`).
- **WCAG 2.2 AA**: Lighthouse/axe detecta una parte de los fallos, no certifica conformidad. Cada cambio de contenido o de estilos pasa además una **lista manual** que queda en el `README.md` de la landing: teclado y foco visible, contraste, `lang` en los cambios de idioma (WCAG 3.1.2), *reflow* a 320 px, zoom al 200 %, `prefers-reduced-motion` y `prefers-color-scheme`.

### 4.6 · Despliegue

- **Carga directa del artefacto desde GitHub Actions** con un *token* del proveedor de permisos mínimos (solo despliegue de ese proyecto), guardado como secreto del repositorio. **No se usa la integración Git del proveedor**, que pediría acceso de lectura a todo el monorepo privado, con todo el código de la plataforma, para publicar dos páginas.
- El CLI del proveedor (`wrangler` o `netlify-cli`) se ejecuta **solo en CI**, con versión fijada, y solo lo llama `scripts/deploy.sh`. Cambiar de proveedor toca ese script, el secreto y el DNS; nada más (`RNF-MANT-007`, protocolo de `ADR-049`).
- **Producción** = `main`, coherente con `CLAUDE.md §4`. La landing sale a producción con la *release* de `develop` a `main`. Se acepta ese acoplamiento (`OPEN-072-06`).
- **Reversión probada** (`CLAUDE.md §9`): (a) volver al despliegue anterior desde el proveedor (ambos conservan el historial de despliegues), o (b) revertir el commit y dejar que el workflow vuelva a desplegar. La reversión (a) **se ejecuta una vez** al primer despliegue y el resultado se anota en el PR. Los cambios de DNS se revierten restaurando los registros anotados en el `README.md` de la landing antes de cambiarlos.

### 4.7 · Proveedor

**Se recomienda Cloudflare** (Workers con *static assets* en modo solo activos o, si en la fecha de alta Cloudflare aún lo recomienda, Pages; los dos leen el mismo `_headers`/`_redirects`). **Condición**: antes de dar de alta se comprueba si siguen vigentes los bloqueos de IP de Cloudflare por los partidos de LaLiga. Si siguen, se usa **Netlify** con el mismo artefacto (`OPEN-072-02`).

---

## 5 · Motivo

- **Monorepo (3.1-A)**: la landing es pequeña y la revisión de documentación, ADR y dependencias del monorepo ya existe. Un repo aparte duplica la protección de ramas, Renovate y el escaneo para un sitio de dos páginas.
- **Script propio sin dependencias (3.2-B)**: es la única opción que cumple `INV-009` sin añadir una dependencia de construcción. El código propio cabe en una lectura y su resultado es HTML plano: la opción más reversible y la que menos mantenimiento pide a 3 años. Un generador amortiza su coste con decenas de páginas, blog o CMS, y nada de eso está pedido. Decir que no aquí es la función del ADR: un generador añade una dependencia con subidas mayores periódicas y no aporta nada para cuatro portadas y cuatro páginas legales.
- **Sin JS ni terceros**: con `default-src 'none'` y sin *script*, el XSS no tiene donde ejecutarse (`RSEC-OWASP-006`), no hay banner y la única transferencia de datos es la del alojamiento, que se declara.
- **Cloudflare**: gratis sin cupo de tráfico (**no verificado**, comprobar en U1; el riesgo de Netlify es que un pico pause el sitio). Gestiona en el mismo sitio el DNS, DNSSEC, CAA y las redirecciones de los cuatro dominios. Su formato de configuración es portable. En RGPD no hay diferencia entre Cloudflare y Netlify, porque las dos son estadounidenses y, según lo que se sabe (**no verificado**), están bajo el DPF. El único argumento serio en contra son los bloqueos en España, y queda como condición verificable, no como fe. Bunny.net gana en RGPD (empresa UE), pero las cabeceras quedan en el panel y no en el repo, y es de pago. Para una página sin datos propios más allá de la IP en los registros del proveedor, esa ventaja no compensa: se descarta, pero queda como salida si el usuario prioriza la localización UE (`OPEN-072-02`).
- **HSTS sin *preload***: se prioriza lo reversible sobre lo óptimo. El *preload* se puede añadir después, pero quitarlo tarda meses.

---

## 6 · Consecuencias

**Buenas**
- Cero dependencias en el sitio publicado y una sola dependencia de desarrollo, aislada en CI.
- La plataforma no se toca. Un fallo de la landing no bloquea `ci-api`/`ci-web` y al revés. **Excepción (`OPEN-072-08`)**: `require-ci-green` de `build-images.yml` (solo en etiquetas `v*`) cuenta **todos** los *check runs* del commit y falla si alguno está pendiente o no es `success`/`neutral`; como `ci-landing` corre en el *push* a `main` de la *release*, un Lighthouse en rojo o un `deploy` pendiente bloquearía la publicación de la versión de la plataforma.
- Cambiar de proveedor afecta a `deploy.sh`, un secreto y el DNS.

**Malas o a vigilar**
- Hay ~200 líneas de script propio que mantener, con tests mínimos de `check.mjs` (un caso válido y uno inválido por regla) para que una regla de `INV-009` no se desactive sin que nadie lo note.
- La landing sale a producción con las *releases* de la plataforma (`OPEN-072-06`).
- **No es un requisito del producto**: no tiene `REQ-*` y no debe inventarse uno. Los commits referencian un **issue de GitHub** propio (`CLAUDE.md §4`), que se abre al aprobar este ADR.
- `INV-015` no aplica tal cual (no hay requisito). Las reglas de `§4.2`/`§4.4` se cubren con `check.mjs`, que es el test.
- Documentos raíz: `README.md` y `ARCHITECTURE.md` tendrán que mencionar `apps/landing` y el nombre del producto. Este ADR no los modifica (`§9`, H2); al aceptarlo solo se tocó la fila de versiones del `README.md` (3.2.26).
- **`INV-006` no aplica a la landing**: es contenido estático, sin funcionalidad ni API; solo figura en `§8` como fuente de una afirmación de contenido.

---

## 7 · Encaje con `ADR-028`, `ADR-030` y `CLAUDE.md §9`

| Regla | ¿Aplica? | Cómo |
|---|---|---|
| `ADR-028` (red externa, sin proxy del frontend a la API, `Wants=`/`After=`, sin IP a mano, `compose down` prohibido) | **No** | La landing no es un contenedor de la plataforma ni comparte red, ni host, ni Quadlet. No llama a la API: no hay nada que enrutar. |
| `ADR-030` (desarrollo en WSL2, ningún dato real en desarrollo, alojamiento del piloto sin decidir) | **Sí, la parte de datos** | La landing no contiene ningún dato personal, ni real ni de `REQ-SEED`, y **no lleva capturas de la aplicación** (ni con datos ficticios, `§8`). **No decide** el alojamiento del piloto: el proveedor de la landing no aloja nada de la plataforma. |
| Expand/contract, compatibilidad de esquema, workers con trabajos antiguos | **No** | No hay base de datos ni colas. |
| Aplicación sin estado, nada en disco local | **Sí** | Artefacto estático inmutable por despliegue. |
| «El host solo ejecuta contenedores; no se instala Node» | **Sí, por analogía en local** | En CI, `setup-node`. En local, la construcción se lanza dentro de un contenedor `node:24` con `podman run --rm -v …:Z`, documentado en el `README.md` de la landing, no con un Node instalado en el sistema. |
| SELinux `enforcing`, volúmenes `:Z` | **Sí en local** | Montaje con `:Z` en el comando anterior. En el proveedor no aplica. |
| Reversión probada en cada entrega | **Sí** | `§4.6`. |
| Cabeceras de seguridad y CSP estricta desde el primer despliegue (`CLAUDE.md §8`) | **Sí** | `§4.4`, comprobadas en CI. |
| Escaneo de dependencias en cada PR (`CLAUDE.md §8`) | **Sí** | `dependency-scan.yml` ya lo cubre sin cambios: Trivy corre en modo `fs` sobre `.` y recoge cualquier *lockfile* (solo HIGH y CRITICAL, con `ignore-unfixed`). `renovate.json` solo agrupa `apps/api` y `apps/web`: la landing caería sin grupo ni sufijo (cosmético). |

---

## 8 · Contenido verificable

La landing dice **solo** lo que el documento de requisitos fija como objetivo del producto, con el estado **«en desarrollo»**. Cada afirmación lleva su fuente, y el PR de contenido incluye esta tabla para que `doc-reviewer` la contraste.

| Afirmación permitida (redacción orientativa) | Fuente |
|---|---|
| Un único sistema para la vida académica, económica, administrativa y la comunicación con las familias de un centro educativo | `§1.3` |
| Varios centros en una sola plataforma, con los datos de cada uno aislados | `§2.1`, `§12` (`RMT-001`, `RMT-002`), `INV-001` |
| Cada centro activa solo los módulos que necesita | `§1.3`, `§13` (`RMOD-001`-`004`) |
| Marca y dominio propios para cada centro | `§1.3`, `§3.3` punto 2, `§10.4`, `§10.5` (`RUX-DOM-002`) |
| Roles y permisos configurables por el propio centro | `§3.3` punto 3, `§11` |
| Toda la funcionalidad disponible por API | `INV-006`, `§3.3` punto 6 |
| Interfaz en castellano, inglés, alemán y francés | `ADR-021`, `§5.1` |
| Diseñada con el objetivo de cumplir WCAG 2.2 AA | `RNF-UX-002`, `ADR-009` |
| Pensada para centros públicos, privados y concertados, y con requisitos para la normativa educativa y de protección de datos española (objetivo del producto, sin afirmar conformidad acreditada) | `§2.1`, `§4.1`, `§4.3` |

**Prohibido**, aunque aparezca en el documento como objetivo o plan:
- certificaciones o auditorías como logradas (ISO 27001, pentest: `§3.3` punto 8 y el hito H4 de `§17` son metas, no hechos);
- cifras (número de módulos, centros, usuarios, rendimiento);
- clientes, pilotos o centros con nombre (`ADR-015`);
- fechas de disponibilidad, fases del *roadmap* o precios (`§17` son estimaciones; con `OPEN-072-01` = B tampoco puede haber oferta comercial);
- apps móviles (fase 3);
- capturas o maquetas de la aplicación;
- comparaciones con competidores con nombre (`§3` describe el mercado, no autoriza a citarlo).

La redacción en es-ES la valida el usuario antes de traducirla.

---

## 9 · Hallazgos fuera del ámbito de este ADR (reportados, no corregidos)

- **H1**: `ci-api.yml`, `ci-web.yml`, `dependency-scan.yml` y `build-images.yml` no tienen filtro `paths` (ver `§1.2`). Cada PR que solo toque `apps/landing` (o `docs/`) gastará minutos de CI de API y web. No es un fallo. Si se corrige, hay que hacerlo sin romper los *required checks* (el problema de `pending` descrito en `§4.5`).
- **H2**: el nombre «AdagioX» y los dominios no figuran en `README.md` ni `ARCHITECTURE.md`, y en el documento de requisitos solo en la fila de este ADR en `§18` y en el historial (3.2.26); el resto de ese documento no los usa. `RUX-DOM-001` sigue con `{slug}.plataforma.com`. Actualizarlo corresponde a quien mantenga esos documentos, y lo condiciona `OPEN-072-04`.
- **H3**: la entrada de este ADR en el índice de la sección 18 del documento de requisitos **se añadió al aceptar el ADR** (documento de requisitos 3.2.26). Hallazgo resuelto.

---

## 10 · Preguntas abiertas para el usuario

| ID | Pregunta | Recomendación del arquitecto |
|---|---|---|
| `OPEN-072-01` | Sin entidad constituida: (A) esperar a tener titular; (B) publicar ya una página mínima, informativa, sin captación de datos ni oferta comercial, con el usuario como persona física titular en `/legal/`; (C) publicar sin identificar titular | **B, previa consulta breve a un asesor.** C incumple el art. 13 RGPD en cuanto el proveedor registra IP, y probablemente el art. 10 LSSI-CE. A retrasa la solicitud a Anthropic sin que eso reduzca ningún riesgo. Con B, cambiar después al titular definitivo es editar un texto. Coste: el art. 10 LSSI-CE pide domicilio, y en B sería público el del usuario; el asesor dirá si basta un domicilio a efectos de notificaciones. Datos: `[TITULAR]`, `[NIF]`, `[DOMICILIO]`, `[CORREO]` pendientes. **Resuelta: la recomendación** (usuario, 2026-11-09). |
| `OPEN-072-02` | Proveedor | **Cloudflare**, condicionado a que no sigan los bloqueos por LaLiga en la fecha de alta; si siguen, **Netlify**. Bunny.net solo si se prioriza la localización UE por encima del coste. **Resuelta: la recomendación** (usuario, 2026-11-09). |
| `OPEN-072-03` | Correo del dominio | **Ahora**: MX nulo + SPF `-all` + DMARC `p=reject` en los cuatro dominios. Si la solicitud a Anthropic, `security.txt` o el aviso legal necesitan un `@adagiox.com`, se elige un buzón en un proveedor de la UE y solo `adagiox.com` recibe MX, SPF, DKIM y DMARC reales. Esa elección va en una decisión aparte. **Resuelta: la recomendación** (usuario, 2026-11-09). |
| `OPEN-072-04` | ¿Los centros colgarán de subdominios de `adagiox.com` (`RUX-DOM-001`) o de un dominio aparte? | **No decidirlo en este ADR.** Lo condiciona HSTS (`includeSubDomains`/`preload`), las cookies entre subdominios del mismo dominio registrable (los subdominios de centros distintos serían del mismo «sitio» a efectos de `SameSite`) y el aislamiento de la marca. La práctica habitual en SaaS es un dominio aparte para los centros. Necesita su propio ADR antes del primer despliegue de la plataforma en un dominio real. **Resuelta: la recomendación** (usuario, 2026-11-09). |
| `OPEN-072-05` | Páginas legales en en/de/fr | **Publicarlas en es-ES en las cuatro rutas**, con `lang="es-ES"` en el contenido y una línea traducida que lo explique (la redacta la sesión, no `traductor`, y no es texto legal: solo avisa del idioma), hasta tener una traducción humana revisada (`CLAUDE.md §2.9`). **Resuelta: la recomendación** (usuario, 2026-11-09). |
| `OPEN-072-06` | ¿Aceptar que la landing salga a producción solo con las *releases* de `develop` a `main`? | **Sí.** Una rama o un camino aparte para la landing rompe el modelo de `CLAUDE.md §4` para ahorrar unos días. Si un cambio urge, se hace una *release*. **Resuelta: la recomendación** (usuario, 2026-11-09). |
| `OPEN-072-07` | Riesgo del nombre | **Antes de publicar**, búsqueda gratuita de «AdagioX» y variantes fonéticas («Adagio») en TMview/EUIPO y en el localizador de la OEPM, clases de Niza 9, 41 y 42. «Adagio» es un término común y es probable que haya marcas anteriores en software o educación. Si aparece una marca confundible en esas clases, se consulta antes de invertir en la marca. Registrarla es una decisión aparte. **Resuelta: la recomendación** (usuario, 2026-11-09). |
| `OPEN-072-08` | `require-ci-green` cuenta todos los *check runs* del commit de la *release*: ¿se acepta que un fallo de `ci-landing` bloquee la versión de la plataforma, o se excluye `ci-landing` de esa puerta? | **A** · Aceptar el acoplamiento en la primera versión, sin tocar la puerta. **B** · Excluir los checks de `ci-landing` en `build-images.yml` (toca la puerta de *release*) | **A** por seguridad: no se debilita la puerta de *release*, y un rojo de la landing se ve y se arregla antes de la *release*. **Abierta, pendiente del usuario.** |

---

## 11 · Pasos fuera del repositorio (los hace el usuario)

- **U1**: cuenta en el proveedor elegido. Verificar en la fecha de alta precios, límites, DPA y el estado del bloqueo por LaLiga. Crear un *token* de API limitado al despliegue del proyecto y guardarlo como secreto del repositorio en GitHub.
- **U2**: DNS de los cuatro dominios. Delegar los servidores de nombres al proveedor (o crear los registros donde estén) y **anotar antes los registros actuales** para poder revertir. Activar DNSSEC (publicar el DS en cada registrador). Crear los registros de `§4.3` (ápice, `www`, redirecciones, CAA, MX nulo, SPF, DMARC).
- **U3**: `.eu` exige que el titular resida en la UE (EURid). Al constituir la entidad, **transferir la titularidad de los cuatro dominios** de la persona física a la entidad.
- **U4**: decidir `OPEN-072-01`, y con ello los datos de `/legal/`: titular, NIF, domicilio y correo. Redactar o encargar el aviso legal y la política de privacidad en es-ES. **El arquitecto no los redacta**: son textos legales.
- **U5**: búsqueda de marca (`OPEN-072-07`).
- **U6**: revisar las condiciones del programa de Anthropic para startups. Este ADR no presupone qué exige; si pide algo que la landing no tiene (correo del dominio, entidad), se vuelve a `§10`.

---

## 12 · Alternativas descartadas

- **HTML a mano por idioma**: incumple `INV-009` y multiplica por cuatro cada cambio.
- **Astro/Eleventy**: dependencia con *majors* periódicos sin beneficio para dos páginas. Se reconsidera con un ADR nuevo si algún día se pide blog o CMS.
- **Prerenderizar con Vue desde `apps/web`**: acopla la landing al ciclo de la SPA e invita a importar sus componentes.
- **Servir la landing desde la infraestructura de la plataforma** (Traefik/Quadlet): contradice la decisión del usuario y ata la disponibilidad de la cara pública a un host que aún no existe (`ADR-030`).
- **GitHub Pages**: sin cabeceras propias no hay HSTS controlado, ni `frame-ancestors`, ni CSP por cabecera (`RSEC-OWASP-005`).
- **Integración Git del proveedor**: le daría lectura de todo el monorepo privado.
- **Analítica sin cookies del proveedor o de terceros**: inyecta *script* (rompe la CSP) o añade un encargado más, y no hay una decisión que dependa de esos datos. Si se necesita, ADR nuevo.
- **Redirección por idioma según dominio o `Accept-Language`**: más reglas, peor rastreo y nada que no resuelvan cuatro enlaces.

---

## 13 · Estimación (`CLAUDE.md §2.7`)

**2 tramos de `implementer`** (≈100-150 llamadas), dentro del límite de 3:
1. Estructura, `build.mjs`, `check.mjs` con sus casos de prueba, plantillas, CSS y `locales/es-ES.json`.
2. `_headers`/`_redirects`, `ci-landing.yml`, `deploy.sh`, Lighthouse y `dependency-scan.yml`.

`traductor` se encarga de en/de/fr entre los dos tramos. El despliegue real y el DNS dependen de U1-U4.
