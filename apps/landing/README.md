# Landing pública de adagiox.com

Sitio estático de AdagioX (`ADR-072`). Sin JavaScript, sin cookies, sin analítica, sin formularios y sin recursos de terceros. Cuatro idiomas: es-ES (por defecto), en, de y fr. **Solo esta landing usa el nombre AdagioX**; el resto del repositorio no cambia (`ADR-072 §1.1`).

Estado: **T1** (estructura, construcción y comprobaciones). Desplegar, revertir, `ci-landing.yml`, Lighthouse y `deploy.sh` son el tramo **T2** y no existen todavía.

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
- **`--publish`** (bloquea la publicación): falla si queda alguna clave `_pending_translation` en en/de/fr o algún marcador pendiente del titular (`[TITULAR]`, `[NIF]`, `[DOMICILIO]`, `[CORREO]`, `[PROVEEDOR]`, `[GARANTIA]`, `[PLAZO]`). En desarrollo solo avisa.

## Idiomas

- `es-ES.json` es la fuente. `en/de/fr.json` llevan `"_pending_translation": true` y el texto es-ES copiado hasta que los traduzca `traductor` (tratamiento formal). Al traducir se **elimina** esa clave.
- **Los textos legales no se traducen automáticamente** (`CLAUDE.md §2.9`, `OPEN-072-05`): las claves `legal.body.*` se renderizan siempre desde es-ES, con `lang="es-ES"`, en las cuatro rutas `/legal/`. Solo la línea `legal.lang.notice` (aviso de idioma) se traduce.
- Rutas: `/`, `/en/`, `/de/`, `/fr/` y `/legal/`, `/en/legal/`, `/de/legal/`, `/fr/legal/`. Sin redirección por `Accept-Language`: el selector son cuatro enlaces.

## Contenido: afirmación → fuente (`ADR-072 §8`)

Estado visible: «en desarrollo». Sin cifras, clientes, certificaciones, fechas, precios, capturas ni disponibilidad. Los textos de abajo son un **borrador** que debe validar el usuario antes de traducirse.

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

Página legal (`legal.body.*`): estructura de `ADR-072 §4.3` y `§4.4` (identificación del art. 10 LSSI-CE; información del art. 13 RGPD: responsable, datos, finalidad, base legal, encargado, transferencia, plazo, derechos, cookies). **Es un borrador con marcadores, no un texto legal revisado**: lo redacta o encarga el usuario (`U4`). Los marcadores `[TITULAR]`, `[NIF]`, `[DOMICILIO]`, `[CORREO]` son los del ADR; `[PROVEEDOR]`, `[GARANTIA]` y `[PLAZO]` dependen del proveedor elegido en `U1` (`OPEN-072-02`) y se añadieron para no inventar datos. Ninguno puede publicarse (`--publish` falla).

## Lista manual de accesibilidad (WCAG 2.2 AA)

Lighthouse/axe no certifican conformidad. En cada cambio de contenido o de estilos, comprobar a mano:

- [ ] Teclado: todo es alcanzable y el foco es siempre visible; el *skip link* aparece al primer `Tab`.
- [ ] Contraste de texto y de componentes (los *tokens* ya cumplen `docs/design-system.md §4.4`).
- [ ] `lang` en los cambios de idioma (WCAG 3.1.2): enlaces del selector y bloque legal en es-ES.
- [ ] *Reflow* a 320 px de ancho sin desplazamiento horizontal.
- [ ] Zoom al 200 % sin pérdida de contenido.
- [ ] `prefers-reduced-motion` y `prefers-color-scheme` respetados.
- [ ] Un único `h1` por página y jerarquía de títulos sin saltos.

## Desplegar y revertir

**T2** (pendiente): `scripts/deploy.sh`, `ci-landing.yml`, Lighthouse y reversión probada (`ADR-072 §4.5`, `§4.6`).
