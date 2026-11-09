# Landing pública de adagiox.com

Sitio estático de AdagioX (`ADR-072`). Sin JavaScript, sin cookies, sin analítica, sin formularios y sin recursos de terceros. Cuatro idiomas: es-ES (por defecto), en, de y fr. **Solo esta landing usa el nombre AdagioX**; el resto del repositorio no cambia (`ADR-072 §1.1`).

Estado: **T1** (estructura, construcción y comprobaciones) y **T2** (CI, Lighthouse y despliegue, sin probar contra Cloudflare) hechos. Datos legales resueltos por `ADR-073` (titular persona física sin NIF ni domicilio publicados; correo `founders@adagiox.com`; publicación sin asesor). **No se publica nada**: el despliegue está apagado hasta `LANDING_DEPLOY_ENABLED = true`, y antes deben estar el DNS, el buzón `founders@adagiox.com` funcionando y el *environment* `landing-production` (`SYSADMIN.md §4b`).

## Estructura

| Ruta | Qué es |
|---|---|
| `src/*.html`, `src/partials/` | Plantillas con marcadores `{{t:clave}}` (texto), `{{v:nombre}}` (variable de construcción) y `{{include:parcial}}` |
| `src/styles.css` | CSS a mano que copia los valores de los *tokens* de `docs/design-system.md §4.3`; no importa nada de `apps/web` |
| `locales/{es-ES,en,de,fr}.json` | Única fuente de todo texto visible (incluidos `alt`, `title`, `aria-label`, `<title>` y `<meta name="description">`) |
| `scripts/build.mjs` | Genera `dist/` (Node 24, solo módulos integrados) |
| `scripts/check.mjs` | Comprobaciones de `ADR-072 §4.2` y `§4.5` |
| `scripts/headers.mjs` | CSP, HSTS y demás cabeceras; redirecciones de dominio |
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
- **`dist/`**: ausencia de `<script>`, `<form>`, `<iframe>`, `<style>`, atributos `style` y manejadores `on*`; ningún recurso de terceros (`src`, `<link>`, `url()`, `@import`); enlaces internos y anclas; un único `<h1>`; `lang`, `canonical` y `hreflang` correctos y recíprocos; `sitemap.xml`; presencia y valor exacto de cada cabecera de `_headers`. Los enlaces externos no se comprueban (se revisan a mano).
- **`--publish`** (bloquea la publicación): falla si queda alguna clave `_pending_translation`, algún marcador pendiente (`[TITULAR]`, `[NIF]`, `[DOMICILIO]`, `[CORREO]`, `[PROVEEDOR]`, `[GARANTIA]`, `[PLAZO]`, cualquier `[MAYÚSCULAS_1-2]`, `TODO` o `TBD`) en los locales, las plantillas **o el HTML de `dist/`**, si `dist/` no existe o es anterior a `src/` o `locales/` (por fecha de modificación). En desarrollo solo avisa.
- **Enlaces externos**: todo `<a>` a otro host http(s) debe llevar `rel="noopener noreferrer"`.

## Idiomas

- `es-ES.json` es la fuente. `en/de/fr.json` ya están traducidos (`traductor`, tratamiento formal) y no llevan `_pending_translation`. La clave `"_pending_translation": true` sigue existiendo como mecanismo: al añadir claves nuevas en es-ES, se copian a en/de/fr con ese indicador, `check` avisa y `--publish` falla hasta que se traducen y se elimina el indicador.
- **Los textos legales no se traducen automáticamente** (`CLAUDE.md §2.9`, `OPEN-072-05`): las claves `legal.body.*` se renderizan siempre desde es-ES, con `lang="es-ES"`, en las cuatro rutas `/legal/`. Solo la línea `legal.lang.notice` (aviso de idioma) se traduce.
- Rutas: `/`, `/en/`, `/de/`, `/fr/` y `/legal/`, `/en/legal/`, `/de/legal/`, `/fr/legal/`. Sin redirección por `Accept-Language`: el selector son cuatro enlaces.

### Cómo añadir un idioma (`ADR-074 §4.2`)

