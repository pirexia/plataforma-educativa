# ADR-074 · Rediseño de la landing: inglés por defecto, capturas reales, carrusel y efectos solo con CSS

**Estado**: **ACEPTADA** (2026-11-09). El usuario aceptó la recomendación de `architect` para `OPEN-074-01` a `-07` (`§10`).
**Fecha**: 2026-11-09
**Origen**: petición del usuario del 2026-11-09 (landing «mucho más moderna y con más marketing», destinada a los revisores del programa de Anthropic para *startups*), issue #579.
**Afecta a**: `apps/landing` (rutas, `scripts/`, plantillas, CSS, `locales/`, imágenes, `.lighthouserc.json`), `apps/web/e2e` y `apps/api/tests/Support` (solo soporte de pruebas, para las capturas; `§4.4`). **Ninguna línea de código de ejecución de `apps/api` ni de `apps/web`.**
**Se apoya en**: `ADR-072`, `ADR-073`, `ADR-030` y `REQ-SEED-005` (datos sintéticos), `ADR-049` (interfaz de `RNF-MANT-007` como protocolo documentado), `RNF-UX-002` (WCAG 2.2 AA).
**Sustituye en parte a `ADR-072`** (detalle en `§5`): el idioma por defecto (`§1.1` punto 6), las rutas y `x-default` de `§4.2`, quién redacta el texto (`§4.2`, «Traducción»), la política de contenido de `§8` (lo que se puede afirmar y la prohibición de capturas) y la fila de `ADR-030` de `§7` («no lleva capturas»). **No toca** `OPEN-072-05` (el texto legal sigue solo en es-ES), la CSP, el alojamiento, el DNS ni el resto de `ADR-072` y `ADR-073`.

---

## 1 · Contexto

### 1.1 · Decisiones ya tomadas por el usuario (no se reabren)

1. **Mockups = capturas reales** de la plataforma con datos sintéticos de un centro ficticio (`ADR-030`, `REQ-SEED-005`: `@example.com`, centro con nombre explícitamente ficticio, sin fotografías de personas reales), capturadas con Playwright.
2. **Contenido = solo lo implementado**, con detalle, más una sección «Próximamente» breve. Nada marcado `FUTURO` y nada que no exista.
3. **Sin JavaScript**: carrusel con `scroll-snap`, efectos de scroll con `animation-timeline: view()` como mejora progresiva, respetando `prefers-reduced-motion`. La CSP de `ADR-072 §4.4` no cambia (sin `script-src`).
4. La **página principal en inglés** (los revisores de Anthropic). Hoy son cuatro idiomas (es-ES, en, de, fr) y habrá más.

### 1.2 · Hechos verificados (rama `develop`, `074b119`)

- **Nada está publicado.** El *job* `deploy` de `ci-landing.yml` no ha corrido nunca (`LANDING_DEPLOY_ENABLED` sin crear, `memory.md`). Las URL actuales (`/` = es-ES, `/en/`, `/de/`, `/fr/`) no tienen enlaces entrantes ni indexación: cambiarlas no exige redirecciones.
- `scripts/lib.mjs` usa **una sola constante, `DEFAULT_LANG = 'es-ES'`, para tres papeles distintos**: idioma de la URL `/` y de `x-default` (`langPath`, `sitemap`, `pageVars`), idioma base de la paridad de claves (`check.mjs`, `checkLocales`) e idioma del texto legal (`build.mjs`, `text()`: las claves `legal.body.*` se leen de `DEFAULT_LANG`). Cambiar la constante a `en` sin separarla **publicaría el texto legal en inglés tomado de `en.json`**, que contiene copias de `legal.body.*` (las cuatro locales tienen 68 claves, incluidas las legales). Ese es el riesgo principal del cambio de idioma.
- Los cuatro enlaces del selector (`src/partials/header.html`) y los cinco `<link rel="alternate">` (`src/partials/head-links.html`) están **escritos a mano** en las plantillas; `.lighthouserc.json` lista las URL a mano. Añadir un idioma hoy exige tocar plantillas, no solo datos.
- `check.mjs` comprueba `src` de `<img>` pero **no `srcset`** ni `<source>`; en CSS **admite `url(data:…)`**, que la CSP (`img-src 'self'`) bloquearía en producción sin aviso; `build.mjs` copia solo `styles.css`, no hay directorio de imágenes.
- `src/styles.css` (220 líneas) ya tiene un bloque `prefers-reduced-motion: reduce` con `animation: none !important`.
- **`REQ-SEED` (`1.15b`) no está implementado.** El único centro sintético es el que crea `apps/api/tests/Support/e2e-real-tenant.php` para el E2E real; su nombre visible es la marca de seguridad `Centro ficticio e2e (CA-PERM-133)`, de la que depende su `teardown` (solo borra un centro con ese nombre), y sus personas se llaman «Daniel Docente», «Tomás Tutor Ficticio».
- La SPA tiene título propio de producto: `apps/web/src/i18n/locales/es.json` → `"title": "Plataforma de Gestión Educativa"`. Por la decisión del 2026-11-09 (pregunta 8 = b), **solo la landing usa «AdagioX»**. Si ese título sale en una captura, la landing mostrará dos nombres de producto (`OPEN-074-03`).
- Pantallas que existen hoy en `apps/web` (vistas `*View.vue`), útiles para capturas: estructura académica (`acad/StructureView`), horario con rejilla (`acad/TimetableView`), instalaciones y reservas (`acad/FacilitiesView`, `BookingsView`), escalas de calificación (`acad/GradingScalesView`), cursos académicos y apertura del curso siguiente (`curso/AcademicYearsListView`, `RolloverWizardView`), usuarios, invitaciones e importación (`core/UsersListView`, `InvitationsView`, `UserImportsView`), roles y permisos efectivos (`core/RolePermissionsView`, `UserEffectivePermissionsView`), auditoría (`core/AuditView`), configuración, marca y módulos (`core/SettingsView`, `BrandingAssetsView`, `ModulesView`), sesiones y MFA (`auth/SessionsView`, `AccountSecurityView`), unidades familiares, consentimientos y condiciones de uso (`fam-unit/*`), puerta de entrega de menores y alertas (`prl/*`).

