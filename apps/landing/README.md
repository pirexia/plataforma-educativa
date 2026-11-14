# Landing pública de adagiox.com

Sitio estático de AdagioX (`ADR-072`). **Sin JavaScript necesario**: el sitio es completamente utilizable sin él y lleva **un único script propio** (`src/js/carousel.js`, `ADR-075`) que solo mejora el carrusel. Sin cookies, sin analítica, sin formularios y sin recursos de terceros. Cuatro idiomas: en (por defecto, en `/`); es-ES, de y fr bajo `/es/`, `/de/`, `/fr/`; los textos legales solo en es-ES. **Solo esta landing usa el nombre AdagioX**; el resto del repositorio no cambia (`ADR-072 §1.1`).

Estado: rediseño de `ADR-074` hecho en sus tramos **T1** (idiomas y comprobaciones), **T2** (capturas), **T3** (diseño y contenido, en/es-ES/de/fr) y **T4** (`ADR-075`, carrusel con script propio: avance automático, puntos, flechas y pausa, y la pantalla de acceso como primera diapositiva). El copy en inglés y en es-ES está redactado por la sesión y **pendiente de validación del usuario**; `de` y `fr` están traducidos (tratamiento formal) y `npm run check:publish` pasa. Datos legales resueltos por `ADR-073` (titular persona física sin NIF ni domicilio publicados; correo `founders@adagiox.com`; publicación sin asesor). **No se publica nada**: el despliegue está apagado hasta `LANDING_DEPLOY_ENABLED = true`, y antes deben estar el DNS, el buzón `founders@adagiox.com` funcionando y el *environment* `landing-production` (`SYSADMIN.md §4b`).

## Estructura

| Ruta | Qué es |
|---|---|
| `src/*.html`, `src/partials/` | Plantillas con marcadores `{{t:clave}}` (texto), `{{v:nombre}}` (variable de construcción) y `{{include:parcial}}` |
| `src/styles.css` | CSS a mano que copia los valores de los *tokens* de `docs/design-system.md §4.3`; no importa nada de `apps/web` |
| `locales/{es-ES,en,de,fr}.json` | Única fuente de todo texto visible (incluidos `alt`, `title`, `aria-label`, `<title>` y `<meta name="description">`) |
| `scripts/build.mjs` | Genera `dist/` (Node 24, solo módulos integrados) |
| `scripts/check.mjs` | Comprobaciones de `ADR-072 §4.2` y `§4.5` |
| `scripts/headers.mjs` | CSP (con `script-src 'self'`, `ADR-075`), HSTS y demás cabeceras; redirecciones de dominio |
| `src/js/carousel.js` | Único script propio (`ADR-075`): controles y avance automático del carrusel; ≤ 6 KiB, sin dependencias |
| `test/` | Pruebas con `node --test` y fixtures (un caso malo y uno bueno por regla) |

## Construir y comprobar

En local, la construcción se lanza dentro de un contenedor (`ADR-072 §7`; el host no lleva Node):

```bash
podman run --rm -v "$PWD":/app:Z -w /app/apps/landing docker.io/library/node:24 \
  sh -c "npm test && node scripts/build.mjs && node scripts/check.mjs"
```

Con Node 24 ya disponible (CI con `setup-node`, o `nvm` en WSL2) basta con:

```bash
cd apps/landing
npm test                          # pruebas de build.mjs y check.mjs
node scripts/build.mjs            # genera dist/
node scripts/check.mjs            # comprobaciones en desarrollo (avisos no bloquean)
node scripts/check.mjs --publish  # comprobaciones previas a publicar (ver abajo)
```

`dist/` no se versiona. Para verlo en local: `python3 -m http.server -d dist`.

### Qué comprueba `check.mjs`