`scripts/lib.mjs` → `LANGS` es la única lista de idiomas (`code` BCP 47, `slug` de URL y `dir`); las plantillas no nombran idiomas. Para añadir `xx`:

1. Una entrada en `LANGS`: `{ code: 'xx', slug: 'xx', dir: 'ltr' }` (`rtl` si procede). El `slug` es el segmento de URL; el `code` va en `lang` y `hreflang`. El idioma por defecto (`DEFAULT_LANG`) es el único con `slug: ''`.
2. `locales/xx.json` con todas las claves de `en.json` y `"_pending_translation": true`. `en` (`BASE_LANG`) y `es-ES` (`LEGAL_LANG`) son idiomas de redacción y no admiten esa marca.
3. La clave `lang.name.xx` (nombre del idioma en su propia lengua) con **el mismo valor en todas** las locales.
4. La URL de su portada (`http://localhost/xx/index.html`) en `.lighthouserc.json`.

`npm run check` falla si falta algún paso (fichero de locale, autónimo, URL de Lighthouse), si hay dos idiomas con el mismo `slug` o si `dir` no es `ltr`/`rtl`; `npm run check:publish` falla mientras quede `_pending_translation`. Selector, `hreflang`, `x-default`, `canonical` y `sitemap.xml` salen de `LANGS` sin tocar plantillas. El texto legal (`legal.body.*`) sigue saliendo siempre de `es-ES`.

## Contenido: afirmación → fuente (`ADR-072 §8`)

Estado visible: «en desarrollo». Sin cifras, clientes, certificaciones, fechas, precios, capturas ni disponibilidad. El copy es-ES lo ha revisado la sesión; **falta la validación del usuario antes de publicar** (`ADR-072 §8`).

| Clave | Afirmación en la landing | Fuente en `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` |
|---|---|---|
| `home.f1.*` | Un único sistema: vida académica, económica, administrativa y comunicación con las familias | `§1.3` |
| `home.f2.*` | Varios centros en una plataforma, datos de cada uno aislados | `§2.1`, `§12` (`RMT-001`, `RMT-002`), `INV-001` |
| `home.f3.*` | Cada centro activa solo los módulos que necesita | `§1.3`, `§13` (`RMOD-001`-`004`) |
| `home.f4.*` | Marca y dominio propios para cada centro | `§1.3`, `§3.3` punto 2, `§10.4`, `§10.5` (`RUX-DOM-002`) |
| `home.f5.*` | Roles y permisos configurables por el centro | `§3.3` punto 3, `§11` |
| `home.f6.*` | Toda la funcionalidad por API | `INV-006`, `§3.3` punto 6 |
| `home.f7.*` | Interfaz en castellano, inglés, alemán y francés | `ADR-021`, `§5.1` |
| `home.f8.*` | Diseñada con el objetivo de cumplir WCAG 2.2 AA | `RNF-UX-002`, `ADR-009` |
| `home.f9.*` | Centros públicos, privados y concertados; requisitos para la normativa educativa y de protección de datos españolas, sin afirmar conformidad acreditada | `§2.1`, `§4.1`, `§4.3` |
| `home.status.*`, `footer.status` | «En desarrollo»; lo descrito son objetivos, no funciones disponibles | `ADR-072 §8` |

Página legal (`legal.body.*`): estructura de `ADR-072 §4.3` y `§4.4` (identificación del art. 10 LSSI-CE; información del art. 13 RGPD: responsable, datos, finalidad, base legal, encargado, transferencia, plazo, derechos, cookies), con los datos de `ADR-073`: titular persona física sin NIF ni domicilio publicados, encargados Cloudflare, Inc. y Arsys Internet, S.L.U., garantía de la transferencia, plazo de conservación y tratamiento de los correos de `founders@adagiox.com`. Ya no es un borrador con marcadores; se publica **sin revisión de asesor** (`ADR-073 §2.4`) y `check:publish` pasa. Los marcadores (`[TITULAR]`, `[NIF]`, `[DOMICILIO]`, `[CORREO]`, `[PROVEEDOR]`, `[GARANTIA]`, `[PLAZO]`) siguen bloqueando la publicación si reaparecen.

## Capturas (`ADR-074 §4.4`)

