# REQ-CORE · Capa visual de la interfaz (paso `1.15a`) · Especificación

| Campo | Valor |
|-------|-------|
| Paso | **`1.15a` · Capa visual de la interfaz** (`PLAN-IMPLEMENTACION.md`), antes de `1.15b` (`REQ-SEED`) |
| Código | `REQ-CORE` (módulo `core`, esencial) y `docs/design-system.md` (sistema de diseño). **No hay módulo nuevo** (§1) |
| Prioridad | MUST (`RUX-001`, `RUX-002`, `RUX-004`, `RNF-UX-002`); la personalización del texto (`RUX-BRAND-005`) según `OPEN-UI-01` |
| Fase | 1 |
| Depende de | `core` (1.1, `GET /tenant/branding`), design system (1.7), *shell* (1.8), pantalla de marca (1.9e), contenido traducible (`ADR-062`, 1.11). Todo implementado |
| Esencial | `core` ya es `essential: true`; no cambia |
| Estado | **PROPUESTO** (2026-11-12). Sin aprobar; con preguntas bloqueantes (§16) |
| Decisión de arquitectura | `ADR-064` (ACEPTADA 2026-10-16). Este documento la **desarrolla** y decide solo lo que su `§6` delega |
| **Clasificación del paso** | **`1.15a-a`: ordinario · `1.15a-b`: crítico** (`ADR-069 §4.2`; motivo y condiciones en §4) |

### Prefijos de este documento

| Prefijo | Qué es |
|---------|--------|
| `CA-UI-NNN` | Criterio de aceptación (§12). Los tests lo citan en su nombre (`INV-015`). `001`-`039` son de `1.15a-a`; `040`-`069`, de `1.15a-b` |
| `RN-UI-NN` | Regla de este documento. Las reglas `RN-DS-25` a `-29` ya aprobadas no se renumeran: se citan |
| `OPEN-UI-NN` | Pregunta abierta (§16) |

**Lo que este documento no hace**: no reabre `ADR-064` ni `ADR-052`, no propone otro ADR sobre lo ya decidido y no decide por su cuenta lo que `ADR-064 §6.4` deja «no decidido». Cada regla cita su fuente; lo que no sale de `REQUISITOS` o de un ADR va marcado **(propuesta)** y tiene su `OPEN-UI-*`.

---

## 1. Ubicación de esta especificación

**Un único fichero, `docs/modulos/REQ-CORE/capa-visual.md`**, con las cinco partes de `docs/modulos/_PLANTILLA/` como secciones (funcional §5-§6, datos §7, API §8, permisos §9, operación §10). Motivos:

1. **No es un módulo.** `ADR-052 §6` y la cabecera de `docs/design-system.md` fijan que el sistema de diseño «no es un *bounded context*; no tiene datos, API ni permisos» y por eso vive en `docs/design-system.md`, como `docs/i18n.md`. `REQUISITOS` no define ningún `REQ-UI`: crear `docs/modulos/REQ-UI/` sería inventar un código de módulo (`CLAUDE.md §11`) y cinco ficheros casi vacíos.
2. **La única parte con datos, API y permisos es de `core`**: `tenant_settings`, `GET /tenant/branding`, `PATCH /tenant/settings` y la pantalla `/administracion/centro/marca` son de `REQ-CORE` (`REQ-CORE/api.md §2`, `funcional.md §14.10`). Precedente: la interfaz de `REQ-CORE` (1.8, 1.9) se documentó dentro de `REQ-CORE`, y `1.12b` dentro de `REQ-CURSO`.
3. **Fichero propio y no secciones nuevas en los cinco existentes**: `REQ-CORE/funcional.md` pesa 461 KB; añadirle una especificación sin aprobar mezcla lo aprobado con lo propuesto y encarece cada lectura (`CLAUDE.md §2`, ahorro de cuota). Mismo criterio que la entrega `S-2` (`REQ-CORE/almacen-objetos.md`).
4. **Al cerrar cada entrega**, lo normativo se traslada a su sitio canónico (§14): reglas visuales y tokens a `docs/design-system.md §4`, `§13`, `§22`; contrato a `REQ-CORE/{api,datos,permisos,operacion}.md`. Este fichero queda como especificación del paso.

Apartados de la plantilla que **no aplican**: descriptor del módulo (no cambia `moduleDescriptor()` de `core`), comportamiento con el módulo desactivado (`core` es esencial), lectura de curso cerrado (ningún dato con `academic_year_id`), ámbito restringido (el único ámbito es `todos`).

---

## 2. Fuentes y estado de partida

### 2.1 Fuentes

| Fuente | Qué aporta |
|--------|-----------|
| `ADR-064` (ACEPTADA) `§2`-`§4` | Disposición «Panel», sombra direccional como única excepción, `--radius` a 2 px. **Decidido; no se toca** |
| `ADR-064 §6` | Lo que delega aquí: 1 texto de bienvenida y API; 2 ilustración por defecto y encuadre; 3 valores finales de tokens y derivadas del radio; 4 resto de la capa visual no decidido; 5 tests y token de sombra permitido |
| `docs/design-system.md §22` | `RN-DS-25` a `-29` aprobadas y pendientes de implementar; `§4`, `§10`, `§11`, `§13` |
| Issue #431 y sus dos comentarios | Decisiones del usuario del 2026-10-16 (la 5: «imagen y texto de bienvenida los elige el centro») y lista de pendientes |
| Maqueta versión 5 (privada, enlazada en `ADR-064 §7`), leída el 2026-11-12 | Valores de referencia: rejilla, sombra, velo, franja móvil, contenido del pie |
| `REQUISITOS §10.1`-`§10.4` | `RUX-001`, `-002`, `-004`, `-005`, `RUX-RESP-001`/`-007`, `RUX-ICON-005`/`-007`, `RUX-BRAND-004`, **`RUX-BRAND-005`** («Textos y mensajes del sistema personalizables»), `RNF-UX-002` |
| `ADR-062` | Contenido del centro traducible (`<campo>_translations`, resolución en servidor, `PATCH` por fusión, auditoría por idioma, `AR-16`) |
| `REQ-CORE/api.md §2`, `datos.md §A.1` | Contrato actual de `GET /tenant/branding` y de `tenant_settings` |

### 2.2 Estado verificado contra el código (2026-11-12, `b7995bc`)

- `apps/web/src/design-system/tokens.css`: `--radius: 0.625rem` en `:root`; no existe ningún token `--shadow-*`; `--overlay` es el único velo.
- `apps/web/src/style.css` (`@theme inline`): `--radius-sm = calc(var(--radius) - 4px)`, `-md = … - 2px`, `-lg = var(--radius)`, `-xl = … + 4px`. No define `xs`, `2xl`, `3xl`, `4xl`: esas utilidades usan hoy los valores por defecto de Tailwind v4, **que no derivan de `--radius`**.
- `PublicAuthShell.vue`: tarjeta centrada (`max-w-sm rounded-xl border p-6 shadow-sm`) sobre `bg-muted` o sobre la imagen del centro como fondo CSS. Sin pie, sin texto de bienvenida, sin selector de idioma. Lo usan siete vistas de `modules/auth/views` (acceso, recuperación ×2, desbloqueo, invitación, resultado de Google y de SSO).
- `usePublicAuthScreen.ts`: fija el idioma en cada montaje como `navigator.languages` ∩ idiomas activos → `default_locale` (`CA-AUTH-063`). **No hay elección manual de idioma en las pantallas públicas.**
- `GET /tenant/branding` (`TenantController::branding`): ocho campos exactos; `api.md §2` declara «regla no negociable: la respuesta no contiene ningún campo más; añadir uno es publicar información en Internet y exige justificación en la revisión de seguridad».
- `TenantMigration::translatableColumn()` crea la columna `NOT NULL` y su comentario dice que, para una tabla que ya existe con filas, «ese caso lo decide la especificación del módulo que lo necesite» (relevante para §7).
- `apps/web/e2e/landing-shots.spec.ts` captura `sign-in` en `/entrar` sin sesión; `apps/landing/README.md` y `ADR-075 §3` avisan de que esa imagen queda desfasada con este paso.

Lo que **no** se pudo comprobar está en §17 (la sesión de especificación no tenía `grep` ni `Bash`).

---

## 3. Alcance y entregas

El paso se parte en **dos entregas** (`CLAUDE.md §2.7`), porque mezcla un cambio solo de `apps/web` con un cambio de datos y de un *endpoint* público, que tienen riesgo, revisores y clasificación distintos.

| Entrega | Contenido | Código | Tramos | Clasificación |
|---------|-----------|--------|--------|---------------|
| **`1.15a-a` · Capa visual** | Tokens (radio, sombra, velo), derivadas del radio, pantalla de acceso «Panel», bienvenida **genérica traducida**, ilustración por defecto, selector de idioma del pie, tests de arquitectura, capturas antes/después | Solo `apps/web` (y la imagen `sign-in` de `apps/landing` si `1.15a-b` no va a continuación) | 2 | Ordinario |
| **`1.15a-b` · Bienvenida personalizable por el centro** | Texto de bienvenida como contenido traducible del centro (`RUX-BRAND-005`, parcial), publicado en `GET /tenant/branding`, editado en `/administracion/centro/marca` | `apps/api` (módulo `Core`) y `apps/web` | 3 | **Crítico** |

`1.15a-a` deja la pantalla completa y correcta por sí sola: si el usuario aplaza `1.15a-b` (`OPEN-UI-01`), no queda nada a medias.

### 3.1 No entra (y dónde iría)