- **Literales (`INV-009`)**: ningún texto visible en las plantillas fuera de un marcador; `alt`, `title`, `aria-label`, `placeholder` y `meta description` solo con marcador; claves usadas inexistentes.
- **Idiomas**: mismas claves exactas en los cuatro ficheros, sin valores vacíos, sin `<`, y mismos marcadores internos `{nombre}` en cada idioma.
- **`dist/`**: ausencia de `<script>` en línea o de otro origen, `<form>`, `<iframe>`, `<style>`, atributos `style` y manejadores `on*`; ningún recurso de terceros (`src`, `<link>`, `url()`, `@import`); enlaces internos y anclas; un único `<h1>`; `lang`, `canonical` y `hreflang` correctos y recíprocos; `sitemap.xml`; presencia y valor exacto de cada cabecera de `_headers`. Los enlaces externos no se comprueban (se revisan a mano).
- **Carrusel y efectos (`ADR-074 §4.5`)**: el contenedor `.carousel` lleva `tabindex="0"`, `role="region"` y `aria-label`; ningún `aria-roledescription`; `animation-timeline` solo dentro de `@supports (animation-timeline: ...)` y de `@media (prefers-reduced-motion: no-preference)`; los `@keyframes` ligados al scroll solo animan `transform` (nunca `opacity`, `filter` ni color); sin `::scroll-button` ni `::scroll-marker`.
- **Script propio (`ADR-075 §2.6`)**: solo se admite `<script src="/js/…">` del mismo origen y con `defer`; se prohíben `<script>` en línea, `src` de otro origen, `on*=` y `javascript:`. La CSP de `dist/_headers` debe ser exactamente `default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'; upgrade-insecure-requests` (sin `'unsafe-inline'` ni `'unsafe-eval'`; duplicada en `check.mjs` a propósito). `dist/js/` solo puede contener `carousel.js`. En el script (fuente y `dist/`): sin `eval`, `new Function`, `document.write`, `innerHTML`/`outerHTML`/`insertAdjacentHTML`, red (`fetch`, `XMLHttpRequest`, `WebSocket`, `sendBeacon`, `import()`) ni cookies o almacenamiento; peso ≤ 6 KiB; y **sin literales visibles** (heurística de `scanScript`/`isVisibleLiteral`: solo cadenas en minúscula o `camelCase`, selectores o consultas de medios que empiecen por `. # [ ( :` y marcadores `{n}`; no detecta una palabra suelta en minúscula, que se revisa a mano). El carrusel debe llevar `data-prev`, `data-next`, `data-goto`, `data-pause` y `data-resume`, y en las plantillas solo con `{{t:…}}`.
- **`--publish`** (bloquea la publicación): falla si queda alguna clave `_pending_translation`, algún marcador pendiente (`[TITULAR]`, `[NIF]`, `[DOMICILIO]`, `[CORREO]`, `[PROVEEDOR]`, `[GARANTIA]`, `[PLAZO]`, cualquier `[MAYÚSCULAS_1-2]`, `TODO` o `TBD`) en los locales, las plantillas **o el HTML de `dist/`**, si `dist/` no existe o es anterior a `src/` o `locales/` (por fecha de modificación). En desarrollo solo avisa.
- **Alcance de las reglas del script**: la defensa real es la CSP (`script-src 'self'` sin `unsafe-*`, y `default-src 'none'`, que bloquea cualquier red). Las reglas de `check.mjs` sobre el script (sin `eval`, red, cookies, etc.) son una red de seguridad heurística: p. ej. no detectan `document['cookie']`.
- **Enlaces externos**: todo `<a>` a otro host http(s) debe llevar `rel="noopener noreferrer"`.

## Idiomas