---

## 2 · Problema

Hacer la landing más vistosa (capturas, carrusel, efectos, fondo, más detalle) **sin perder lo que justificó `ADR-072`**: cero JavaScript, CSP estricta, cero dependencias en el sitio publicado, `INV-009` comprobado, accesibilidad Lighthouse = 100 y rendimiento móvil ≥ 0,95. Y a la vez cambiar el idioma por defecto a inglés y dejar preparado añadir idiomas sin tocar plantillas.

Cada pieza nueva tiene un coste concreto: las imágenes son el primer riesgo para el LCP y para el peso de un repositorio público; las animaciones, para el contraste que mide axe; el carrusel, para el teclado; las capturas, para la regla de no usar datos reales. El ADR fija cómo se paga cada una y dice que no a lo que no compensa.

---

## 3 · Opciones reales

### 3.1 · Esquema de URL con inglés por defecto

| | Opción | Coste | Mantenimiento a 3 años | Reversibilidad |
|---|---|---|---|---|
| **A** | **`/` = en; `/es/`, `/de/`, `/fr/`** (y `/legal/`, `/es/legal/`…) | Bajo: cambia una tabla en `lib.mjs` | Bajo | Media: una vez publicado, volver a cambiar el idioma de `/` exige 301 |
| B | Todos con prefijo (`/en/`, `/es/`…) y `/` como página de elección o 302 a `/en/` | Bajo | Bajo | Alta |
| C | Detectar `Accept-Language` en el borde (regla de Cloudflare o Worker) | Medio: código o reglas fuera del repo | Medio | Alta |

B añade un salto o una página vacía antes del contenido (peor LCP y peor primera impresión para el revisor, que es quien importa ahora). C contradice `ADR-072 §4.2` y `§12` (sin redirección por idioma; rastreo peor) y mete lógica en el proveedor. **A.**

### 3.2 · Pipeline de imágenes

| | Opción | Dependencias | Calidad del resultado | Mantenimiento | Reversibilidad |
|---|---|---|---|---|---|
| A | Una sola WebP por captura (≈1280 px), sin `srcset` | Ninguna nueva… pero hace falta **algo** que codifique WebP: Playwright solo produce PNG/JPEG | Móvil descarga 2-3 veces más de lo necesario; Lighthouse marca «Properly size images» | Bajo | Alta |
| B | `vips` / `cwebp` / `avifenc` por CLI dentro de un contenedor fijado (`podman run`) | Una imagen de contenedor, sin paquete npm | Buena; `avifenc` no redimensiona, hace falta `vips`; que el paquete de la distribución traiga codificador AV1 **no está verificado** | Medio: imagen que fijar y actualizar a mano, fuera de Renovate | Alta |
| **C** | **`sharp` como dependencia de desarrollo aislada** en `apps/landing/tools/images/` (`package.json` y *lockfile* propios), llamada solo desde `tools/images/images.mjs`, ejecutada **a mano**; el resultado (AVIF + WebP + `manifest.json`) **se commitea** | Una dependencia de desarrollo, fuera del `npm ci` de CI | Buena: AVIF y WebP con varios anchos, metadatos eliminados | Bajo: Renovate y `dependency-scan.yml` la ven solos | Alta: borrar el directorio; las imágenes ya commiteadas siguen sirviendo |
| D | `sharp` dentro de `build.mjs` (las variantes se generan en cada construcción) | La misma, pero en el camino de CI | Igual | Rompe `ADR-072 §4.1` (construcción solo con módulos integrados) y descarga binarios nativos en cada *job* | Media |

**C**, condicionada a `OPEN-074-01`. A no cumple «tamaños adaptativos» y aun así necesita un codificador. B cambia una dependencia npm por una imagen de contenedor peor vigilada. D mete la dependencia donde `ADR-072` dijo que no.

### 3.3 · De dónde salen los datos de las capturas

| | Opción | Coste | Riesgo | Reversibilidad |
|---|---|---|---|---|
| A | Esperar a `REQ-SEED` (`1.15b`) | Ninguno ahora | Retrasa la landing semanas sin fecha | Alta |
| **B** | **Conjunto de datos de escaparate propio, pequeño y fijo**, creado contra la **API real** por un *spec* de Playwright en `apps/web/e2e` sobre un centro sintético de usar y tirar | Medio: un tramo | Toca el script destructivo de soporte (`e2e-real-tenant.php`): pide revisión de seguridad | Alta: se sustituye por `REQ-SEED` cuando exista |
| C | Rellenar las pantallas por SQL directo | Bajo | Salta las validaciones y la auditoría (`INV-006`, `INV-010`); datos que la API nunca produciría | Alta |