| Fuera | Motivo y destino |
|-------|------------------|
| Color de marca en el interior de la aplicación (elemento activo del menú, cabecera o barra lateral teñidas), acentos | `ADR-064 §6.4`: no decidido. Parte de ello cambia `RN-DS-02` y exige ADR propio (§11). `OPEN-UI-10` |
| Ilustraciones en estados vacíos y página de inicio | `ADR-064 §6.4`: no decidido; `RUX-ICON-005`/`-007`. `OPEN-UI-11` |
| Resto de `RUX-BRAND-005` («textos y mensajes del sistema personalizables» más allá de la bienvenida del acceso) | Ningún documento lo especifica; `1.15a-b` cubre **solo** la bienvenida y **no** da el requisito por cumplido |
| Elementos que la maqueta muestra y `ADR-064` no decide: botón «Mostrar» de la contraseña, separador «o entra con», monograma cuando no hay logo, subtítulo bajo el nombre del centro, antetítulo «Curso 2026-2027» | No inventar requisitos. `OPEN-UI-13`. El curso académico, además, no es dato del *endpoint* público |
| Punto focal o recorte configurable de la imagen del centro | Ningún requisito lo pide; se resuelve con texto de ayuda (`RN-UI-13`) |
| Tipografía e identidad de la plataforma | `0.11c` (`ADR-052 P4`) |
| Cambios en autenticación, sesión, CSRF, MFA o en los formularios de `REQ-AUTH` | Este paso cambia el marco visual, no los flujos |

---

## 4. Clasificación del paso: crítico | ordinario

**`Clasificación del paso: 1.15a-a ordinario · 1.15a-b crítico`** (`ADR-069 §4.2`). La sesión lo comprueba contra el diff.

**`1.15a-a` · ordinario.** No toca migraciones, *endpoints*, *policies*, permisos, resolutores, *middleware*, sesión, ficheros subidos, datos de categoría especial, datos de menores, dinero ni tablas de plataforma. Cambia hojas de tokens, un componente de marco y la resolución del idioma de pantallas públicas. `ADR-069 §1` punto 6 ya contaba `1.15a` entre los ordinarios. **Pasa a crítico si el diff toca cualquiera de estos**: algo bajo `apps/api/`; la siembra de la cookie CSRF o cualquier llamada de `usePublicAuthScreen.ts` que no sea la del idioma; `apiFetch`, `src/session/**`, *guards* del *router*; cabeceras o CSP; lo que `useTenantBranding` guarda en `localStorage` (`RN-DS-08`).

**`1.15a-b` · crítico**, sin duda: crea una tabla con RLS (según `OPEN-UI-02`), modifica un *endpoint* **anónimo y público** cuya lista de campos es cerrada por regla de seguridad, modifica `PATCH /tenant/settings` y publica en Internet texto escrito por un usuario. Revisores: Codex, `security-reviewer` y `db-reviewer`.

## 5. Funcional de `1.15a-a`: capa visual

Actores: cualquier visitante de una pantalla pública del centro (sin sesión) y, por el radio, todo usuario de la aplicación. Sin eventos de dominio, sin interacción con otros módulos. Design system único: **shadcn-vue** vendorizado en `src/components/ui/` (`ADR-023`, `CLAUDE.md §1`); **no se añade ninguna librería de componentes ni ninguna dependencia** (`RN-UI-01`).

### 5.1 Tokens nuevos y modificados (`ADR-064 §6.3`)

Todos en `apps/web/src/design-system/tokens.css` (única hoja de tokens, `CA-DS-001`); el mapeo a utilidades, en `@theme inline` de `style.css`. Los valores de color en `oklch()`, como el resto de la hoja.

| Token | Claro (`:root`) | Oscuro (`.dark`) | Fuente y nota |
|-------|-----------------|------------------|---------------|
| `--radius` | `2px` | no se redefine | `ADR-064 §4.1`, `RN-DS-25` |
| `--shadow-auth-panel` | dos capas: desplazamiento `-2px 0`, desenfoque `6px`, negro al 28 %; y `-12px 0`, `40px`, negro al 42 % | **igual** | `ADR-064 §3.1` (valor de la maqueta). La sombra cae sobre la imagen, que no cambia con el modo: por eso no hay variante oscura (la maqueta tampoco la tiene). Mapeo: utilidad `shadow-auth-panel` |
| `--scrim-auth` | negro al **64 %** | **igual** | `RN-DS-29`. **(propuesta de valor)** Sustituye al velo teñido de la maqueta (`rgba(6,30,37,.72)`), que era del color del centro de demostración: un velo teñido por la marca sería un semántico nuevo dependiente de la marca y cambiaría `RN-DS-02` (§11). Mismo criterio que `--overlay` (`§4.9`): color fijo, igual en ambos modos |
| `--on-scrim` | blanco (`oklch(1 0 0)`) | **igual** | Color del texto sobre el velo. No puede ser `text-white` (`RN-DS-19`) ni `--primary-foreground` (depende de la marca) |
| `--auth-panel-width` (en `@theme`, geometría, como los *breakpoints* de `§4.10`) | `clamp(22.5rem, 30vw, 28.75rem)` | — | `ADR-064 §2.1`, `RN-DS-26`: 360-460 px a tamaño de letra por defecto; en `rem` para que escale con la letra del usuario (WCAG 1.4.4) |
| `--auth-visual-min-height` (en `@theme`) | `11.25rem` (180 px) | — | Franja de imagen en móvil; valor de la maqueta **(propuesta)** |

**`RN-UI-02`** · Por qué 64 %: con texto blanco, el peor fondo posible es una imagen blanca. El contraste blanco sobre (negro al α sobre blanco) alcanza 4,5:1 a partir de α ≈ 0,54 (cálculo con la fórmula de WCAG 2.x); 0,64 deja margen frente al redondeo a 8 bits (misma prudencia que `§4.4` con `--input`). **El test es la autoridad** (`CA-UI-007`): si no alcanza el umbral, el implementador sube α en pasos de 0,02 y actualiza esta tabla en el mismo *commit*.

**`RN-UI-03`** · `tokens-contrast.spec.ts` (`§11`) **ignora de forma explícita y nominal** los tokens `--shadow-*` (no son un color, son una composición) y sigue fallando ante cualquier semántico de color en formato no admitido (`CA-DS-006` no se debilita). Añade el par nuevo: `on-scrim` sobre `scrim-auth` compuesto sobre blanco y sobre negro, umbral 4,5.

### 5.2 Radio de 2 px y sus derivadas (`ADR-064 §4.2`, `RN-DS-25`)