- `en.json` (`BASE_LANG`) y `es-ES.json` son idiomas de redacción; `de.json` y `fr.json` llevan las claves `home.*` traducidas (tratamiento formal) y sin `_pending_translation`. El indicador sigue existiendo como mecanismo: al añadir claves nuevas en es-ES, se copian a en/de/fr con ese indicador, `check` avisa y `--publish` falla hasta que se traducen y se elimina el indicador.
- **Los textos legales no se traducen automáticamente** (`CLAUDE.md §2.9`, `OPEN-072-05`): las claves `legal.body.*` se renderizan siempre desde es-ES, con `lang="es-ES"`, en las cuatro rutas `/legal/`. Solo la línea `legal.lang.notice` (aviso de idioma) se traduce.
- Etiquetas del carrusel: cinco claves `home.carousel.{prev,next,goto,pause,resume}` (`goto` lleva `{n}` y `{total}`) más `home.carousel.hint`, que describe los controles y sigue siendo veraz sin JavaScript. Llegan al script por atributos `data-*` del contenedor `.carousel` (`data-prev`, `data-next`, `data-goto`, `data-pause`, `data-resume`) puestos con `{{t:…}}`: el script no contiene texto. Para añadir una etiqueta, se crea la clave en los cuatro idiomas, se pone un `data-…` nuevo con su marcador y el script lo lee con `getAttribute`; `check` exige las cinco actuales.
- Rutas: `/` (en), `/es/`, `/de/`, `/fr/` y `/legal/`, `/es/legal/`, `/de/legal/`, `/fr/legal/`. Sin redirección por `Accept-Language`: el selector son cuatro enlaces.

### Cómo añadir un idioma (`ADR-074 §4.2`)

`scripts/lib.mjs` → `LANGS` es la única lista de idiomas (`code` BCP 47, `slug` de URL y `dir`); las plantillas no nombran idiomas. Para añadir `xx`:

1. Una entrada en `LANGS`: `{ code: 'xx', slug: 'xx', dir: 'ltr' }` (`rtl` si procede). El `slug` es el segmento de URL; el `code` va en `lang` y `hreflang`. El idioma por defecto (`DEFAULT_LANG`) es el único con `slug: ''`.
2. `locales/xx.json` con todas las claves de `en.json` y `"_pending_translation": true`. `en` (`BASE_LANG`) y `es-ES` (`LEGAL_LANG`) son idiomas de redacción y no admiten esa marca.
3. La clave `lang.name.xx` (nombre del idioma en su propia lengua) con **el mismo valor en todas** las locales.
4. La URL de su portada (`http://localhost/xx/index.html`) en `.lighthouserc.json`.

`npm run check` falla si falta algún paso (fichero de locale, autónimo, URL de Lighthouse), si hay dos idiomas con el mismo `slug` o si `dir` no es `ltr`/`rtl`; `npm run check:publish` falla mientras quede `_pending_translation`. Selector, `hreflang`, `x-default`, `canonical` y `sitemap.xml` salen de `LANGS` sin tocar plantillas. El texto legal (`legal.body.*`) sigue saliendo siempre de `es-ES`.

## Contenido: afirmación → paso del plan → PR (`ADR-074 §4.7`)

Estado visible siempre: «In development: not yet available to schools». Sin cifras, clientes, certificaciones, fechas, precios, comparaciones, logotipos ni nombres de terceros (no se nombra a ningún proveedor de identidad: «external provider»). Las pantallas llevan un aviso de que son de un centro de pruebas con datos ficticios. Una funcionalidad aparece solo si su paso está **mezclado en `develop`** (PR comprobados con `gh pr view` el 2026-11-10: todos `MERGED` en `develop`). El copy en y es-ES **falta de validación del usuario**.