**B.** C enseñaría pantallas con datos imposibles. A no tiene fecha.

### 3.4 · Imagen de fondo

| | Opción | Peso | LCP | Licencia |
|---|---|---|---|---|
| **A** | **Degradados CSS y, si acaso, un SVG decorativo propio** (`/img/bg.svg`, mismo origen) | < 10 KB | No compite | Propia |
| B | Fotografía (banco de imágenes) en AVIF con `image-set()` | 40-150 KB | Una imagen de fondo **también puede ser el LCP** y no la descubre el escáner de precarga | Licencia del banco, atribución, repositorio público; sin personas por `REQ-SEED-005` |

**A** (`OPEN-074-04`).

---

## 4 · Decisión

### 4.1 · Idiomas y URL (opción 3.1-A)

- **Rutas**: `/` (en), `/es/`, `/de/`, `/fr/`. Legal: `/legal/`, `/es/legal/`, `/de/legal/`, `/fr/legal/`. El segmento de URL (`es`) y el código de idioma (`es-ES`, el de `ADR-021`, que va en `lang` y `hreflang`) son **dos campos distintos**.
- Cada página: `<html lang>` con el código, `canonical` a sí misma, `hreflang` a cada idioma y **`x-default` → `/`**. `sitemap.xml` igual. La 404 se sirve en inglés con enlaces a las cuatro portadas.
- **Sin redirecciones de las URL antiguas**: nunca se publicaron (`§1.2`). **Condición**: si antes de mezclar este cambio se llegara a desplegar (`LANDING_DEPLOY_ENABLED = true`), se añaden 301 de `/en/*` → `/:splat` en `_redirects`. Sigue sin haber redirección por `Accept-Language` (`ADR-072 §4.2`).
- **`lib.mjs` separa los tres papeles de `DEFAULT_LANG`** (`§1.2`):
  - `DEFAULT_LANG = 'en'`: idioma de `/`, de `x-default` y de la 404.
  - `BASE_LANG = 'en'`: base de la paridad de claves en `check.mjs`. `en` y `es-ES` son **idiomas de redacción** (`§4.7`): en ninguno de los dos se admite `_pending_translation`.
  - `LEGAL_LANG = 'es-ES'`: las claves `legal.body.*` se leen **siempre** de `es-ES.json` y se envuelven en `lang="es-ES"`, en las cuatro rutas legales (`OPEN-072-05` sigue vigente). `check.mjs` falla si `build.mjs` lee una clave `legal.body.*` de otro idioma (test con un `en.json` cuyo valor legal difiera).
- **El texto legal no cambia** en este ADR. `legal.lang.notice` (la línea que avisa de que el texto está en castellano) se redacta en inglés y es-ES por la sesión y se traduce a de/fr; no es texto legal.
- **Selector de idioma** generado desde `LANGS`, con el autónimo de cada idioma («English», «Español», «Deutsch», «Français»), `lang` y `hreflang` en cada enlace y `aria-current="page"` en el actual.

### 4.2 · Cómo se añade un idioma

`LANGS` pasa a ser la **única lista**: `[{ code: 'en', slug: '', dir: 'ltr' }, { code: 'es-ES', slug: 'es', dir: 'ltr' }, { code: 'de', slug: 'de', dir: 'ltr' }, { code: 'fr', slug: 'fr', dir: 'ltr' }]`. Las plantillas **no nombran idiomas**: `head-links` y el selector salen de variables que `build.mjs` genera recorriendo `LANGS`.

Añadir un idioma `xx` es, y el `README.md` de la landing lo documenta como procedimiento:

1. Una entrada en `LANGS` (código BCP 47, *slug*, `dir`).
2. `locales/xx.json` con todas las claves de `en.json` y `"_pending_translation": true`.
3. La clave del autónimo `lang.name.xx` en **todas** las locales (mismo valor en todas).
4. La URL de su portada en `.lighthouserc.json`.

Y `check.mjs` lo hace cumplir: paridad de claves contra `BASE_LANG`; `--publish` falla con `_pending_translation`; cada código de `LANGS` aparece en `hreflang` de cada página y en `sitemap.xml`; **cada portada de `LANGS` está en `.lighthouserc.json`** (si se olvida, CI falla); no hay dos idiomas con el mismo *slug*; `dir` es `ltr` o `rtl`. El CSS usa **propiedades lógicas** (`margin-inline`, `inset-inline-start`, `text-align: start`): cuesta lo mismo que las físicas y deja un idioma RTL a una entrada de `LANGS`. No se añade nada más «por si acaso».

### 4.3 · Imágenes (opción 3.2-C)

**Formato y marcado**
- `<picture>` con `<source type="image/avif" srcset sizes>` y `<img>` WebP de reserva con `srcset`, `sizes`, `width` y `height` (proporción real, contra el CLS) y `alt`. **Sin PNG** en `dist/`: AVIF y WebP cubren a la práctica totalidad de navegadores actuales y un PNG de captura pesa varias veces más.
- **Tres anchos por captura**: 640, 1024 y 1600 px. Más anchos multiplican el peso en Git sin mejora apreciable en el `srcset`.
- **Una sola imagen LCP por página** (la primera del carrusel): sin `loading="lazy"`, con `fetchpriority="high"` y `decoding="async"`. Todas las demás, `loading="lazy"`.
- Capturas solo en **tema claro** (doble tema duplicaría el peso). El marco de navegador o dispositivo del *mockup* se dibuja con CSS, no con imágenes.
- Las secciones de detalle **reutilizan** las mismas capturas (mismos ficheros, recorte por CSS con `object-fit`/`object-position` si hace falta); no se generan variantes nuevas por sección. **Máximo 8 capturas** en total.