**`RN-UI-04`** · En `@theme inline`, **toda la escala** de radios se define y vale `var(--radius)`: `--radius-xs`, `-sm`, `-md`, `-lg`, `-xl`, `-2xl`, `-3xl`, `-4xl`. Sin `calc()`. Motivos: (a) con base `2px` las fórmulas actuales dan `-2px` y `0px` (`ADR-064 §7`); (b) las cuatro que hoy no se definen heredan valores de Tailwind ajenos a `--radius`, y un componente vendorizado que las use (p. ej. una insignia con `rounded-4xl`) se saltaría la decisión de «un único token» (issue #431, decisión 1); (c) a 2 px una jerarquía de radios no se percibe. Alternativas en `OPEN-UI-08`.

**`RN-UI-05`** · Excepciones al radio de 2 px, **lista cerrada en el test** (`CA-UI-003`): `rounded-full` solo en elementos que son círculos por significado o por forma —el indicador de `radio-group` (un botón de opción cuadrado se confunde con una casilla), indicadores de carga giratorios y avatares o puntos de estado si existen—. El implementador parte del inventario del *briefing* de `explorer`; cada entrada se justifica en el test. `rounded-none` y los valores arbitrarios (`rounded-[…]`) quedan prohibidos (regla 5 de `§22`). Las insignias (`badge`) pasan a 2 px (`OPEN-UI-09`).

**`RN-UI-06`** · El indicador de foco no cambia de regla (`RN-DS-23`, `CA-DS-043`): borde `ring` de opacidad completa, que sigue el radio del control. Se comprueba que con 2 px sigue visible y no queda recortado por el panel (`CA-UI-015`).

**`RN-UI-07`** · El cambio es global (`ADR-064 §7`): antes de mezclar, `verificador` genera **capturas antes y después** de una lista cerrada de pantallas (acceso, panel de inicio, listado de usuarios con menú abierto, diálogo de confirmación, formulario de ajustes, matriz de permisos, horarios) en claro y oscuro, fuera de Git, y el usuario las revisa. No se añade ninguna herramienta de comparación de píxeles (dependencia nueva sin requisito).

### 5.3 Sombra (`ADR-064 §3`, `RN-DS-28`)

**`RN-UI-08`** · La utilidad `shadow-auth-panel` aparece en **un solo fichero**, `PublicAuthShell.vue`, y solo desde el *breakpoint* de dos columnas; por debajo, sin sombra. Lo comprueba un test de arquitectura (`CA-UI-006`), que además fija la regla 3 de `§22` en CI: las utilidades `shadow-*` distintas de `shadow-none` solo se admiten en `src/components/ui/**` (capas flotantes vendorizadas) y en una lista cerrada de ficheros que el *briefing* inventaría; cualquier otro uso que aparezca se corrige en esta entrega (la tarjeta actual de `PublicAuthShell`, con `shadow-sm`, desaparece). «Ninguna otra superficie la usa por analogía; una excepción más exige otro ADR» (`ADR-064 §3.3`).

### 5.4 Pantalla de acceso «Panel» (`ADR-064 §2`, `RN-DS-26`, `RN-DS-27`)

Afecta a `PublicAuthShell.vue` y, por herencia, a las siete vistas públicas que lo usan. Las vistas no cambian de comportamiento ni de pruebas, salvo lo que aquí se indica.

1. **Dos regiones**, en este orden en el DOM y en pantalla: **región visual** (imagen y bienvenida) y **panel de acceso**. El orden del DOM coincide con el visual en escritorio (izquierda → derecha) y en móvil (arriba → abajo); la región visual no tiene ningún elemento enfocable (WCAG 1.3.2, 2.4.3).
2. **Escritorio** (desde el *breakpoint* `md`, 48rem; `RN-UI-09`): rejilla de dos columnas, la visual ocupa el resto y el panel mide `--auth-panel-width`, a toda la altura de la ventana (`min-h-svh`), con `bg-background` y la sombra de §5.3. Si el contenido del panel es más alto que la ventana, se desplaza la página, no una caja interior.
3. **Móvil** (por debajo de `md`): una columna; la región visual es una franja superior de altura mínima `--auth-visual-min-height` que muestra **solo el título** de bienvenida; el panel ocupa el resto, sin sombra. Sin desplazamiento horizontal a 320 px ni con zoom del 400 % (`RUX-RESP-001`, WCAG 1.4.10).
4. **Contenido del panel**, de arriba abajo (`ADR-064 §2.2`):
   - **Cabecera**: logo del centro (decorativo, `alt=""`, como hoy) y nombre del centro. Sin logo, solo el nombre. Con estado `unavailable` o `not-found` no hay nombre ni logo (comportamiento actual de `§7.2`).
   - **Cuerpo**: el *slot* de cada vista (su `h1`, su formulario, los accesos externos que ya pinta `IdentityProviderLoginList`). En `/entrar` se añade bajo el título un **texto de ayuda** (`ADR-064 §2.2`), clave nueva `auth.login.lead`.
   - **Pie**: «Con tecnología de {plataforma}» y el **selector de idioma** (§5.7). El nombre de la plataforma **no** aparece dentro del formulario (`RN-DS-27`) y sale de **una única clave de traducción** para que `0.11c` lo cambie en un solo sitio (`OPEN-UI-06`).
5. **Exactamente un `h1`** por pantalla (el de la vista). Los textos de bienvenida son párrafos, no encabezados. La región visual es un `aside` con nombre accesible traducido; el panel contiene el `main`.

**`RN-UI-09`** · El cambio de una a dos columnas usa el *breakpoint* `md` ya existente (`RN-CORE-29`, `RUX-RESP-001`). Los 820 px de la maqueta no son un punto de cambio del producto y no se añade uno nuevo. A 768 px la imagen dispone de 408 px. `OPEN-UI-07`.

**`RN-UI-10`** · Estados de la región visual según la capa B (`useTenantBranding`, `§7.2` de `design-system.md`):

| `status` | `login_background_url` | Se pinta |
|----------|------------------------|----------|
| `loading` | — | Superficie neutra (`bg-muted`), sin imagen ni ilustración, para no mostrar la ilustración y cambiarla al instante por la del centro |
| `ready` | informado | Imagen del centro como **fondo CSS** (`ADR-064 §2.3`), `cover` y centrada, **con la ilustración por defecto debajo**: si la URL firmada falla, se ve la ilustración y no un hueco |
| `ready` | nulo | Ilustración por defecto (§5.6) |
| `unavailable`, `not-found` | — | Ilustración por defecto y bienvenida genérica; el resto, como hoy (`RN-CORE-35` no cambia) |

La imagen se aplica por `:style` (CSSOM), sin `<style>` generado: compatible con la CSP (`§16` de `design-system.md`).

### 5.5 Texto de bienvenida genérico (`ADR-064 §2.3`, `§6.1`; `INV-009`)

**`RN-UI-11`** · Sobre la región visual, abajo a la izquierda, tres piezas: **antetítulo**, **título** y **una línea**. En `1.15a-a` salen de claves de traducción de `modules/auth/locales/{es,en,de,fr}.json`:

| Pieza | Clave | es-ES **(propuesta, `OPEN-UI-06`)** |
|-------|-------|-------------------------------------|
| Antetítulo | — | No se pinta en la versión genérica (no hay dato público del que salga; el «Curso 2026-2027» de la maqueta no es dato de `GET /tenant/branding`) |
| Título | `auth.shell.welcomeTitle` | «Te damos la bienvenida» |
| Línea | `auth.shell.welcomeText` | «Accede con tu cuenta para consultar la información de tu centro.» |

El texto genérico no nombra módulos (calificaciones, horarios…): un centro puede no tenerlos contratados (`RMOD-008`). Traducción a en, de, fr con tratamiento formal («Sie», «vous»); son menos de 15 claves no legales: las traduce la sesión (`CLAUDE.md §2.9`).

**`RN-UI-12`** · Contraste (`RN-DS-29`, `RNF-UX-002`, WCAG 1.4.3): el texto usa `--on-scrim` a **opacidad completa** (la maqueta lo atenuaba al 90-95 %; se descarta) sobre una placa `--scrim-auth` **sólida detrás de todas las líneas de texto**; el degradado hacia transparente ocurre **solo en el relleno superior del bloque**, por encima de la primera línea. (En la maqueta el degradado bajaba al 45 % detrás del texto, que sobre una imagen clara no llega a 4,5:1.)

### 5.6 Ilustración por defecto y encuadre (`ADR-064 §2.4`, `§6.2`)

**`RN-UI-13`** · Requisitos de la ilustración, cualquiera que sea la opción de `OPEN-UI-05`:

- **Original** del proyecto, **abstracta, sin personas** (`REQ-SEED-005`), sin texto, sin marcas de terceros. Su licencia y autoría se anotan en el registro de recursos gráficos (`RUX-ICON-007`; ubicación en §14).
- **Formato SVG**, fichero estático servido por la propia SPA (mismo origen; sin petición a terceros), de **≤ 20 KB**, sin imágenes de mapa de bits incrustadas, sin referencias externas, sin *scripts* **(propuesta de límite)**.
- **Encuadre**: la región visual va de muy vertical (≈ 1:2 a 768 px de ancho) a muy apaisada (franja móvil, hasta ≈ 4:1). Por eso la ilustración **no tiene motivo central ni elemento cuyo recorte la estropee**: es una composición que admite cualquier recorte entre 1:2 y 4:1 con `cover`. Es el modo de resolver «el recorte pierde parte del dibujo» sin lógica de recorte.
- Para la **imagen del centro** el encuadre es `cover` centrado y no configurable; la pantalla de marca añade un texto de ayuda con la recomendación (tamaño mínimo orientativo y «el motivo, centrado: los bordes pueden recortarse»), claves nuevas en `modules/core/locales`.

**Opción recomendada (`OPEN-UI-05` = A)**: la región visual tiene de fondo el **color primario del centro** (`bg-primary`, con su `text-primary-foreground` emparejado, `RN-DS-18`) y la ilustración es un **patrón monocromo translúcido** encima. Un centro que solo ha configurado su color —el caso del centro de demostración que motivó #431— obtiene una pantalla con su color sin subir imagen; sin marca, el neutro de respaldo. **No toca `RN-DS-02`**: usa `--primary`, que ya depende de la marca; el contraste del texto lo garantiza el velo, no el color.

### 5.7 Selector de idioma del pie (`ADR-064 §2.2`)

**`RN-UI-14`** · Lista de los **idiomas activos del centro** (`active_locales` de la capa B); si solo hay uno, no se pinta. Cada opción muestra el código y tiene como nombre accesible el nombre del idioma en ese idioma, con su atributo `lang`; la activa lleva `aria-current`. Navegación con nombre accesible traducido. Si ya existe un selector en el *shell* de 1.8, se reutiliza su lógica; no se duplica (§17, sin verificar).

**`RN-UI-15`** · Precedencia del idioma en pantallas públicas **(amplía `CA-AUTH-063`; `OPEN-UI-12`)**: elección explícita guardada (`plataforma.locale`), **si está entre los idiomas activos del centro** → idiomas del navegador ∩ activos → `default_locale`. Hoy `usePublicAuthScreen` vuelve a calcular el idioma en cada montaje y pisaría la elección al pasar de `/entrar` a `/recuperar`. Sin elección explícita, el resultado es idéntico al actual.

### 5.8 Accesibilidad (WCAG 2.2 AA, `RNF-UX-002`, `RUX-004`)

| Criterio | Cómo se cumple | CA |
|----------|----------------|----|
| 1.1.1 | Imagen e ilustración decorativas (fondo CSS); logo `alt=""` junto al nombre | `CA-UI-011` |
| 1.3.1, 2.4.6, 1.3.2 | Un `h1`; `main` y `aside` con nombre; orden del DOM = orden visual | `CA-UI-010` |
| 1.4.3, 1.4.11 | Velo sólido tras el texto; panel con tokens semánticos ya verificados por `§11`; bordes de campo `--input` | `CA-UI-007`, `-012` |
| 1.4.4, 1.4.10 | Ancho del panel en `rem`; una columna bajo `md`; sin desplazamiento horizontal a 320 px | `CA-UI-009` |
| 2.1.1, 2.4.3, 2.4.7, 2.4.11 | Todo alcanzable por teclado en orden lógico; foco visible y no tapado ni recortado | `CA-UI-015` |
| 2.5.8 y `RUX-RESP-007` | Botones de idioma ≥ 24 px, y 44 px con puntero grueso (`§12.1b`) | `CA-UI-017` |
| 3.1.1, 3.1.2 | `lang` del documento sigue al idioma elegido; opciones de idioma con su `lang` | `CA-UI-014` |
| 2.3.3 / `RUX-005` | Sin animación propia; `prefers-reduced-motion` ya es global (`§4.6`) | `CA-UI-018` |
| 1.4.1 | La opción de idioma activa no se distingue solo por color (peso y subrayado) | `CA-UI-014` |

### 5.9 Imagen `sign-in` de la landing

La captura `sign-in` de `apps/landing` queda desfasada (`apps/landing/README.md`, `ADR-075 §3`). Se **regenera** con `npm run landing:shots` limitado a `sign-in` y `tools/images/images.mjs`, **en un único commit de imágenes**, en la **última entrega que se mezcle** (tras `1.15a-b` si va a continuación; si se aplaza, al cerrar `1.15a-a`), y pasa la revisión humana de `ADR-074 §4.3`. Con `1.15a-b`, el *fixture* del escaparate fija un texto de bienvenida ficticio.

---

## 6. Funcional de `1.15a-b`: texto de bienvenida personalizable

Fuente: `RUX-BRAND-005`; issue #431, decisión 5 («imagen y texto de bienvenida los elige el centro»); `ADR-064 §6.1`; `ADR-062`. Actores: quien tenga `configuracion.actualizar` (edita) y cualquier visitante anónimo (lee).

### 6.1 Flujos

**F1 · Personalizar.** 1) En `/administracion/centro/marca`, bloque nuevo «Texto de bienvenida del acceso». 2) Por cada pieza (`OPEN-UI-03`), una entrada por idioma activo, con el componente compartido de campo traducible de `ADR-062 §5.7`; el idioma por defecto del centro va primero y es obligatorio si la pieza se rellena. 3) Aviso permanente: **«Este texto es público: lo ve cualquiera que abra la dirección del centro, sin iniciar sesión. No incluyas datos personales.»** 4) Guardar → `PATCH /tenant/settings` → la vista llama a `useTenantBranding().refresh()`.