| Claves | Afirmación en la landing | Paso del plan | PR |
|---|---|---|---|
| `home.s.access.*` | Cuenta local; sesiones activas con cierre remoto | `1.2`, `1.2b` | #76, #91 |
| | MFA por aplicación (TOTP) o correo, obligatoria por rol | `1.3`, `1.3b` | #107, #123 |
| | Inicio de sesión con proveedor externo; SSO OIDC y SAML 2.0 | `1.4`, `1.4b`, `1.4c` | #143, #149, #160 |
| `home.s.tenant.*` | Datos aislados entre centros (`INV-001`) | `0.7` | no verificado (aislamiento transversal, no un PR propio) |
| | Logotipo, favicon y fondo de acceso propios; módulos activados por centro; invitaciones | `1.9b`, `1.9e` | #299, #325 |
| | Importación masiva de usuarios | `1.9c` | #315 |
| `home.s.roles.*` | Roles personalizados; permiso concedido, denegado o sin definir; la denegación prevalece (`RPERM-007`); permisos efectivos | `1.5`, `1.5b` | #171, #354 |
| `home.s.audit.*` | Registro de auditoría con filtros y exportación | `1.9d` | #320 |
| `home.s.timetable.*` | Horario semanal por grupo; conflictos de docente; reserva de espacios | `1.12` (`1.12-a`, `1.12-b`) | #447, #449 |
| `home.s.families.*` | Unidades familiares, tutores, vínculos (parentesco, representación legal, acceso), custodia por alumno | `1.14-a` | #468 |
| | Consentimiento de uso de imagen | `1.14-b` | #478 |
| `home.s.pickup.*` | Búsqueda en conserjería y personas autorizadas a recoger (solo hasta `1.14b-d1`; no se citan `1.14b-d2` a `-d4`) | `1.14b-a`, `-b`, `-c1`, `-c2`, `-d1` | #497, #504, #513, #519, #525 |
| `home.also.academic.*` | Estructura académica, períodos lectivos, escalas de calificación | `1.11-b`, `1.11d-a`, `1.11d-b` | #423, #435, #439 |
| `home.also.year.*` | Abrir, cerrar y reabrir un curso; abrir el siguiente | `1.10`, `1.11c`, `1.12b` | #386, #443, #460 |
| `home.also.languages.*` | Interfaz en castellano, inglés, alemán y francés; idioma por usuario | `ADR-021` | no verificado (transversal) |
| `home.next.*` | Matrícula y listas, asistencia, calificaciones y boletines, comunicación con familias, portales: pasos de fase 1 **no** mezclados, sin fechas ni orden | `1.15-b` a `1.23`; `1.15-a` (alumnado, #559) está mezclado y la landing no lo afirma | sin PR de estos pasos (#559 es solo `1.15-a`) |
| `home.built.*` | «Developed with Claude Code» (`OPEN-074-05`), sin logotipo ni enlace | `ADR-074 §4.7` | no aplica |
| `home.status.*`, `footer.status` | «En desarrollo»; no disponible para centros | `ADR-074 §4.7` | no aplica |

Contacto: un enlace `mailto:founders@adagiox.com` (el correo que ya publica `/legal/`, `ADR-073`); la landing sigue sin formularios.

**Capturas desfasadas**: la imagen `sign-in` mostrará una pantalla de acceso distinta cuando se implemente el paso `1.15a` (`ADR-064`, capa visual). Entonces hay que regenerar las capturas (`npm run landing:shots` y `tools/images/images.mjs`) en un único commit de imágenes.

## Diseño (`ADR-074 §4.4`-`§4.5`)

- Identidad propia en `:root` de `src/styles.css`: tinta índigo (`--foreground` `oklch(0.2 0.04 270)`) sobre fondo casi blanco, un acento de marca índigo (`--brand`) y un punto cálido (`--accent`) solo decorativo; variante oscura en `@media (prefers-color-scheme: dark)`. Contrastes calculados: texto corriente >= 6,1:1 en claro y >= 6,9:1 en oscuro, botones 7,7:1 y 9,8:1.
- Fondo: degradado CSS y `src/img/hero-bg.svg` (vectorial propio, < 1 KB, líneas de pentagrama); no es una imagen ráster ni puede ser el LCP (que es la primera captura del carrusel).
- Efectos de scroll (`@supports (animation-timeline: view())` y `no-preference`): solo `transform` en las capturas, las tarjetas y el fondo. Sin soporte o con `reduce`, el contenido está en su estado final.

Página legal (`legal.body.*`): estructura de `ADR-072 §4.3` y `§4.4` (identificación del art. 10 LSSI-CE; información del art. 13 RGPD: responsable, datos, finalidad, base legal, encargado, transferencia, plazo, derechos, cookies), con los datos de `ADR-073`: titular persona física sin NIF ni domicilio publicados, encargados Cloudflare, Inc. y Arsys Internet, S.L.U., garantía de la transferencia, plazo de conservación y tratamiento de los correos de `founders@adagiox.com`. Ya no es un borrador con marcadores; se publica **sin revisión de asesor** (`ADR-073 §2.4`) y `check:publish` pasa. Los marcadores (`[TITULAR]`, `[NIF]`, `[DOMICILIO]`, `[CORREO]`, `[PROVEEDOR]`, `[GARANTIA]`, `[PLAZO]`) siguen bloqueando la publicación si reaparecen.

## Capturas (`ADR-074 §4.4`)

Las imágenes de la cabecera y de las secciones son capturas **reales de la aplicación** con un centro de escaparate de datos sintéticos. No es un test: no entra en `test:e2e:real` ni en CI.

```bash
# Con la pila de desarrollo arriba (plataforma-api en :8000 y plataforma-postgres):
cd apps/web && npm run landing:shots
```

- **Qué hace** (`apps/web/scripts/landing-shots.sh`): crea por el soporte destructivo de la API (`apps/api/tests/Support/e2e-real-tenant.php setup demo showcase`) el centro «Colegio Ejemplo Los Álamos (ficticio)», lanza `apps/web/e2e/landing-shots.spec.ts` y **lo retira siempre** al terminar, también si falla (`trap`).
- **Datos**: `apps/web/e2e/fixtures/landingShowcase.ts`, con valores fijos (centro, etapas, grupos, asignaturas, aulas, marco horario, horario de 1.º ESO A y B, rol personalizado, unidad familiar con tres tutores y custodia, alumnado, persona autorizada a recoger, marca). Todo se da de alta por la API real con la sesión del administrador; solo los tres docentes activos y el administrador los deja el soporte. Nombres inventados, correos `@example.com`, **sin documentos de identidad, direcciones ni fotografías** (el logo es un SVG sintético y el fondo de acceso, un paisaje estilizado también sintético: `login-background.svg` es la fuente y `login-background.png` (1920 × 1080, ~50 KB) el derivado que sube el spec, porque la API no admite SVG como fondo; el comando para regenerarlo está en el comentario del SVG).
- **Slug `demo`**: la CORS de desarrollo de la API (`CORS_ALLOWED_ORIGINS`) solo admite `http://demo.plataforma.test:5173`. El soporte solo crea y retira un `demo` que lleve el nombre fijo del escaparate **y** su administrador (`elena.marin@example.com`); un `demo` de otro origen no se toca.
- **Estado (tramos T2b y T2c)**: el spec captura ocho pantallas a 1440 × 900 CSS px, `deviceScaleFactor: 2`, en inglés: `sign-in` (acceso sin sesión), `hero-schedule` (rejilla limpia de 1.º ESO A), `schedule-conflict` (diálogo con el conflicto de profesor), `role-permissions` (módulo Academic management, con `allow`, `deny` explícito y sin conceder), `audit-log` (cambios de negocio dados de alta justo antes), `family-unit`, `pickup-door` y `school-branding`; guarda `<id>.png` y `<id>.txt` (texto visible, para la revisión) en `apps/landing/tools/images/masters/` (ignorado por Git). `E2E_SHOTS_ONLY=id1,id2` limita las pantallas al depurar. Falla si falta una pantalla, si hay un 4xx/5xx inesperado o si el texto contiene un correo no `@example.com`, marca E2E, URL de desarrollo o documento. Después, `node tools/images/images.mjs` genera `src/img/` y `manifest.json` (el tope sigue en 8 imágenes); **se revisa a ojo cada imagen** (lista de `ADR-074 §4.3`).
- **Requisitos**: para la pantalla de marca (logo) hace falta el almacén S3 de desarrollo (Garage, `ADR-076`; `scripts/landing-shots.sh` lo levanta e inicializa con `infra/garage/init.sh`); la cola `long` (`worker-long`) solo si un paso ejecuta la copia del curso siguiente.

## Lista manual de accesibilidad (WCAG 2.2 AA)

Lighthouse/axe no certifican conformidad. En cada cambio de contenido o de estilos, comprobar a mano:

- [ ] Teclado: todo es alcanzable y el foco es siempre visible; el *skip link* aparece al primer `Tab`.
- [ ] Contraste de texto y de componentes (los *tokens* ya cumplen `docs/design-system.md §4.4`).
- [ ] `lang` en los cambios de idioma (WCAG 3.1.2): enlaces del selector y bloque legal en es-ES.
- [ ] *Reflow* a 320 px de ancho sin desplazamiento horizontal.
- [ ] Zoom al 200 % sin pérdida de contenido.
- [ ] `prefers-reduced-motion` y `prefers-color-scheme` respetados.
- [ ] Un único `h1` por página y jerarquía de títulos sin saltos.
- [ ] Carrusel con teclado: entrar con `Tab`, recorrer con las flechas del teclado, pasar por los botones anterior/siguiente, los puntos y el de pausa, salir con `Tab`; foco visible en todos; el avance automático se detiene con el botón de pausa (WCAG 2.2.2), con el foco de teclado dentro, con el puntero encima y con la pestaña oculta, y no existe con `prefers-reduced-motion: reduce`.
- [ ] Objetivo táctil >= 44 px en los botones, las flechas y los puntos del carrusel y de 24 x 24 px (WCAG 2.5.8) en el selector de idioma; el carrusel ocupa el ancho completo en móvil.
- [ ] Con `prefers-reduced-motion: reduce` no se anima nada.

## Carrusel con script propio (`ADR-075`)

Mejora progresiva: sin JavaScript el carrusel es el de `ADR-074 §4.5` (desplazamiento con `scroll-snap` y teclado, la siguiente diapositiva asoma) y no se ve ningún botón. Con JavaScript, `src/js/carousel.js` (script clásico con `defer`, cargado desde la portada) añade, creados por el propio script:

- Flechas anterior y siguiente (con vuelta), superpuestas a los lados, y un punto por diapositiva (`aria-label` «ir a la pantalla N de M», `aria-current="true"` en el activo) sincronizados con el desplazamiento por `IntersectionObserver`.
- Avance automático cada 6 s con vuelta a la primera, y botón de pausa/reanudación visible y operable por teclado (WCAG 2.2.2). El estado lo comunica la etiqueta, que alterna «Pausar»/«Reanudar» (`data-pause`/`data-resume`), sin `aria-pressed`: es un refinamiento del detalle de `ADR-075 §2`, que decía `aria-pressed`, porque la APG desaconseja combinar `aria-pressed` con una etiqueta cambiante (un lector diría «Reanudar, pulsado»). Se pausa con el puntero (ratón) encima, con foco de teclado (`:focus-visible`) dentro y con la pestaña oculta. Con `prefers-reduced-motion: reduce` no hay avance automático ni botón de pausa, pero flechas y puntos sí, sin desplazamiento suave.
- Dirección: los controles usan propiedades lógicas y el cálculo del desplazamiento distingue `dir="rtl"`.

**Reglas**: sin dependencias, sin `eval`, sin HTML dinámico, sin red ni almacenamiento, sin literales visibles (etiquetas por `data-*`), ≤ 6 KiB sin comprimir (hoy ≈ 5 KiB). Pruebas de comportamiento en `test/carousel.test.mjs` con una maqueta mínima de DOM y `vm`, sin dependencias.

**CSP**: `script-src 'self'` (ADR-075 sustituye a `ADR-072 §4.4` en esa directiva): ni scripts en línea ni de terceros. Se verificó con `wrangler dev` (cabeceras reales) y el Chromium de Playwright: sin violaciones de CSP.

## Lighthouse

`npm run lighthouse` (`lhci autorun`, `@lhci/cli` 0.16.0) sirve `dist/` con `staticDistDir` y audita en perfil móvil las cuatro portadas (es, en, de, fr) y `/legal/`, tres pasadas cada una. Umbrales (`ADR-072 §4.5`, `.lighthouserc.json`): accesibilidad = 1; buenas prácticas, SEO y rendimiento >= 0,95. Necesita Chrome (en CI viene en el runner; en local, `CHROME_PATH`). Informes en `.lighthouseci/` (ignorado por Git).

**Limitación**: `lhci` sirve los ficheros estáticos **sin** `_headers` ni `_redirects`, así que Lighthouse no valida CSP, HSTS ni redirecciones. Eso lo cubren `npm run check` (valor exacto en `dist/_headers`) y el smoke test de `deploy.sh` contra la URL real. Lighthouse no certifica WCAG: sigue la lista manual de arriba.

## Desplegar y revertir

Proveedor: Cloudflare **Workers static assets** (`ADR-072 §4.7`; no Pages). `wrangler.jsonc`: Worker `adagiox-landing`, `assets.directory = ./dist`, sin `main`, `workers_dev: true`, **`observability.enabled: false`** (sin Workers Logs: la landing no registra peticiones propias, `ADR-073 §2.5`), **dominio personalizado solo para el ápice `adagiox.com`** (`routes` con `custom_domain`, `ADR-072 §4.3`; `www` y los demás dominios por reglas de zona, fuera de este fichero), `html_handling: auto-trailing-slash` (`/es/` sirve `es/index.html`, coherente con las URL canónicas) y `not_found_handling: 404-page` (sirve `404.html` con estado 404). Workers lee `_headers` y `_redirects` de `dist/`.

`scripts/deploy.sh` es el **único punto que conoce al proveedor**. Lo llama solo `ci-landing.yml` (job `deploy`: `push` a `main`, tras `build`, `check` y `lighthouse`, con la variable de repositorio `LANDING_DEPLOY_ENABLED = true`). El token de Cloudflare ya existe como secreto de GitHub (`CLOUDFLARE_API_TOKEN`); el alta de secretos, del *environment* `landing-production` y de la variable está en `SYSADMIN.md §4b`. Orden: exige `CLOUDFLARE_API_TOKEN` y `CLOUDFLARE_ACCOUNT_ID`; `npm run build`; `npm run check:publish` (**puerta de publicación, #582**: con marcadores `[TITULAR]`, traducciones pendientes o `dist/` obsoleto el despliegue no continúa); `wrangler deploy`; y smoke test de cabeceras (#583) con `curl` a la URL desplegada, comparando con el valor exacto de `dist/_headers` (CSP, HSTS `max-age=86400`, `nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy`, COOP y CORP).

```
CLOUDFLARE_API_TOKEN=... CLOUDFLARE_ACCOUNT_ID=... scripts/deploy.sh --dry-run   # todo menos subir
scripts/deploy.sh --url https://...                                               # fija la URL del smoke test
```

La primera petición del smoke test se **reintenta** (`DEPLOY_SMOKE_ATTEMPTS`, 10 por defecto, y `DEPLOY_SMOKE_DELAY`, 6 s): justo tras `wrangler deploy` la URL puede dar un 404 transitorio (#666). `wrangler.jsonc` lleva `preview_urls: false`. En la zona `adagiox.com` está activado *Always Use HTTPS* (ajuste de Cloudflare, no del repositorio; `ADR-072 §4.3`).

El smoke test comprueba las cabeceras de `_headers` en la portada y que `/js/carousel.js` responda 200 con tipo MIME JavaScript y `X-Content-Type-Options: nosniff`. `--dry-run` ejecuta `wrangler deploy --dry-run` y no hace smoke test. `check:publish` pasa hoy; si reaparece algún marcador, ambos modos fallan ahí, como debe.

**Redirecciones** de `www.adagiox.com` y de `adagiox.es/.eu/.org` (ápice y `www`) hacia `https://adagiox.com` (`ADR-072 §4.3`, `ADR-086 §2.2`): son **reglas de redirección de zona de Cloudflare** (no `_redirects`, que no admite nombres de host en Workers static assets), con registros `AAAA 100::` con proxy como marcador. Comprobadas con `curl` en `www.adagiox.com` y `adagiox.eu` el 2026-11-14; `adagiox.es` y `adagiox.org` esperaban la propagación de los servidores de nombres. El smoke test de `deploy.sh` no las cubre.

### Reversión (`ADR-072 §4.6`)

1. **Volver al despliegue anterior**: `npx wrangler rollback` (elige una versión anterior; requiere las mismas variables de entorno) o desde el panel de Cloudflare, Workers & Pages -> `adagiox-landing` -> *Deployments* -> *Rollback*.
2. **Revertir el commit** que introdujo el cambio en `main` y dejar que `ci-landing.yml` vuelva a desplegar.
3. **DNS**: antes de cambiar cualquier registro, anotar aquí los actuales (tipo, nombre, valor, TTL) y restaurarlos para revertir. Registros anteriores anotados: los de la zona `adagiox.com` de Cloudflare a 2026-11-13, importados de Arsys al delegar los servidores de nombres (zona `active` desde esa fecha). Todos sin proxy y con TTL automático.

   | Tipo | Nombre | Valor |
   |---|---|---|
   | CNAME | `autoconfig` | `autoconfig.serviciodecorreo.es` |
   | CNAME | `autodiscover` | `autodiscover.serviciodecorreo.es` |
   | CNAME | `webmail` | `serviciodecorreo.es` |
   | MX | `@` (prioridad 10) | `mx.serviciodecorreo.es` |
   | TXT | `@` | `v=spf1 include:_spf.serviciodecorreo.es ~all` |
   | TXT | `1794304124578._domainkey` | `v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAuEAsRBNt5ayTDFcwkz8eIy4oVKxsTPdsBRl38isZbjTp1QOQqZ16UHB2jtAuoM/9dT2B9wXLXB6ovL7mkt2xxCniwiD8mVV33uBMwj1Nkz9tsSG8GfL5Mbu2ymPwDlXGTi3wqizUYX3rR2a0jkS5ufhIVOmIRw3TbwMPV+KMsRiPvhe452bbRgt4k+mmUt31hMcrKlXRq7NM21VQzNBUPw82+JLO8gGA7puPuqZeD3zrjIlANdZnAZCWHTRzqJO986SBhNgUl0wwCUaiAif/xKHPBZ9jxced/xhptvMipZbnRNjRBlG/iZN2E4XJENvmpS3SXKI7JpZM7W6K0w01dQIDAQAB` |

   Servidores de nombres anteriores (Arsys): `dns97.servidoresdns.net` y `dns98.servidoresdns.net`; para revertir la delegación hay que volver a ponerlos en el panel de Arsys. Servidores actuales (Cloudflare): `johnny.ns.cloudflare.com` y `keyla.ns.cloudflare.com`. DNSSEC estaba desactivado en Arsys antes del cambio. Desde 2026-11-14 está **activo en Cloudflare para `adagiox.com`**, en estado `pending` hasta que Arsys ponga el DS. **Si hay que revertir la delegación con DNSSEC activo, quitar antes el DS en Arsys** (y esperar su TTL): con un DS que ya no coincide con la zona, los resolvedores que validan dejan de resolver el dominio.
4. **Parada de emergencia**: borrar la variable de repositorio `LANDING_DEPLOY_ENABLED` detiene los despliegues; `npx wrangler delete` retira el Worker.

**Pendiente explícito: la reversión (1) no se ha probado.** No hay cuenta de Cloudflare autenticada en el CLI local (el token vive solo como secreto de GitHub), así que ni el despliegue real ni el `rollback` se han ejecutado. `ADR-072 §4.6` exige ejecutarla una vez en el primer despliegue y anotar el resultado en el PR; hasta entonces, la reversión figura como **no probada**.