**Herramienta (`RNF-MANT-007`, `CLAUDE.md §1`)**
- `apps/landing/tools/images/` con su `package.json` y *lockfile*: **`sharp`**, versión exacta fijada con al menos dos semanas de antigüedad. **Solo** `tools/images/images.mjs` la importa (la interfaz propia es ese script y su protocolo en el `README.md`, como en `ADR-049`). Lee los PNG maestros de un directorio **fuera de Git** (ignorado), genera AVIF y WebP en los tres anchos **sin metadatos**, escribe en `apps/landing/src/img/` y regenera `src/img/manifest.json` (por fichero: ancho, alto y bytes).
- `build.mjs` **no** importa `sharp`: copia `src/img/` a `dist/img/` y lee `manifest.json` para escribir `srcset`, `width` y `height`. La construcción sigue con solo módulos integrados (`ADR-072 §4.1`) y CI no instala `sharp` (no ejecuta `npm ci` en `tools/images/`).
- Antes de añadirla, la comprobación de `CLAUDE.md §1` va en el PR con datos de la fecha: mantenimiento, *releases* y licencia. **No verificado en este ADR**: según lo conocido, `sharp` es Apache-2.0 con *releases* frecuentes, y sus binarios precompilados de libvips son LGPL-3.0, aceptable para una herramienta que no se distribuye. Si no lo supera, se vuelve a `§3.2` (opción B).

**Política de binarios en Git (el repositorio es público)**
- Se commitean **solo** las variantes finales y `manifest.json`. **Nunca** los PNG maestros, ni capturas intermedias, ni capturas «de prueba».
- **Topes**, que `check.mjs` hace cumplir: cada fichero ≤ **120 KB**; `src/img/` en total ≤ **2,5 MB**.
- Cada generación de capturas va en **un solo commit** que no mezcla código. Se regeneran solo cuando la interfaz cambie de forma visible, y como mucho una vez por *release*: cada regeneración queda para siempre en el historial.
- **Revisión humana obligatoria de cada imagen antes del commit** (lista en el PR): ningún dato real; ningún nombre del centro de `ADR-015`; solo correos `@example.com`; sin números de documento visibles; sin URL, puertos ni avisos de entorno de desarrollo; sin fotografías de personas (ni reales ni generadas; avatares de iniciales).
- **Sin Git LFS**: para 2,5 MB no compensa el cupo de ancho de banda de GitHub ni la configuración extra en CI.

**Fondo** (opción 3.4-A): degradados CSS y como mucho un SVG decorativo propio en `/img/` (mismo origen, sin `data:`, que la CSP bloquearía). Ninguna imagen ráster de fondo (`OPEN-074-04`).

### 4.4 · Capturas (opción 3.3-B)

- **Dónde**: un *spec* propio en `apps/web/e2e/landing-shots.spec.ts`, que solo corre con `E2E_LANDING_SHOTS=1`, lanzado por `npm run landing:shots` (envoltorio en `apps/web/scripts/`, mismo patrón que `e2e-real-api.sh`: pila de desarrollo arriba, centro sintético creado al empezar y retirado siempre al terminar, también si falla). Se reutiliza el arnés existente (sesión con cookie y CSRF, `prepare_vite_api_url`) en lugar de duplicar Playwright y el *login* en `apps/landing`. **No es un test**: no entra en `test:e2e:real` ni en CI.
- **Datos**: el *spec* crea el escaparate **a través de la API real** (`INV-006`, `INV-010`), desde un fichero de *fixture* versionado con **valores fijos** (nombres, grupos, horario), no aleatorios: así la revisión de qué puede salir en una imagen se hace una vez, sobre texto. Nombres verosímiles e inventados, correos `@example.com`, documentos con dígito de control inválido y que **no aparezcan** en pantalla (`REQ-SEED-005`). El **nombre visible del centro** es explícitamente ficticio y legible como marketing (p. ej. «Colegio Ejemplo Los Álamos (ficticio)»; lo valida el usuario con el texto, `§4.7`), no la marca técnica `Centro ficticio e2e (CA-PERM-133)`.
- **Soporte destructivo**: si para lo anterior hay que tocar `e2e-real-tenant.php` (p. ej. un modo `showcase` cuya marca de seguridad no sea el nombre visible), **se conservan sus tres garantías**: solo `APP_ENV` local/testing y bases `plataforma`/`plataforma_test`; solo borra centros que creó él; retirada siempre. El mecanismo lo concreta el tramo con el *briefing* de `explorer`, y lo revisa `security-reviewer` (`§4.8`).
- **Salida**: PNG maestros a 1440 × 900 CSS px con `deviceScaleFactor: 2`, interfaz **en inglés** (`OPEN-074-02`), en un directorio ignorado por Git; después `tools/images/images.mjs`.
- **Reproducible en procedimiento, no en píxeles**: mismo *fixture*, mismo tamaño, mismo idioma; las fechas que muestre la interfaz pueden variar.
- **Pantallas propuestas** (`OPEN-074-06`): horario (rejilla con detección de conflictos), estructura académica, editor de permisos de un rol o permisos efectivos de un usuario, auditoría, apertura del curso siguiente, unidad familiar con tutores y custodia, puerta de entrega de menores (sin fotos), configuración de marca del centro. Antes de capturar, el tramo comprueba en qué pantallas aparece el título de producto de la SPA (`OPEN-074-03`).