Las imágenes de la cabecera y de las secciones son capturas **reales de la aplicación** con un centro de escaparate de datos sintéticos. No es un test: no entra en `test:e2e:real` ni en CI.

```bash
# Con la pila de desarrollo arriba (plataforma-api en :8000 y plataforma-postgres):
cd apps/web && npm run landing:shots
```

- **Qué hace** (`apps/web/scripts/landing-shots.sh`): crea por el soporte destructivo de la API (`apps/api/tests/Support/e2e-real-tenant.php setup demo showcase`) el centro «Colegio Ejemplo Los Álamos (ficticio)», lanza `apps/web/e2e/landing-shots.spec.ts` y **lo retira siempre** al terminar, también si falla (`trap`).
- **Datos**: `apps/web/e2e/fixtures/landingShowcase.ts`, con valores fijos (centro, etapas, grupos, asignaturas, aulas, marco horario, horario de 1.º ESO A y B, rol personalizado, unidad familiar con tres tutores y custodia, alumnado, persona autorizada a recoger, marca). Todo se da de alta por la API real con la sesión del administrador; solo los tres docentes activos y el administrador los deja el soporte. Nombres inventados, correos `@example.com`, **sin documentos de identidad, direcciones ni fotografías** (el logo es un SVG sintético).
- **Slug `demo`**: la CORS de desarrollo de la API (`CORS_ALLOWED_ORIGINS`) solo admite `http://demo.plataforma.test:5173`. El soporte solo crea y retira un `demo` que lleve el nombre fijo del escaparate **y** su administrador (`elena.marin@example.com`); un `demo` de otro origen no se toca.
- **Estado (tramo T2b)**: el spec captura las ocho pantallas (`hero-schedule`, `academic-structure`, `role-permissions`, `audit-log`, `year-rollover`, `family-unit`, `pickup-door`, `school-branding`) a 1440 × 900 CSS px, `deviceScaleFactor: 2`, en inglés, y guarda `<id>.png` y `<id>.txt` (texto visible, para la revisión) en `apps/landing/tools/images/masters/` (ignorado por Git). Falla si falta una pantalla, si hay un 4xx/5xx inesperado o si el texto contiene un correo no `@example.com`, marca E2E, URL de desarrollo o documento. Después, `node tools/images/images.mjs` genera `src/img/` y `manifest.json`; **se revisa a ojo cada imagen** (lista de `ADR-074 §4.3`) y se commitean aparte, sin PNG.
- **Requisitos**: para la pantalla de marca (logo) hace falta un almacén S3 en `minio:9000` (MinIO o el servidor falso de `docs/modulos/REQ-PRL/operacion.md §12.7`); la cola `long` (`worker-long`) solo si un paso ejecuta la copia del curso siguiente.

## Lista manual de accesibilidad (WCAG 2.2 AA)

Lighthouse/axe no certifican conformidad. En cada cambio de contenido o de estilos, comprobar a mano:

- [ ] Teclado: todo es alcanzable y el foco es siempre visible; el *skip link* aparece al primer `Tab`.
- [ ] Contraste de texto y de componentes (los *tokens* ya cumplen `docs/design-system.md §4.4`).
- [ ] `lang` en los cambios de idioma (WCAG 3.1.2): enlaces del selector y bloque legal en es-ES.
- [ ] *Reflow* a 320 px de ancho sin desplazamiento horizontal.
- [ ] Zoom al 200 % sin pérdida de contenido.
- [ ] `prefers-reduced-motion` y `prefers-color-scheme` respetados.
- [ ] Un único `h1` por página y jerarquía de títulos sin saltos.

## Lighthouse

`npm run lighthouse` (`lhci autorun`, `@lhci/cli` 0.16.0) sirve `dist/` con `staticDistDir` y audita en perfil móvil las cuatro portadas (es, en, de, fr) y `/legal/`, tres pasadas cada una. Umbrales (`ADR-072 §4.5`, `.lighthouserc.json`): accesibilidad = 1; buenas prácticas, SEO y rendimiento >= 0,95. Necesita Chrome (en CI viene en el runner; en local, `CHROME_PATH`). Informes en `.lighthouseci/` (ignorado por Git).