**F2 · Restablecer.** «Usar el texto por defecto» en una pieza, con confirmación (`ConfirmDialog`, `RN-CORE-64`) → se elimina la personalización de esa pieza.

**F3 · Ver.** La pantalla pública pinta cada pieza personalizada; la que no lo esté cae **por separado**: título y línea, al genérico de §5.5; antetítulo, no se pinta.

### 6.2 Reglas

- **`RN-UI-20`** · Validación de servidor, la común de `ADR-062 §5.2` (idioma por defecto obligatorio al escribir, solo idiomas activos, valor no vacío, `PATCH` por fusión, códigos `translations.*` del catálogo compartido), más: longitud máxima por valor —antetítulo 60, título 120, línea 240 caracteres **(propuesta, `OPEN-UI-15`)**— y **una sola línea** (sin saltos ni caracteres de control; `422`).
- **`RN-UI-21`** · **Texto plano.** No se interpreta HTML ni Markdown ni se convierten URL en enlaces. El servidor guarda lo recibido tras recortar; la SPA lo pinta como texto, nunca como HTML (`RSEC-OWASP`, XSS almacenado en una página anónima).
- **`RN-UI-22`** · Resolución **en servidor** con el resolutor único de `ADR-062 §5.3`: idioma pedido → `default_locale` → `es-ES` → primera clave. En el *endpoint* anónimo, «idioma pedido» es `Accept-Language` ∩ activos (`ResolveApiLocale`). Cuando el visitante cambia de idioma con el selector, la capa B repite la petición indicando ese idioma (`RN-DS-22` se mantiene: único llamador).
- **`RN-UI-23`** · La SPA marca con `lang` la pieza cuyo idioma de procedencia no coincide con el de la página (`ADR-062 §5.4` punto 1, WCAG 3.1.2).
- **`RN-UI-24`** · Compatibilidad de despliegue (`CLAUDE.md §9`): una SPA nueva frente a una API sin el campo trata su ausencia como «sin personalizar»; una SPA antigua ignora el campo nuevo. El orden de despliegue de API y web es indiferente.
- **`RN-UI-25`** · La caché de `plataforma.brand` de `localStorage` **no cambia** (`RN-DS-08`: solo dos colores). El texto no se guarda en el navegador.
- **`RN-UI-26`** · `1.15a-b` no añade ruta, entrada de navegación ni bloque del panel: usa la ruta existente `/administracion/centro/marca` con sus `meta.permissions` actuales.

### 6.3 Casos límite

| Caso | Resultado |
|------|-----------|
| El centro retira de `active_locales` un idioma con texto | El texto no se borra (`ADR-062 §5.2` punto 3); deja de ofrecerse y de pedirse |
| Cambia `default_locale` y una pieza no tiene el nuevo idioma | Se muestra por caída; la siguiente edición exige añadirlo |
| Visitante con idioma que el centro no tiene activo | Idioma por defecto del centro; `lang` marcado si difiere del de la página |
| Texto con `<script>` o `<img onerror=…>` | Se guarda y se ve literal, como texto |
| Texto muy largo en una pantalla estrecha | Se ajusta en varias líneas dentro de la placa del velo; nunca desborda ni se recorta sin indicación |
| `GET /tenant/branding` falla (`unavailable`) | Bienvenida genérica |
| Dos administradores guardan a la vez | Última escritura gana por idioma (fusión); sin bloqueo optimista, como el resto de `PATCH /tenant/settings` |

## 7. Datos (`1.15a-b`)

`1.15a-a` no tiene datos. Lo que sigue describe la **opción recomendada de `OPEN-UI-02` (A)**; no se implementa hasta que se decida.

### 7.1 El problema que obliga a elegir

El texto es **opcional** y `tenant_settings` **ya tiene filas**. `ADR-062 §5.1` fija que una columna traducible es `jsonb NOT NULL` con al menos un idioma, y `AR-16` lo comprueba con lista de excepciones vacía. Una columna `*_translations` anulable en `tenant_settings` incumple el ADR y rompe `AR-16`; rellenarla con el texto genérico congelaría texto del producto dentro de los datos de cada centro.

### 7.2 Opción A (recomendada): tabla `tenant_custom_texts`

Tabla de tenant nueva, creada con `TenantMigration::tenantTable()`. **Una fila por texto personalizado**; que no haya fila significa «sin personalizar». Cumple `ADR-062` tal como está escrito, sin enmendarlo.

| Columna | Tipo | Nulo | Descripción |
|---------|------|------|-------------|
| `id`, `tenant_id`, autoría, `created_at`/`updated_at`/`deleted_at` | — | — | De `tenantTable()` (`INV-004`, `INV-005`; `TIMESTAMPTZ`, `ADR-029`) |
| `public_id` | ULID, único | No | `ADR-029`, `AR-05` |
| `text_key` | `text` + `CHECK` | No | Vocabulario cerrado. En esta entrega: `login_welcome_eyebrow`, `login_welcome_title`, `login_welcome_text` (según `OPEN-UI-03`). Sin `ENUM` (`AR-04`); un texto nuevo se añade ampliando el `CHECK` (*expand*) |
| `content_translations` | `jsonb` | No | `TenantMigration::translatableColumn($t, 'content')`, con su `CHECK` de `ADR-062 §5.1` |

- **Unicidad**: `UNIQUE (tenant_id, text_key) WHERE deleted_at IS NULL` — un texto vivo por clave y centro, garantizado por el motor (dos guardados simultáneos no crean dos filas).
- **Índices**: el único parcial anterior cubre la única consulta (todas las filas vivas del centro; a lo sumo tres). Ninguno más.
- **Relaciones**: solo con el tenant. Sin `academic_year_id` (no depende del curso; `ADR-034 §4`). Sin importes. Sin datos de categoría especial.
- **Modelo**: `TenantModel`, `Auditable`, alias en el *morph map* de `Core` (`AR-06`). **Política de auditoría: `Full`** — es texto que el centro publica en Internet a propósito; `ADR-062 §5.5` desglosa `content_translations` por idioma. Un valor cuya codificación supere el tope de `ADR-035 §5` se redacta `oversized`, como cualquier otro.
- **Escrituras por instancia**, nunca masivas (`AR-15`, `INV-003`). «Restablecer» es borrado lógico de la fila.
- **Datos personales**: no debe contenerlos (aviso de F1); no hay forma técnica de impedirlo y por eso la política no es `Selective`. Ningún dato de menores (`INV-008` no aplica).
- **Migración**: solo crea la tabla (*expand* puro, compatible con la versión anterior). Reversión: eliminar la tabla, segura mientras no se haya desplegado la escritura.

### 7.3 Opciones descartadas o alternativas (detalle en `OPEN-UI-02`)

- **B · columnas anulables en `tenant_settings`**: menos código (reutiliza la caché y el `PATCH` tal cual), pero exige **un ADR que enmiende `ADR-062 §5.1` y `AR-16`** para admitir el campo traducible opcional. Es el único punto de esta especificación que requeriría un ADR nuevo, y solo si se elige.
- **C · `NOT NULL` rellenando con el genérico**: descartada (§7.1).

---

## 8. API (`1.15a-b`)

`1.15a-a` no toca la API. Convenciones de `ADR-038` y de `REQ-CORE/api.md §1`. Ningún *endpoint* nuevo: se amplían tres representaciones. Todo se documenta en OpenAPI antes de mezclar (`CLAUDE.md §10`).

### 8.1 `GET /api/v1/tenant/branding` (anónimo) — campo nuevo `login_welcome`

- **Permiso**: ninguno (sin cambios). Tenant por *host*. Límite por IP sin cambios (`429`).
- **Campo nuevo**: `login_welcome`, objeto **siempre presente** con, por cada pieza, el par de `ADR-062 §5.4`: `<pieza>` (cadena resuelta o `null`) y `<pieza>_translations` (objeto almacenado o `null`). Piezas: `eyebrow`, `title`, `text`.
- **Cabeceras**: `Content-Language` como hoy; la respuesta depende de `Accept-Language` (`Vary` si alguna capa la cachea).
- **Justificación exigida por la «regla no negociable» de `api.md §2`** (la decide `security-reviewer`; `OPEN-UI-04`): el campo publica únicamente texto que el centro ha escrito **para** esa pantalla pública, con aviso explícito; es texto plano, de longitud acotada y de una línea; no añade ningún dato de configuración, estado, módulos ni personas. El test que fija la lista cerrada de claves de la respuesta se actualiza a nueve.
- **Errores**: `404` (host sin centro), `429`. Sin cambios.

### 8.2 `GET /api/v1/tenant/settings` — `branding.login_welcome`

- **Permiso**: `configuracion` · `leer` · `todos` (sin cambios). Misma forma que en §8.1. Errores `401`, `403`, `404`.

### 8.3 `PATCH /api/v1/tenant/settings` — `branding.login_welcome`

- **Permiso**: `configuracion` · `actualizar` · `todos` (sin cambios).
- **Cuerpo**: dentro de `branding`, `login_welcome` con las claves `<pieza>_translations` que se quieran cambiar. Fusión por idioma (`ADR-062 §5.2`, `ADR-038 §9.2` regla 4): un idioma a `null` lo quita; `<pieza>_translations` a `null` elimina la personalización de la pieza. `<pieza>` (resuelto) es de solo lectura y se ignora si llega.
- **Validación** (`INV-010`): `RN-UI-20`. Una clave desconocida dentro de `login_welcome` es `422`.
- **Respuesta `200`**: el recurso completo. **Errores**: `401`, `403`, `422` con `errors` por campo (`translations.default_locale_required`, `translations.locale_not_active`, `translations.empty_value`, y los de longitud y línea única del catálogo de validación).
- **Idempotencia**: no (naturalmente repetible, como hoy). **Paginación**: no aplica. **Auditoría**: automática por el *observer*, una fila por instancia tocada (`INV-003`).
- La escritura de `tenant_settings` y de `tenant_custom_texts` ocurre en **una transacción**, y la caché de configuración del centro se invalida al confirmar (`ADR-033 §9`, prefijo por tenant).