### 4.5 · Carrusel, efectos y accesibilidad

**Carrusel** (cabecera)
- Lista `<ul>` de `<li><figure>` (captura + `<figcaption>`) dentro de un contenedor con `overflow-x: auto`, `scroll-snap-type: x mandatory`, `overscroll-behavior-x: contain` y la siguiente diapositiva **asomando** para indicar que hay más.
- El contenedor lleva `tabindex="0"`, `role="region"` y `aria-label` por marcador: se enfoca con `Tab`, se recorre con las flechas y se sale con `Tab` (sin trampa de teclado, WCAG 2.1.2). Chrome hace enfocables los contenedores con *scroll* por sí solo, Safari no: por eso el `tabindex` explícito (y la regla `scrollable-region-focusable` de axe).
- **Sin avance automático.** Sin JavaScript no hay forma fiable de hacerlo pausable, y sin movimiento automático no aplica WCAG 2.2.2 (pausar, detener, ocultar): **no hace falta control de pausa**. No se usa `aria-roledescription="carousel"`: promete el patrón completo de la APG, que sin JS no se puede cumplir.
- **No se usan `::scroll-button()` ni `::scroll-marker`** en esta versión: solo los soporta Chromium (que es justo el navegador con el que audita Lighthouse) y no está contrastado cómo los expone axe. Se puede añadir después dentro de `@supports`, con su propio cambio.
- En pantallas estrechas el carrusel ocupa el ancho completo; con `prefers-reduced-motion: reduce`, `scroll-behavior: auto`.

**Efectos de scroll**
- Solo dentro de `@media (prefers-reduced-motion: no-preference)` **y** `@supports (animation-timeline: view())`: sin soporte, el contenido se ve en su estado final desde el principio. Firefox no los tiene activados por defecto según lo conocido (**no verificado**): ahí la página es estática, que es el diseño.
- **Solo se anima `transform`**; nunca `opacity`, `filter` ni color en elementos con texto. Motivo con datos: axe calcula el contraste con la opacidad efectiva del momento en que audita, y un texto aún no revelado a `opacity: 0.2` da un contraste insuficiente y tira la accesibilidad de 100. `transform` además es compuesto (sin aviso de «non-composited animations») y no genera *layout shift*. `opacity` solo se admite en imágenes decorativas.
- El bloque `prefers-reduced-motion: reduce` con `animation: none !important` y `scroll-behavior: auto` se mantiene, y `check.mjs` comprueba que existe.

**Accesibilidad**: todo texto, incluidos `alt`, `figcaption` y `aria-label`, por marcador (`INV-009`). Cada `alt` describe **qué muestra la pantalla y para qué sirve**, no «captura de pantalla». La lista manual del `README.md` de la landing añade: carrusel con teclado (entrar, recorrer, salir), foco visible en el contenedor, *reflow* a 320 px con el carrusel, zoom al 200 %, animaciones desactivadas con `reduce` y objetivo táctil de 24 × 24 px (WCAG 2.5.8) en el selector de idioma.

### 4.6 · Rendimiento y comprobaciones

**Umbrales** (`.lighthouserc.json`, perfil móvil): accesibilidad = 1; buenas prácticas, SEO y rendimiento ≥ 0,95 (sin cambio). Se añaden aserciones: LCP ≤ 2 500 ms, CLS ≤ 0,05, `resource-summary:image:size` ≤ 300 KB y `resource-summary:total:size` ≤ 450 KB **en la carga inicial móvil** (lo que mide Lighthouse, con las imágenes diferidas sin cargar). URL auditadas: las portadas de todos los idiomas de `LANGS` y `/legal/`.

**`check.mjs`**, reglas nuevas (cada una con un caso válido y uno inválido en `test/`, como pide `ADR-072 §6`):
1. Todo `<img>` tiene `alt`, `width` y `height`, y los dos últimos coinciden con `manifest.json`.
2. Cada página tiene **como mucho una** imagen sin `loading="lazy"`, y esa lleva `fetchpriority="high"`.
3. Cada URL de `srcset` (de `<img>` y de `<source>`) es del mismo origen y existe en `dist/` (hoy solo se mira `src`).
4. `url()` en CSS: existe en `dist/` y **no es `data:`** (hoy se admite; la CSP la bloquea).
5. Topes de peso de `§4.3` (por fichero y total de `src/img/`) y de `manifest.json` contra los bytes reales.
6. Sigue prohibido el atributo `style` y `<style>` (CSP `style-src 'self'`): **todo el diseño nuevo va en `styles.css`**.
7. Las reglas de `§4.2` (idiomas) y el bloque `prefers-reduced-motion: reduce` de `§4.5`.

**`ci-landing.yml`**: sin *jobs* nuevos. `build` copia `src/img/`; `check` y `lighthouse` aplican lo anterior; `tools/images/` no se instala en CI. `dependency-scan.yml` (Trivy en modo `fs` sobre `.`) recoge el *lockfile* nuevo sin cambios; Renovate detecta el `package.json` nuevo por sí solo (`renovate.json` no lo agrupa: cosmético, como en `ADR-072 §7`).