**Limitación**: `lhci` sirve los ficheros estáticos **sin** `_headers` ni `_redirects`, así que Lighthouse no valida CSP, HSTS ni redirecciones. Eso lo cubren `npm run check` (valor exacto en `dist/_headers`) y el smoke test de `deploy.sh` contra la URL real. Lighthouse no certifica WCAG: sigue la lista manual de arriba.

## Desplegar y revertir

Proveedor: Cloudflare **Workers static assets** (`ADR-072 §4.7`; no Pages). `wrangler.jsonc`: Worker `adagiox-landing`, `assets.directory = ./dist`, sin `main`, `workers_dev: true`, **`observability.enabled: false`** (sin Workers Logs: la landing no registra peticiones propias, `ADR-073 §2.5`), **sin rutas ni dominios personalizados** (el DNS aún no existe, `OPEN-072-04`), `html_handling: auto-trailing-slash` (`/en/` sirve `en/index.html`, coherente con las URL canónicas) y `not_found_handling: 404-page` (sirve `404.html` con estado 404). Workers lee `_headers` y `_redirects` de `dist/`.

`scripts/deploy.sh` es el **único punto que conoce al proveedor**. Lo llama solo `ci-landing.yml` (job `deploy`: `push` a `main`, tras `build`, `check` y `lighthouse`, con la variable de repositorio `LANDING_DEPLOY_ENABLED = true`). El token de Cloudflare ya existe como secreto de GitHub (`CLOUDFLARE_API_TOKEN`); el alta de secretos, del *environment* `landing-production` y de la variable está en `SYSADMIN.md §4b`. Orden: exige `CLOUDFLARE_API_TOKEN` y `CLOUDFLARE_ACCOUNT_ID`; `npm run build`; `npm run check:publish` (**puerta de publicación, #582**: con marcadores `[TITULAR]`, traducciones pendientes o `dist/` obsoleto el despliegue no continúa); `wrangler deploy`; y smoke test de cabeceras (#583) con `curl` a la URL desplegada, comparando con el valor exacto de `dist/_headers` (CSP, HSTS `max-age=86400`, `nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy`, COOP y CORP).

```
CLOUDFLARE_API_TOKEN=... CLOUDFLARE_ACCOUNT_ID=... scripts/deploy.sh --dry-run   # todo menos subir
scripts/deploy.sh --url https://...                                               # fija la URL del smoke test
```

`--dry-run` ejecuta `wrangler deploy --dry-run` y no hace smoke test. `check:publish` pasa hoy; si reaparece algún marcador, ambos modos fallan ahí, como debe.

**Pendiente (no probado)**: el redireccionamiento de `www` y de `adagiox.es/.eu/.net` hacia `https://adagiox.com` (`ADR-072 §4.3`). Las reglas de `_redirects` con nombre de host no se han verificado en Workers static assets; se prevén como reglas de redirección de la zona de Cloudflare y el smoke test no las cubre todavía. Hay que comprobarlo en el alta.

### Reversión (`ADR-072 §4.6`)

1. **Volver al despliegue anterior**: `npx wrangler rollback` (elige una versión anterior; requiere las mismas variables de entorno) o desde el panel de Cloudflare, Workers & Pages -> `adagiox-landing` -> *Deployments* -> *Rollback*.
2. **Revertir el commit** que introdujo el cambio en `main` y dejar que `ci-landing.yml` vuelva a desplegar.
3. **DNS**: antes de cambiar cualquier registro, anotar aquí los actuales (tipo, nombre, valor, TTL) y restaurarlos para revertir. Registros anteriores anotados: *ninguno todavía; la zona no existe*.
4. **Parada de emergencia**: borrar la variable de repositorio `LANDING_DEPLOY_ENABLED` detiene los despliegues; `npx wrangler delete` retira el Worker.

**Pendiente explícito: la reversión (1) no se ha probado.** No hay cuenta de Cloudflare autenticada en el CLI local (el token vive solo como secreto de GitHub), así que ni el despliegue real ni el `rollback` se han ejecutado. `ADR-072 §4.6` exige ejecutarla una vez en el primer despliegue y anotar el resultado en el PR; hasta entonces, la reversión figura como **no probada**.