---

## 9. Permisos

**No se crea ningún permiso, rol ni ámbito** y `declaredPermissions()` de `core` no cambia. Se comprueban permisos, nunca roles: un rol personalizado con `configuracion.actualizar` puede editar.

| Recurso | Acción | Ámbito | Qué permite en este paso | Entrega |
|---------|--------|--------|--------------------------|---------|
| `configuracion` | `leer` | `todos` | Ver el bloque «Texto de bienvenida» y `branding.login_welcome` en `GET /tenant/settings` | `1.15a-b` |
| `configuracion` | `actualizar` | `todos` | Guardar y restablecer el texto (`PATCH /tenant/settings`) | `1.15a-b` |
| — (anónimo) | lectura pública | centro del *host* | `GET /tenant/branding` con `login_welcome` | `1.15a-b` |

- Denegar por defecto: sin `configuracion.leer`, `403` en `GET /tenant/settings`; sin `configuracion.actualizar`, `403` en `PATCH` y el bloque se muestra en solo lectura (la interfaz oculta; el servidor decide, `INV-002`).
- Sin exportación, sin datos de categoría especial, sin auditoría de lectura (texto público).
- Aislamiento (`INV-001`): el *endpoint* anónimo solo devuelve el texto del centro resuelto por *host*; RLS en la tabla nueva.
- `1.15a-a` no tiene permisos: solo cambia pantallas públicas y tokens.

---

## 10. Operación

| Aspecto | `1.15a-a` | `1.15a-b` |
|---------|-----------|-----------|
| Migraciones | Ninguna | Una, *expand* (tabla nueva); revisada por `db-reviewer` |
| Variables de entorno | Ninguna | Ninguna |
| Colas y tareas programadas | Ninguna | Ninguna (`INV-012` no aplica: una lectura y una escritura cortas) |
| Caché | `localStorage` sin cambios | Caché de configuración del centro en Redis: incluye el texto y se invalida al escribir |
| CSP | Sin `<style>` ni `<script>` nuevos; la ilustración es del mismo origen; la imagen del centro ya se sirve hoy desde el almacén | Sin cambios |
| Despliegue | Solo la imagen de `apps/web` | API y web en cualquier orden (`RN-UI-24`) |
| Reversión | Revertir el *commit* y redesplegar la web. Probada revirtiendo en local y comprobando que la suite vuelve a verde | Revertir web y API; la tabla puede quedarse (nadie la lee) y se elimina en una entrega posterior (*contract*) |
| *Workers* | No afecta | No afecta: ningún trabajo encolado nuevo ni cambiado |
| Purga de tenant | — | La tabla es de tenant con `tenant_id`: la purga genérica (`ADR-077`, en propuesta) debe incluirla; se avisa a esa entrega |
| Observabilidad | — | Las escrituras quedan en `audit_logs`; sin métrica nueva |

---

## 11. Qué toca `RN-DS-02` y cuándo haría falta un ADR

`RN-DS-02`: «solo los semánticos de "primario" dependen de la marca» (`--primary`, `--primary-foreground`, `--primary-on-background`, `--ring`, `--sidebar-primary`, `--sidebar-primary-foreground`, `--sidebar-ring`). `ADR-064 §6.4`: «cualquier cambio a `RN-DS-02` exige su propio ADR».

**Las dos entregas de este documento no tocan `RN-DS-02` y no necesitan ADR nuevo**: los tokens nuevos (`--shadow-auth-panel`, `--scrim-auth`, `--on-scrim`) son fijos y no dependen de la marca, y la región visual con color del centro (§5.6) usa `--primary`, que ya está en la lista.