### 4.7 · Texto

- **Redacta la sesión**, en **inglés y es-ES**. El usuario valida los dos antes de traducir (como `ADR-072 §8` hacía con es-ES). de y fr: más de 15 claves, así que `traductor` (`CLAUDE.md §2.9`), tratamiento formal. El texto legal no se toca ni se traduce.
- **Tono**: concreto y verificable. Verbos de lo que se puede hacer hoy en la plataforma («define», «revisa», «registra»), sin superlativos ni adjetivos de valoración («líder», «revolucionaria», «la mejor»).
- **Estado visible siempre**: «En desarrollo: aún no disponible para centros». Es lo cierto (`PLAN-IMPLEMENTACION.md`: fase 1 a medias, sin piloto) y lo que un revisor puede contrastar.
- **Qué se puede afirmar (sustituye a `ADR-072 §8`)**: una funcionalidad aparece si, y solo si, su paso o entrega de `PLAN-IMPLEMENTACION.md` está **mezclado en `develop`** en la fecha del PR de contenido. El `README.md` de la landing mantiene la tabla **afirmación → paso del plan → PR**, que `doc-reviewer` contrasta. A fecha de este ADR, el material verificable es: aislamiento entre centros (`0.7`); acceso local, sesiones, MFA (TOTP y correo), Google, SSO OIDC y SAML (`1.2`-`1.4c`); permisos granulares con editor de roles y vista de permisos efectivos (`1.5`, `1.5b`); *backoffice* de plataforma (`1.6`-`1.6e`); usuarios, invitaciones, importación, auditoría, configuración, marca y módulos del centro (`1.9b`-`1.9f`); ciclo de vida del curso, reapertura y apertura del curso siguiente (`1.10`, `1.11c`, `1.12b`); horarios con detección de conflictos (`1.12`); unidades familiares, tutores, consentimientos, condiciones de uso y entrega de menores (entregas mezcladas de `1.14` y `1.14b`); alumnado sin matrícula (`1.15-a`); cuatro idiomas de interfaz. **Antes de citar** estructura académica (`1.11-b`) y períodos y escalas (`1.11d`), el PR de contenido comprueba que estén mezclados: el plan los marca `[ ]`/`[~]`.
- **«Próximamente»**: solo pasos de fase 1 de `PLAN-IMPLEMENTACION.md` no mezclados (p. ej. matrícula y listas, asistencia, calificaciones y boletines, comunicación con familias, portales), **sin fechas**, sin nada marcado `FUTURO` (apps móviles incluidas) y sin orden prometido.
- **Sigue prohibido** (de `ADR-072 §8`): certificaciones o auditorías como logradas; cifras (módulos, centros, usuarios, número de tests, rendimiento); clientes, pilotos o centros con nombre (`ADR-015`); fechas y precios; comparaciones con competidores; **nombres o logotipos de proveedores de identidad o terceros** («funciona con Google Workspace/Microsoft Entra») salvo que un E2E real lo haya probado, y aun así sin logotipos. Lo que se pueda decir de cómo se construye el producto lo decide `OPEN-074-05`.

### 4.8 · Tramos y revisores (`CLAUDE.md §2.7`)

**Tres tramos de `implementer`**, secuenciales, con *briefing* de `explorer` antes de cada uno:

| Tramo | Alcance | Estimación |
|---|---|---|
| **T1 · Idiomas, comprobaciones y pipeline** | `LANGS` con *slug*/`dir`; `DEFAULT_LANG`/`BASE_LANG`/`LEGAL_LANG`; plantillas sin idiomas escritos; 404 en inglés; sitemap; `.lighthouserc.json`; reglas nuevas de `check.mjs` con sus tests (imágenes de *fixture* diminutas en `test/fixtures`); `build.mjs` con `src/img/` y `manifest.json`; `tools/images/` con `sharp` (si se aprueba `OPEN-074-01`) | 60-90 llamadas |
| **T2 · Capturas** | *Fixture* del escaparate, `landing-shots.spec.ts`, envoltorio `landing:shots`, ajuste del soporte destructivo si hace falta; ejecución con la pila arriba; `images.mjs`; **commit de imágenes aparte**, tras la revisión humana de `§4.3` | 60-100 llamadas |
| **T3 · Diseño y contenido** | Cabecera con carrusel y marco de *mockup*, fondo, secciones de detalle, «Próximamente», efectos, *responsive*; claves en y es-ES **ya validadas por el usuario**; después `traductor` (de, fr); `README.md` de la landing (tabla afirmación → fuente, lista manual, procedimiento de idioma nuevo e imágenes) | 80-100 llamadas |

El texto en y es-ES lo redacta la sesión mientras corren T1 y T2, para que el usuario lo valide antes de T3. `verificador` ejecuta al final `npm test`, `build`, `check`, `check:publish` y Lighthouse (o deja constancia de que solo lo corre CI).

**Revisión**: paso **crítico** (cara pública, cambios en las reglas que protegen la CSP y en un script destructivo de soporte; ante la duda, crítico). Codex primero si su régimen está activo; si no, `ADR-050`. Después `security-reviewer` (CSP y reglas de `check.mjs`, ausencia de datos reales en imágenes y *fixture*, garantías de `e2e-real-tenant.php`). `doc-precheck` y `doc-reviewer` (tabla afirmación → fuente contra el plan, coherencia con `ADR-072`/`-073`/`-074`). Sin `db-reviewer`: no hay migraciones.

---

## 5 · Qué sustituye de `ADR-072`

| `ADR-072` | Queda así |
|---|---|
| `§1.1` punto 6: «es-ES (por defecto)» | **en por defecto**; es-ES, de y fr con prefijo |
| `§4.2` «Rutas» y `x-default` → `/` (es-ES) | `§4.1` de este ADR; `x-default` → `/` (en) |
| `§4.2` «Traducción»: redacta es-ES la sesión; en/de/fr `traductor` | `§4.7`: redacta en y es-ES la sesión; de/fr `traductor` |
| `§7`, fila `ADR-030`: «no lleva capturas de la aplicación» | Capturas reales con datos sintéticos, con las condiciones de `§4.3`-`§4.4` |
| `§8` entero (afirmaciones permitidas y prohibidas) | `§4.7` |
| `§4.1` «Sin JavaScript… ni para animaciones» | **Sigue vigente**; se aclara que las animaciones son CSS |
| `§4.1` dependencias de desarrollo | Se añade `sharp`, aislada en `tools/images/` (`§4.3`) |
| `OPEN-072-05` (texto legal solo en es-ES) | **Sigue vigente**; solo cambian sus rutas |

---

## 6 · Motivo

- **`/` en inglés sin prefijo**: es lo que pidió el usuario y lo más directo para el revisor (ni salto ni página de elección). Como nada está publicado, hoy cuesta una tabla; dentro de un año, con enlaces e indexación, costaría redirecciones.
- **Separar `DEFAULT_LANG` en tres**: cambiar una sola constante habría publicado el texto legal desde `en.json`, sin aviso de ninguna comprobación actual (`§1.2`). Es el error que este ADR existe para evitar.
- **Idiomas solo en `LANGS`**: hoy añadir un idioma toca cuatro ficheros de plantilla y uno de configuración; después, datos y un fichero de idioma, y `check.mjs` atrapa lo que se olvide.
- **`sharp` aislada, imágenes commiteadas**: la construcción sigue siendo de módulos integrados y reproducible sin red; CI no descarga binarios nativos; el coste en Git queda acotado por topes que se comprueban, no por buena voluntad.
- **Escaparate por API**: la captura enseña lo que la plataforma hace de verdad, con los datos que la API admite; y no espera a `REQ-SEED`.
- **Sin autoplay, sin `::scroll-button`, solo `transform`**: cada uno elimina un riesgo medible (WCAG 2.2.2, semántica no contrastada en el navegador de auditoría, contraste de axe) a cambio de un efecto menos llamativo. Se prioriza lo reversible: los tres se pueden añadir después.

---

## 7 · Consecuencias

**Buenas**
- Sigue sin JavaScript, sin terceros y con la misma CSP; la construcción sigue sin dependencias.
- Añadir un idioma es un cambio de datos comprobado en CI.
- Las afirmaciones de la landing quedan atadas al plan: si el plan cambia, la tabla del `README.md` lo hace visible.

**Malas o a vigilar**
- **Discrepancia registrada (`CLAUDE.md §0`)**: la audiencia de la landing **después** de la solicitud a Anthropic son centros españoles, que llegarán a una portada en inglés. Con la portada indexada, volver a es-ES en `/` exigirá 301 (`/es/*` → `/:splat` y `/` → `/en/`). Se acepta porque el objetivo declarado hoy es la solicitud; queda anotado para cuando cambie.
- Unos 2,5 MB de binarios por generación de capturas, permanentes en el historial de un repositorio público.
- Las capturas **envejecen**: cada cambio visible de la SPA las deja desfasadas hasta la siguiente generación. No hay CI que lo detecte; se revisa en cada *release*.
- `apps/web/e2e` y, si hace falta, `apps/api/tests/Support` reciben código de soporte que solo sirve a la landing. Es código de pruebas, no de ejecución, pero se mantiene con la SPA.
- En navegadores sin `animation-timeline` la página es estática. Es el diseño, no un fallo.
- Una dependencia de desarrollo más (`sharp`), con binarios nativos, solo en la máquina de quien regenere capturas.

---

## 8 · Alternativas descartadas

- **Prefijo para todos y `/` como selector** (`§3.1`-B): un salto más antes del contenido.
- **Redirección por `Accept-Language`** (`§3.1`-C): contradice `ADR-072 §4.2` y `§12`.
- **Generador estático o *framework* CSS** para el rediseño: `ADR-072 §12` sigue valiendo; nada de lo pedido lo necesita.
- **Carrusel con JavaScript** (autoplay, controles): rompe la CSP sin `script-src`; decisión del usuario.
- **PNG de reserva**, **Git LFS**, **capturas en tema oscuro** y **capturas por idioma** (`OPEN-074-02`): peso o complejidad sin beneficio proporcional en esta versión.
- **`sharp` en `build.mjs`** (`§3.2`-D) y **contenedor con `vips`** (`§3.2`-B): ver `§3.2`.
- **Datos por SQL directo** (`§3.3`-C) y **esperar a `REQ-SEED`** (`§3.3`-A).
- **Fotografía de fondo** (`§3.4`-B).
- **Fuentes web autoalojadas**: más peso en la ruta crítica y sin petición del usuario; se mantiene `system-ui` (`ADR-072 §4.1`).

---

## 9 · Hallazgos fuera del ámbito de este ADR (reportados, no corregidos)