| Lo que se planteó junto a `ADR-064` (`§6.4`, #431) | ¿Cambia `RN-DS-02`? | Qué exige |
|---|---|---|
| Elemento activo del menú con el color del centro, usando `--sidebar-primary` o `--primary-on-background` | **No**: usa semánticos ya dependientes de la marca y respeta `RN-DS-16` a `-18` | Decisión de diseño del usuario (no está tomada), sin ADR |
| Barra lateral o cabecera **teñidas** con el color del centro | **Sí**: `--sidebar` (u otra superficie) pasaría a depender de la marca. Además rompe la premisa de `RN-DS-06` (superficies **neutras** contra las que se deriva el contraste) y la tabla de `§11` | **ADR propio** |
| «Acentos» de marca (`--accent`, fondos de *hover* o de selección teñidos) | **Sí** | **ADR propio** |
| Usar `color_secondary` como segundo color de marca | **Sí**, y contradice `ADR-052 P1` (alimenta `--primary-foreground`) | **ADR que sustituya** esa parte de `ADR-052` |
| Velo del acceso teñido con la marca (como en la maqueta) | **Sí** (semántico nuevo dependiente de la marca) | Descartado aquí (§5.1): velo fijo |
| Ilustraciones en estados vacíos | No, si se pintan con tokens existentes | Paso propio; `RUX-ICON-005`, `RUX-ICON-007`, `RUX-006` |
| Página de inicio | No | Paso propio; no hay requisito que fije su contenido más allá de los bloques de `ADR-053` |

**Propuesta de proceso (`OPEN-UI-10`)**: nada de esa tabla entra en `1.15a-a` ni en `1.15a-b`. Si el usuario quiere la marca en el interior, se hace como se hizo `ADR-064`: **maqueta navegable → decisión del usuario → ADR de `architect`** («Marca del centro en el interior de la aplicación», que enmendaría `RN-DS-02`, `RN-DS-06` y `§11`) → entrega `1.15a-c`. Esta especificación **no** redacta ese ADR ni toma número; si el usuario lo quiere, la orquestadora asigna el siguiente libre. El único otro ADR posible es el condicional de §7.3 (opción B de `OPEN-UI-02`).

## 12. Criterios de aceptación

Vitest salvo los marcados **[Playwright]** (necesitan estilos calculados), **[Pest]** (API) o **[Procedimiento]** (entregable revisado por una persona). Cada test cita su `CA-UI-NNN` (`INV-015`). Las reglas de arquitectura llevan casos fijos en el propio test que demuestran que saben fallar (`§10.6` de `design-system.md`).

### 12.1 `1.15a-a`

**Tokens y radio**

- **`CA-UI-001`** [`RN-DS-25`, `ADR-064 §4`] · *Dado* `tokens.css`, *cuando* se analiza, *entonces* `:root` declara `--radius: 2px` y `.dark` no lo redefine.
- **`CA-UI-002`** [`RN-DS-25`, `RN-UI-04`] · *Dado* `style.css`, *cuando* se analiza `@theme inline`, *entonces* `--radius-xs`, `-sm`, `-md`, `-lg`, `-xl`, `-2xl`, `-3xl` y `-4xl` existen y valen exactamente `var(--radius)`; *y dado* el caso fijo `calc(var(--radius) - 4px)`, el test lo señala.
- **`CA-UI-003`** [`§22` regla 5, `RN-UI-05`] · *Dado* `src/` según `§10.1`, *entonces* no hay `rounded-[…]` ni `rounded-none`, y `rounded-full` solo aparece en los ficheros de la lista cerrada del test; *y dados* los casos fijos `rounded-[6px]`, `md:rounded-full`, se detectan, y `rounded-md` no.
- **`CA-UI-004`** [`RN-DS-25`] **[Playwright]** · *Dado* `/entrar` y una pantalla autenticada con un menú desplegable y un diálogo abiertos, *cuando* se leen los estilos calculados, *entonces* el radio del botón principal, de un campo de texto, del menú y del diálogo es `2px`.
- **`CA-UI-005`** [`RN-DS-28`, `RN-UI-03`] · *Dado* `tokens.css` y `style.css`, *entonces* `--shadow-auth-panel` está definido con el mismo valor en `:root` y `.dark` (o solo en `:root`) y mapeado a utilidad; *y* el test de contraste de `§11` lo ignora por nombre y sigue fallando ante un semántico de color en formato no admitido (`CA-DS-006`).
- **`CA-UI-006`** [`RN-DS-28`, `ADR-064 §3.3`, `§6.5`] · *Dado* `src/`, *entonces* `shadow-auth-panel` solo aparece en `PublicAuthShell.vue`, y ninguna otra utilidad `shadow-*` (salvo `shadow-none`) aparece fuera de `src/components/ui/**` y de la lista cerrada; *y dados* los casos fijos (`shadow-md` en una vista, `lg:shadow-auth-panel` en otro fichero), se detectan.
- **`CA-UI-007`** [`RN-DS-29`, `RNF-UX-002`, `RN-UI-02`] · *Dado* `--on-scrim` y `--scrim-auth` (iguales en ambos modos), *cuando* se compone el velo sobre blanco puro y sobre negro puro y se calcula el contraste con `contrast.ts`, *entonces* ambos son ≥ 4,5:1; *y dado* un velo al 40 % en una copia en memoria, el test informa del incumplimiento.
- **`CA-UI-008`** [`CA-DS-037` a `-043`] · *Dado* `src/` tras el cambio, *entonces* los tests de arquitectura de `§10` siguen en verde sin ninguna excepción nueva en sus listas.

**Pantalla «Panel»**

- **`CA-UI-009`** [`RN-DS-26`, `RUX-RESP-001`, WCAG 1.4.10] **[Playwright]** · *Dado* cada una de las rutas públicas que usan `PublicAuthShell`, *cuando* la ventana mide 1440×900, *entonces* la región visual está a la izquierda, el panel a la derecha, ocupa toda la altura y mide entre 360 y 460 px (432 px); a 1920 px mide 460 y a 1024 px, 360; *y cuando* mide 767 px y 320 px de ancho, hay una sola columna con la franja visual arriba, la sombra calculada del panel es `none` y el documento no tiene desplazamiento horizontal.
- **`CA-UI-010`** [`RN-DS-27`, `RUX-004`] · *Dado* `PublicAuthShell` montado con un *branding* simulado, *entonces* el orden del DOM es región visual (`aside` con nombre accesible) y panel (`main`); el panel contiene, en orden, logo y nombre del centro, el *slot* y el pie; hay exactamente un `h1`; el nombre de la plataforma está en el pie y **no** dentro de ningún `form`; y la región visual no contiene elementos enfocables.
- **`CA-UI-011`** [`RN-DS-29`, `RUX-BRAND-004`, `RN-UI-10`] · *Dado* cada fila de la tabla de `RN-UI-10`, *cuando* la capa B está en ese estado, *entonces* la región visual pinta lo indicado: con `login_background_url`, la imagen como fondo aplicado por `style` con la ilustración debajo; sin ella, la ilustración; en `loading`, ninguna de las dos; y el componente no crea elementos `style`.
- **`CA-UI-012`** [`RN-DS-29`, `RNF-UX-002`, `RN-UI-12`] **[Playwright]** · *Dado* un centro con una imagen de acceso blanca y otro con una negra, *cuando* se abre `/entrar` a 1440 px y a 320 px, *entonces* cada línea del texto de bienvenida queda dentro de la placa del velo, cuya opacidad calculada es la de `--scrim-auth`; el color del texto es `--on-scrim` con opacidad 1; y el contraste calculado texto/fondo compuesto es ≥ 4,5:1 en los dos casos.
- **`CA-UI-013`** [`INV-009`, `ADR-064 §2.3`, `RN-UI-11`] · *Dado* la pantalla sin texto personalizado, *entonces* título y línea salen de las claves `auth.shell.*` en el idioma activo, el antetítulo no se pinta, y por debajo de `md` solo se muestra el título; `TranslationParity` y `npm run lint:i18n` pasan con las claves nuevas en es, en, de y fr.
- **`CA-UI-014`** [`ADR-064 §2.2`, `RN-UI-14`, `RN-UI-15`, WCAG 3.1.1/3.1.2/1.4.1] · *Dado* un centro con `active_locales = ['es-ES','fr']`, *entonces* el selector ofrece solo esos dos, cada opción con su `lang` y nombre accesible, y la activa con `aria-current` y distinguida por algo más que el color; *cuando* se elige `fr`, cambian los textos, el `lang` del documento y `plataforma.locale`, y al navegar a `/recuperar` el idioma **sigue siendo `fr`**; *dado* un centro con un solo idioma activo, el selector no se pinta; *dado* `plataforma.locale = 'de'` con `de` no activo, rige la regla de `CA-AUTH-063`.
- **`CA-UI-015`** [`RUX-004`, WCAG 2.1.1, 2.4.3, 2.4.7, 2.4.11, `RN-UI-06`] **[Playwright]** · *Dado* `/entrar` a 1440 px y a 320 px, *cuando* se recorre con el tabulador, *entonces* el foco pasa por correo, contraseña, botón de envío, accesos externos, enlace de recuperación y opciones de idioma, en ese orden; cada uno muestra un indicador de foco de opacidad completa con el color `ring`, y su caja no queda recortada ni tapada por el panel ni por la franja.
- **`CA-UI-016`** [`RN-DS-28`] **[Playwright]** · *Dado* `/entrar` a 1440 px, *entonces* la sombra calculada del panel es la de `--shadow-auth-panel` (dos capas con desplazamiento horizontal negativo).
- **`CA-UI-017`** [`RUX-RESP-007`, WCAG 2.5.8] **[Playwright]** · *Dado* el selector de idioma, *entonces* cada opción mide al menos 24×24 px, y al menos 44×44 px cuando el contexto emula un puntero grueso.
- **`CA-UI-018`** [`RUX-005`] **[Playwright]** · *Dado* `reducedMotion: 'reduce'`, *entonces* ningún elemento de `PublicAuthShell` tiene una duración de transición o animación calculada mayor de `0.01ms`.
- **`CA-UI-019`** [`ADR-064 §2.4`, `REQ-SEED-005`, `RUX-ICON-007`, `RN-UI-13`] · *Dado* el fichero de la ilustración por defecto, *entonces* es un SVG de ≤ 20 KB sin `image`, `text`, `script`, `foreignObject` ni referencias externas; y el registro de recursos gráficos tiene su entrada con autoría y licencia.
- **`CA-UI-020`** [`RNF-UX-004`] **[Playwright]** · *Dado* `/entrar` con `colorScheme` oscuro, *entonces* el fondo del panel es el `--background` oscuro, y velo, texto sobre velo y sombra son idénticos a los del modo claro.
- **`CA-UI-021`** [sustituye a `CA-DS-045`; `RUX-BRAND-002`, `-004`] · *Dado* el contexto de la capa B con un *branding* simulado, *cuando* se monta `PublicAuthShell`, *entonces* pinta nombre, logo e imagen del contexto, no declara la *prop* `branding`, ningún elemento suyo tiene `--primary` ni `--primary-foreground` en `style`, y un fallo de carga del logo llama una vez a `reportAssetError`.
- **`CA-UI-022`** [`CA-DS-047`] · *Dado* la suite de `apps/web`, *cuando* se ejecuta tras el cambio, *entonces* todos los tests preexistentes de `REQ-AUTH` pasan con las únicas reescrituras de la lista cerrada del informe del paso (marcado de `PublicAuthShell` y precedencia del idioma de `CA-AUTH-063`).
- **`CA-UI-023`** [`CLAUDE.md §1`, `RN-UI-01`] · *Dado* `apps/web/package.json`, *entonces* no gana ninguna dependencia, y `src/` no importa ninguna librería de componentes distinta de las ya permitidas por `§10.4`.

**Entregables revisados por el usuario**

- **`CA-UI-024`** [`ADR-064 §7`, `RN-UI-07`] **[Procedimiento]** · *Dado* el cambio de radio, *cuando* se cierra la entrega, *entonces* existen las capturas antes y después de la lista de `RN-UI-07` en claro y oscuro, fuera de Git, y el informe del paso recoge la revisión del usuario.
- **`CA-UI-025`** [`ADR-075 §3`, `ADR-074 §4.3`] **[Procedimiento]** · *Dado* la última entrega de `1.15a` que se mezcle, *entonces* la imagen `sign-in` de la landing está regenerada en un único *commit* de imágenes, su texto visible pasa la comprobación de `landing-shots`, `ci-landing` está en verde y el usuario la ha aprobado.

### 12.2 `1.15a-b`

- **`CA-UI-040`** [`ADR-062 §5.1`, `ADR-029`, `AR-04`/`-05`/`-06`/`-16`] **[Pest]** · *Dado* el esquema migrado, *entonces* `tenant_custom_texts` tiene RLS activada y forzada, `public_id` único, `text_key` con `CHECK` de vocabulario cerrado, `content_translations` `jsonb NOT NULL` con el `CHECK` de traducciones y el único parcial por `(tenant_id, text_key)`; el grupo `arch` pasa; y el motor rechaza una segunda fila viva con la misma clave y un objeto vacío (`23505`, `23514`).
- **`CA-UI-041`** [`INV-001`] **[Pest]** · *Dados* dos centros con textos distintos, *cuando* se pide `GET /tenant/branding` por el *host* de cada uno, *entonces* cada uno recibe solo el suyo; *y cuando* el administrador de uno hace `PATCH`, el texto y la caché del otro no cambian; una consulta directa con el contexto de un centro no ve filas del otro.
- **`CA-UI-042`** [`REQ-CORE/api.md §2`, `OPEN-UI-04`] **[Pest]** · *Dado* un centro sin personalización, *cuando* se pide `GET /tenant/branding` sin sesión, *entonces* la respuesta tiene exactamente las ocho claves anteriores más `login_welcome`, y dentro de este las seis claves de §8.1 a `null`.
- **`CA-UI-043`** [`ADR-062 §5.3`, `§5.4`, `RN-UI-22`] **[Pest]** · *Dado* un centro con `default_locale = 'es-ES'`, activos `es-ES` y `fr`, y título en ambos, *cuando* se pide con `Accept-Language: fr`, *entonces* `title` es el francés y `Content-Language` es `fr`; con `de`, el español; `title_translations` trae siempre las dos claves; *y dado* que después el centro cambia `default_locale` a un idioma sin texto, se resuelve a `es-ES` o a la primera clave.
- **`CA-UI-044`** [`INV-002`, `RPERM-011`] **[Pest]** · *Dado* `PATCH /tenant/settings` con `branding.login_welcome`, *cuando* lo envía un usuario sin sesión, *entonces* `401`; con sesión y sin `configuracion.actualizar`, `403` y nada cambia; con un **rol personalizado** que tiene ese permiso, `200`. *Y* `GET /tenant/settings` sin `configuracion.leer` responde `403`.
- **`CA-UI-045`** [`INV-010`, `RN-UI-20`] **[Pest]** · *Dado* un cuerpo que crea una pieza sin el idioma por defecto del centro, o con un idioma no activo, o con valor vacío o solo espacios, o que supera la longitud de la pieza, o con un salto de línea, o con una clave desconocida, *entonces* `422` con el código correspondiente por campo y no se escribe nada.
- **`CA-UI-046`** [`ADR-062 §5.2`, `ADR-038 §9.2`] **[Pest]** · *Dado* un título en `es-ES` y `fr`, *cuando* el `PATCH` envía solo `fr`, *entonces* `es-ES` no cambia; con `fr: null`, se quita `fr`; al quitar `es-ES` (idioma por defecto), `422`; con `title_translations: null`, la pieza queda sin personalizar, su fila con borrado lógico, y `GET /tenant/branding` devuelve `title: null`; un valor `title` en el cuerpo se ignora.
- **`CA-UI-047`** [`RN-UI-21`, `RSEC-OWASP`] **[Pest + Playwright]** · *Dado* un título `<img src=x onerror=alert(1)>`, *cuando* se guarda y se abre `/entrar`, *entonces* la API lo devuelve literal, la pantalla lo muestra como texto, el DOM de la región visual no contiene ningún elemento `img` ni `script`, y no se dispara ningún diálogo.
- **`CA-UI-048`** [`INV-003`, `ADR-062 §5.5`, `AR-15`] **[Pest]** · *Dado* un alta, un cambio de un idioma y un restablecimiento, *entonces* `audit_logs` tiene una fila `created`, una `updated` cuyo `changes` solo contiene la clave del idioma cambiado (`content_translations.fr`) y una `deleted`, con el actor correcto.
- **`CA-UI-049`** [`ADR-033 §9`] **[Pest]** · *Dado* un `GET /tenant/branding` previo (caché caliente), *cuando* el administrador guarda un texto, *entonces* el siguiente `GET` anónimo ya lo devuelve.
- **`CA-UI-050`** [`REQ-CORE/api.md §2`] **[Pest]** · *Dado* `GET /tenant/branding`, *entonces* el límite por IP sigue respondiendo `429` como antes, y un *host* sin centro, `404`.
- **`CA-UI-051`** [`INV-006`, `CLAUDE.md §10`] · *Dado* `apps/api/openapi`, *entonces* `login_welcome` está documentado en las tres representaciones, con `<pieza>` como `readOnly`, y el test de contrato de OpenAPI pasa.
- **`CA-UI-052`** [`RUX-BRAND-005`, F1, F2, `RN-UI-26`] · *Dado* `/administracion/centro/marca` con `configuracion.actualizar`, *entonces* el bloque muestra por cada pieza una entrada por idioma activo con el idioma por defecto primero y marcado como obligatorio, el aviso de texto público, y «Usar el texto por defecto» con confirmación; al guardar se llama a `refresh()` de la capa B; un `422` muestra el mensaje del servidor junto al campo; *y dado* un usuario solo con `configuracion.leer`, el bloque es de solo lectura.
- **`CA-UI-053`** [F3, `RN-UI-23`, WCAG 3.1.2] · *Dado* un *branding* con título personalizado solo en `es-ES`, línea sin personalizar y la página en `fr`, *cuando* se monta `PublicAuthShell`, *entonces* el título es el del centro con `lang="es-ES"`, la línea es la genérica en francés sin `lang` propio, y el antetítulo no se pinta.
- **`CA-UI-054`** [`RN-UI-22`, `RN-DS-22`] · *Dado* la pantalla de acceso en `es`, *cuando* el visitante elige `fr`, *entonces* la capa B repite `GET /tenant/branding` una vez indicando `fr` y el texto mostrado pasa al francés; `getTenantBranding` sigue llamándose solo desde `src/tenant/**`.
- **`CA-UI-055`** [`RN-UI-24`] · *Dado* una respuesta de `GET /tenant/branding` sin `login_welcome`, *cuando* se monta la pantalla, *entonces* se pinta la bienvenida genérica y no hay error.
- **`CA-UI-056`** [`RN-UI-25`, `RN-DS-08`] · *Dado* un centro con texto personalizado, *entonces* `plataforma.brand` contiene exactamente `v`, `primary` y `primaryForeground`.
- **`CA-UI-057`** [E2E real, norma del 2026-11-02] **[Playwright contra la API real]** · *Dado* el centro de pruebas, *cuando* el administrador guarda un título y una línea en dos idiomas y cierra sesión, *entonces* `/entrar` los muestra en el idioma elegido; el segundo centro de pruebas sigue con la bienvenida genérica; y al restablecer, vuelve la genérica.
- **`CA-UI-058`** [`INV-009`] · Las claves nuevas del bloque de ajustes y los mensajes de validación existen en es, en, de y fr (`TranslationParityTest` y paridad de la web).
- **No aplica**: lectura de curso cerrado (sin `academic_year_id`); acceso denegado por ámbito en listado y detalle (único ámbito `todos`, sin listado ni detalle por `public_id`); módulo desactivado (`core` es esencial).

---

## 13. Tramos de `implementer` y verificación

Secuenciales, nunca en paralelo (`CLAUDE.md §2.1`). Antes de cada tramo, *briefing* de `explorer` con lo marcado «sin verificar» en §17.

| Entrega | Tramo | Contenido | Cierre |
|---------|-------|-----------|--------|
| `1.15a-a` | T1 · tokens y radio | §5.1-§5.3: tokens, escala de radios, tests de arquitectura y de contraste (`CA-UI-001` a `-008`, `-023`), correcciones que los tests señalen en componentes vendorizados y vistas | `Verificado: X/X Vitest (ficheros afectados)`; capturas «antes» tomadas **antes** de este tramo |
| `1.15a-a` | T2 · pantalla «Panel» | §5.4-§5.8: `PublicAuthShell`, ilustración, bienvenida genérica, selector de idioma, claves y traducción, *specs* de Playwright (`CA-UI-009` a `-022`) | `Verificado` de suite completa de la web; capturas «después» |
| `1.15a-b` | T1 · datos y API | §7, §8, §9: migración, modelo, validación, caché, tres representaciones, OpenAPI, Pest (`CA-UI-040` a `-051`) | `Verificado` de ficheros afectados y grupo `arch` |
| `1.15a-b` | T2 · interfaz | §6: bloque de ajustes, pintado y `lang`, cambio de idioma, compatibilidad (`CA-UI-052` a `-056`, `-058`) | `Verificado` de la web |
| `1.15a-b` | T3 · E2E y cierre | *Spec* de E2E real (`CA-UI-057`, `-047`), *fixture* del escaparate de la landing, documentación de §14 | Suite completa antes del PR |

Después de cada entrega: `verificador` ejecuta la suite y el E2E real y devuelve el resultado literal; Codex primero; en `1.15a-b`, además `security-reviewer` y `db-reviewer`; `doc-precheck` y `doc-reviewer`. La imagen `sign-in` (`CA-UI-025`) se regenera una vez, al final.

---

## 14. Documentación a actualizar al cerrar

No son ficheros compartidos de la ola, pero quedan fuera de lo que esta especificación puede escribir; los actualiza `implementer` en la entrega indicada.

| Fichero | Cambio | Entrega |
|---------|--------|---------|
| `docs/design-system.md §4.3`, `§4.9`/`§4.10` | `--radius: 2px`; tokens nuevos de §5.1; escala de radios | `a` |
| `docs/design-system.md §10`, `§11` | Reglas de `RN-UI-05`, `RN-UI-08`; par nuevo de contraste; exclusión nominal de `--shadow-*` | `a` |
| `docs/design-system.md §13` | `PublicAuthShell` descrito como «Panel»; `CA-DS-045` sustituido por `CA-UI-021` | `a` |
| `docs/design-system.md §22` | Quitar «pendientes de implementar» de `RN-DS-25` a `-29` (`ADR-064 §7`) | `a` |
| `docs/modulos/REQ-AUTH/funcional.md §1.6` y su `CA-AUTH-063` | Precedencia del idioma de `RN-UI-15` (si `OPEN-UI-12` = A) | `a` |
| `docs/i18n.md` | Selector de idioma en pantallas públicas | `a` |
| Registro de recursos gráficos (`RUX-ICON-007`) | Entrada de la ilustración. Si no existe registro, se crea como sección de `docs/design-system.md` | `a` |
| `apps/landing/README.md` | Quitar el aviso de capturas desfasadas | última |
| `docs/modulos/REQ-CORE/{api,datos,permisos,operacion}.md` y `funcional.md §14.10` | Contrato de §7-§10 y bloque de la pantalla de marca | `b` |
| `apps/api/openapi/` | `login_welcome` | `b` |
| `docs/manual-usuario/admin.md` | Cómo cambiar el texto de bienvenida y el aviso de que es público | `b` |
| `SECURITY.md` | Nota del campo público nuevo y de su tratamiento como texto plano (fichero raíz: ver §15) | `b` |

## 15. Cambios propuestos en ficheros compartidos

Esta rama no los toca; los aplica la sesión orquestadora al integrar (guion de la ola, `§8`).

| Fichero | Cambio propuesto |
|---------|------------------|
| `PLAN-IMPLEMENTACION.md` | Partir `1.15a` en **`1.15a-a` · Capa visual** [SONNET, ordinario, 2 tramos, solo `apps/web`] y **`1.15a-b` · Bienvenida personalizable** [SONNET, **crítico**, 3 tramos, `db-reviewer` y `security-reviewer`], ambas antes de `1.15b` si `OPEN-UI-01` = A. Enlazar esta especificación. Sustituir «si entran el color de marca en el interior…» por: «no entran; `OPEN-UI-10`/`-11`, posible `1.15a-c` con ADR propio». Añadir que la imagen `sign-in` de la landing se regenera al cerrar |
| `memory.md` | Nota: especificación de `1.15a` PROPUESTA en `docs/modulos/REQ-CORE/capa-visual.md`, con 7 preguntas bloqueantes; no se implementa hasta decidirlas |
| `CHANGELOG.md` | Entrada de la ola: especificación de `1.15a` (documento nuevo, sin código) |
| `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` (cabecera e índice `§18`) | Sin ADR nuevo por esta especificación. Solo si el usuario elige `OPEN-UI-02` = B o pide la marca en el interior (`OPEN-UI-10`), una fila por el ADR que se le asigne |
| `ARCHITECTURE.md` | Al cerrar `1.15a-a`: en la sección de frontend, la escala de radios única y los tokens de acceso. Al cerrar `1.15a-b`: `tenant_custom_texts` en el inventario de tablas de `core`, si lo hay |
| `SECURITY.md` | Al cerrar `1.15a-b`: `GET /tenant/branding` publica además el texto de bienvenida, texto plano y acotado |
| `README.md`, `SYSADMIN.md`, `RUNBOOK.md`, `CLAUDE.md` | Sin cambios (sin variables, servicios ni procedimientos nuevos) |
| `.claude/skills/frontend-spa` | Proponer al usuario una línea: «radios solo de la escala de `--radius`; `rounded-full` y `shadow-*` tienen lista cerrada (`docs/design-system.md §10`)» |

---

## 16. Preguntas abiertas

No se resuelven aquí. La primera opción es siempre la recomendada.

### 16.1 Bloquean la implementación

| ID | Pregunta | Opciones | Impacto | Bloquea · decide |
|----|----------|----------|---------|------------------|
| `OPEN-UI-01` | ¿La personalización del texto (`1.15a-b`) va ahora, antes de `1.15b`? | **A (Recomendada)**: sí, `a` y luego `b`, las dos antes de `REQ-SEED`, para que los centros de demostración nazcan con su texto y la imagen de la landing se regenere una sola vez. **B**: ahora solo `a`; `b` después de `REQ-SEED`. **C**: `b` no se hace hasta especificar `RUX-BRAND-005` entero | A: 5 tramos antes de `1.15b`. B: 2 ahora; la decisión 5 de #431 queda a medias. C: se aplaza sin fecha | Planificación · usuario |
| `OPEN-UI-02` | ¿Dónde se guarda un texto traducible **opcional** del centro? (§7.1) | **A (Recomendada)**: tabla `tenant_custom_texts`, una fila por texto; cumple `ADR-062` y `AR-16` sin tocarlos y sirve para el resto de `RUX-BRAND-005`. **B**: columnas anulables en `tenant_settings` y **un ADR que enmiende `ADR-062 §5.1` y `AR-16`**. **C**: `NOT NULL` copiando el genérico | A: tabla, modelo y RLS nuevos (más código, ningún ADR). B: menos código, pero cambia un ADR aceptado y una regla de arquitectura. C: congela texto del producto en datos; no recomendable | `1.15a-b` · usuario, con dictamen de `architect`/`db-reviewer` |
| `OPEN-UI-03` | ¿Qué piezas puede personalizar el centro? | **A (Recomendada)**: las tres de `ADR-064 §2.3` (antetítulo, título, línea), cada una opcional. **B**: título y línea. **C**: solo el título | Número de campos del bloque de ajustes y de valores del `CHECK`; A no cuesta más que B | `1.15a-b` · usuario |
| `OPEN-UI-04` | ¿Se acepta ampliar la respuesta **anónima** de `GET /tenant/branding`, cerrada por regla de seguridad (`api.md §2`)? | **A (Recomendada)**: sí, con las salvaguardas de §8.1 (texto plano, acotado, aviso al autor). **B**: *endpoint* anónimo aparte para el texto. **C**: no publicar texto del centro | A: una petición, como hoy. B: una petición más en cada carga y otro *endpoint* anónimo que limitar. C: equivale a no hacer `1.15a-b` | `1.15a-b` · usuario, con `security-reviewer` |
| `OPEN-UI-05` | ¿Cómo es la ilustración por defecto? | **A (Recomendada)**: patrón abstracto monocromo translúcido sobre el color primario del centro; neutro sin marca. **B**: ilustración fija en tonos neutros. **C**: ilustración fija a color de la plataforma (espera a `0.11c`) | A da color a todo centro con paleta y no toca `RN-DS-02`; a cambio, sin marca y en modo oscuro la región es clara (se valora en las capturas). B repite el «blanco y negro» que motivó #431. C bloquea el paso por la identidad | `1.15a-a` T2 · usuario |
| `OPEN-UI-06` | Textos del acceso: bienvenida genérica (§5.5), texto de ayuda de `/entrar` y marcador del nombre de la plataforma en el pie hasta `0.11c` | **A (Recomendada)**: los propuestos en §5.5, ayuda «Entra con la cuenta que te ha dado el centro.» (maqueta) y, como nombre, el que ya use la SPA en su título. **B**: el usuario los redacta. **C**: ocultar la línea del pie hasta `0.11c` (se aparta de `ADR-064 §2.2`) | Son textos visibles en la primera pantalla de todos los centros y se traducen a cuatro idiomas | `1.15a-a` T2 · usuario |
| `OPEN-UI-12` | El selector de idioma del pie obliga a que la elección explícita prevalezca en pantallas públicas: ¿se amplía `CA-AUTH-063`? | **A (Recomendada)**: elección guardada ∩ activos → navegador ∩ activos → idioma por defecto. **B**: la elección vale solo hasta recargar. **C**: sin selector en el acceso (contradice `ADR-064 §2.2`: exigiría ADR) | A cambia un criterio aprobado de `REQ-AUTH`; sin elección, el comportamiento es el actual | `1.15a-a` T2 · usuario |

### 16.2 No bloquean (la especificación ya propone un valor; se confirman al aprobar)

| ID | Pregunta | Opciones | Decide |
|----|----------|----------|--------|
| `OPEN-UI-07` | *Breakpoint* de una a dos columnas | **A (Recomendada)**: `md` (768 px), ya existente. **B**: `lg` (1024 px; la tableta en vertical ve la franja). **C**: 820 px de la maqueta (añade un punto de cambio ajeno a `RUX-RESP-001`) | Usuario |
| `OPEN-UI-08` | Derivadas de `--radius` | **A (Recomendada)**: toda la escala vale `var(--radius)`. **B**: proporcional (mitad, igual, doble). **C**: las fórmulas actuales acotadas a 0 (deja botones y campos con radio 0, contra la decisión) | Usuario |
| `OPEN-UI-09` | Excepciones circulares | **A (Recomendada)**: `rounded-full` solo en botón de opción, indicadores de carga y avatares; insignias a 2 px. **B**: insignias en píldora. **C**: ninguna excepción | Usuario |
| `OPEN-UI-10` | Marca en el interior (elemento activo del menú, barra lateral o cabecera teñidas, acentos) | **A (Recomendada)**: fuera de `1.15a-a`/`-b`; maqueta → decisión → ADR propio → `1.15a-c`. **B**: solo el elemento activo del menú con tokens existentes (sin ADR), en `1.15a-a` tras verlo en maqueta. **C**: no hacerlo | Usuario; el ADR, `architect` |
| `OPEN-UI-11` | Estados vacíos ilustrados y página de inicio | **A (Recomendada)**: paso propio posterior, con registro de licencias. **B**: tras `1.15b`, cuando haya datos de demostración que los enseñen. **C**: no hacerlo en fase 1 | Usuario |
| `OPEN-UI-13` | Elementos de la maqueta no decididos en `ADR-064` («Mostrar» contraseña, separador «o entra con», monograma sin logo, subtítulo del centro) | **A (Recomendada)**: no entran; issue Bajo por si se quieren. **B**: incluir el monograma. **C**: incluirlos todos (parte toca `REQ-AUTH`) | Usuario |
| `OPEN-UI-14` | Comprobación automática de accesibilidad | **A (Recomendada)**: las aserciones explícitas de §12, sin dependencia nueva. **B**: añadir un motor de reglas de accesibilidad a Playwright (dependencia nueva a justificar, `CLAUDE.md §1`). **C**: auditoría manual al cerrar la fase | Usuario / técnico |
| `OPEN-UI-15` | Longitudes máximas (60 / 120 / 240) | **A (Recomendada)**: las propuestas. **B**: más cortas (40 / 80 / 160). **C**: una sola longitud de 200 | Usuario |
| `OPEN-UI-16` | Valor del velo y altura de la franja móvil | **A (Recomendada)**: 64 % y 180 px, ajustables por el test y por las capturas. **B**: velo al 72 % como la maqueta. **C**: franja de 240 px | Técnico, con capturas |

---

## 17. Hallazgos fuera de ámbito y lo no verificado

### 17.1 Hallazgos (se reportan; no se corrigen aquí)

1. **`RUX-BRAND-005` no tiene especificación en ningún módulo**: solo lo citan `REQUISITOS`, el plan, `ADR-064` y el guion de la ola. `1.15a-b` cubre una parte; el requisito seguirá abierto. Severidad Baja.
2. **Posible doble uso del identificador `AR-16`**: `ADR-062 §5.8` lo define como la regla de las columnas `*_translations`, y el comentario de `TenantMigration::tenantForeignId()` dice que «`AR-16` vigila que toda FK compuesta quede cubierta». Sin verificar contra los tests de arquitectura. Severidad Baja (documentación).
3. `PLAN-IMPLEMENTACION.md` describe `1.15a` como un solo paso «[OPUS + SONNET]»; esta especificación lo parte en dos (§15).
4. La maqueta atenúa el texto sobre la imagen y baja el velo al 45 % tras él; no alcanza 4,5:1 sobre imágenes claras. Corregido en la especificación (`RN-UI-12`), no en la maqueta.

### 17.2 No verificado (la sesión de especificación no tenía `Bash` ni búsqueda local; lo comprueba `explorer` en el *briefing*)

- Inventario real de `rounded-*` y `shadow-*` en `apps/web/src` (alimenta las listas cerradas de `CA-UI-003` y `-006`) y qué componentes vendorizados usan `rounded-2xl`, `-3xl`, `-4xl` o `rounded-full`.
- Si existe un selector de idioma reutilizable del *shell* de 1.8, y una clave única con el nombre del producto.
- Cómo fija hoy la SPA el idioma al arrancar con `plataforma.locale` guardado, y si `apiFetch` envía el idioma elegido (la búsqueda en GitHub indica que `Accept-Language` solo se menciona en `usePublicAuthScreen.ts`).
- Qué guarda exactamente `TenantSettingsCache` y cómo se invalida; el nombre del componente de campo traducible de `ADR-062 §5.7`; la forma exacta de `AR-16`.
- Si existe un registro de licencias de recursos gráficos (`RUX-ICON-007`).
- `ADR-052` y `REQ-CORE/funcional.md §4.8`, `§14.10` no se leyeron directamente: se citan a través de `docs/design-system.md`, `REQ-CORE/api.md` y el código.
- Los valores de contraste de `RN-UI-02` son un cálculo a mano; la autoridad es `CA-UI-007`.
- Ninguna estimación de tramos está medida: es la referencia de `CLAUDE.md §2.7`.

---

## 18. Aprobación

Esta especificación está en estado **PROPUESTO**. `1.15a-a` puede implementarse en cuanto se decidan `OPEN-UI-05`, `-06` y `-12` (su tramo T1 no depende de ninguna); `1.15a-b`, cuando se decidan `OPEN-UI-01` a `-04`.

**¿Se aprueba la especificación —con qué opción en cada pregunta bloqueante— antes de pasar a implementación?**