- **H1**: `check.mjs` admite `url(data:…)` en CSS (`checkDist`, bloque «CSS sin recursos externos»), que la CSP de producción bloquea sin aviso. No hay hoy ninguna, así que no es un fallo activo; la regla 4 de `§4.6` lo cierra en T1.
- **H2**: `apps/landing/README.md` dice «Sin … capturas» y que «falta la validación del usuario» del texto es-ES; quedará desfasado al aceptar este ADR y lo actualiza T3.
- **H3**: el título de producto de la SPA («Plataforma de Gestión Educativa») choca con «AdagioX» en cuanto haya capturas (`OPEN-074-03`). Renombrar la SPA no corresponde a este ADR y lo condiciona la búsqueda de marca (`OPEN-072-07`).

---

## 10 · Preguntas abiertas para el usuario

| ID | Pregunta | Recomendación del arquitecto |
|---|---|---|
| `OPEN-074-01` | Dependencia nueva para generar AVIF/WebP en varios anchos: (A) `sharp` aislada en `apps/landing/tools/images/`, ejecutada a mano, salida commiteada; (B) contenedor fijado con `vips` por CLI, sin paquete npm; (C) sin *pipeline*: una WebP por captura, sin `srcset` | **A.** B cambia una dependencia vigilada por Renovate y Trivy por una imagen de contenedor que nadie actualiza, y el soporte de AV1 del paquete de la distribución no está verificado. C no cumple «tamaños adaptativos» y aun así necesita un codificador. A queda fuera de la construcción y de CI. Condición: la comprobación de `CLAUDE.md §1` en el PR. **Resuelta: A** (usuario, 2026-11-09, la recomendación). |
| `OPEN-074-02` | Idioma de la interfaz en las capturas: (A) solo inglés, en las cuatro versiones de la página (`alt` y pies traducidos); (B) una tanda por idioma | **A.** B multiplica por cuatro el peso en Git (≈10 MB por generación) y el tiempo de captura, para un público que hoy es un revisor angloparlante. Los datos del centro (nombres) irán en castellano: enseña de paso que interfaz y contenido son independientes. **Resuelta: A** (usuario, 2026-11-09, la recomendación). |
| `OPEN-074-03` | La SPA se titula «Plataforma de Gestión Educativa» y la landing «AdagioX»: (A) elegir y encuadrar pantallas en las que el título no salga; (B) renombrar la SPA a AdagioX ya; (C) aceptar los dos nombres | **A.** B adelanta el renombrado que la decisión del 2026-11-09 dejó para después de la búsqueda de marca (`OPEN-072-07`). C confunde al revisor. A no cuesta nada si T2 lo comprueba antes de capturar; si alguna pantalla imprescindible lo enseña, se vuelve a preguntar. **Resuelta: A** (usuario, 2026-11-09, la recomendación). |
| `OPEN-074-04` | Fondo: (A) degradados CSS y un SVG decorativo propio; (B) fotografía de banco de imágenes | **A.** Una foto de fondo puede convertirse en el LCP (el escáner de precarga no la ve), trae licencia y atribución a un repositorio público, y por `REQ-SEED-005` no podría tener personas, que es lo que suelen tener las fotos de colegios. **Resuelta: A** (usuario, 2026-11-09, la recomendación). |
| `OPEN-074-05` | ¿Dice la landing cómo se construye el producto? Por ejemplo, «Desarrollado con Claude Code», con o sin enlace al repositorio público | **Una frase factual, sí** (es verificable y relevante para quien la va a leer), **sin logotipos de Anthropic** ni nada que sugiera patrocinio o asociación. El enlace al repositorio lo decide el usuario: es público y verificable, pero expone toda la documentación interna (incluido el nombre del titular de `ADR-073`, ya publicado en `/legal/`) como parte del mensaje comercial. **Resuelta: la recomendación** (usuario, 2026-11-09). |
| `OPEN-074-06` | Pantallas del carrusel y de las secciones (máximo 8): horario, estructura académica, permisos de un rol o efectivos de un usuario, auditoría, apertura del curso siguiente, unidad familiar con tutores y custodia, puerta de entrega de menores (sin fotos), marca del centro. ¿Capturas también en ancho de móvil? | **La lista tal cual, sin capturas de móvil en esta versión.** Cubre lo más diferencial y ya mezclado (permisos, auditoría, curso, familias y entrega de menores). Las capturas de móvil duplicarían ficheros con dirección de arte (`<source media>`); se añaden si el revisor lo echa en falta. **Resuelta: la recomendación** (usuario, 2026-11-09). |
| `OPEN-074-07` | Datos de las capturas: (A) escaparate propio y fijo creado por la API en un centro de usar y tirar; (B) esperar a `REQ-SEED` (`1.15b`) | **A.** B no tiene fecha. A exige tocar con cuidado el script destructivo de soporte (`§4.4`), con `security-reviewer`; se sustituye por `REQ-SEED` cuando exista. **Resuelta: A** (usuario, 2026-11-09, la recomendación). |

---

## 11 · Pasos fuera del repositorio

- **U1**: validar el texto en inglés y en es-ES (`§4.7`) antes de T3.
- **U2**: revisar a ojo, antes del commit de T2, cada imagen contra la lista de `§4.3`.
- Siguen pendientes los de `ADR-072 §11` y `ADR-073 §3` (DNS, buzón, *environment*, LaLiga, marca); este ADR no añade ninguno de despliegue.
