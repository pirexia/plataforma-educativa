# Design system

| Campo | Valor |
|-------|-------|
| Paso | **1.7 · Design system** (`PLAN-IMPLEMENTACION.md`, Bloque B) |
| Estado | **APROBADA** (2026-09-22, ratificada por el usuario: las tres preguntas de §20 resueltas con la opción recomendada — A, Sí, 1.8) |
| Decisión de arquitectura | `ADR-052` (ACEPTADA 2026-09-22). Este documento la **desarrolla**, no la reabre |
| Se apoya en | `ADR-023` (Tailwind + shadcn-vue + TanStack), `ADR-025`, `ADR-046`, `RNF-MANT-007`, `RNF-COMP-001` |
| Requisitos cubiertos | `RUX-002`, `RUX-004`, `RUX-005`, `RNF-UX-002`, `RNF-UX-004`, y en cliente `RUX-BRAND-002`, `RUX-BRAND-003`, `RUX-BRAND-006` (en servidor ya los cubre `REQ-CORE`, paso 1.1) |
| Código afectado | Solo `apps/web`. **Ni una línea de `apps/api`**: sin tabla, sin endpoint, sin permiso, sin campo nuevo en `GET /tenant/branding` |
| Por qué no es `docs/modulos/REQ-*` | `ADR-052 §6`: no es un *bounded context*; no tiene datos, API ni permisos. Mismo criterio que `docs/i18n.md` |

### Prefijos de identificadores de este documento

| Prefijo | Qué es |
|---------|--------|
| `CA-DS-NNN` | Criterio de aceptación (§18). Cada uno referencia los `RUX-*`/`RNF-UX-*` que cubre. Los tests lo citan en su nombre, como el resto del proyecto (`INV-015`) |
| `RN-DS-NN` | Regla de diseño o de comportamiento de este documento |
| `OPEN-DS-NN` | Pregunta abierta para el usuario (§20). Mismo patrón que `OPEN-AUTH-*`/`OPEN-BO-*` |

---

## 1. Alcance

### 1.1 Entra en 1.7

1. **Hoja de tokens** propia, en tres niveles (`ADR-052 §3.1`), con los añadidos de `§3.2`: `success`/`warning`/`info` con su `-foreground`, `--primary-on-background` y duraciones de movimiento (§4).
2. **Capa A** (aplicación de la paleta de marca al documento) y **función pura de derivación** de `--primary-on-background` (§5, §6).
3. **Capa B** (contexto del centro: una petición a `GET /tenant/branding` en el arranque, caché de los dos colores, *favicon*, `refresh()`) (§7, §8).
4. **Modo oscuro**: *composable* propio sobre `useColorMode` de `@vueuse/core`, tres estados, persistencia local (§9).
5. **Tests de arquitectura** en Vitest que hacen cumplir las reglas de uso del color y la frontera del *design system* (§10), y **test de contraste** de todos los pares semánticos estáticos en ambos modos (§11).
6. **Adaptación de los ocho componentes vendorizados** a las reglas de §10 (§12), y de cualquier otro fichero de `src/` que los tests de §10 señalen (por ejemplo `LoginView.vue`, que usa `text-primary`).
7. **Migración** de `PublicAuthShell.vue` y `usePublicAuthScreen.ts` al mecanismo global, sin cambio visible salvo el previsto en §13.3.
8. `prefers-reduced-motion` como regla global (§4.6).
9. Corrección de `components.json` (issue [#251](https://github.com/pirexia/plataforma-educativa/issues/251), §15).

### 1.2 No entra en 1.7 (aunque roce el tema)

| Fuera | Dónde va | Motivo |
|-------|----------|--------|
| Paleta oscura configurable por centro | Sin paso; ampliación *expand* si un centro la pide | `ADR-052 §2`/Motivo; `P3` resuelta |
| Sincronía de la preferencia de modo entre dispositivos (en servidor) | Sin paso | `ADR-052 P2` resuelta: solo local |
| Que el centro fuerce o desactive el modo oscuro | Sin paso | `ADR-052 P3` resuelta |
| Tipografía de marca / fuente web | `0.11c` (identidad de marca) | `ADR-052 P4` resuelta: pila del sistema |
| Estados vacíos, de carga y de error (`RUX-006`, `RNF-UX-005`) | **1.8** | Los tokens `success`/`warning`/`info` se crean aquí para que 1.8 los consuma; los componentes que los pintan, no |
| *Layout* autenticado, navegación, menús adaptativos, *breakpoints* (`RUX-003`, `RUX-RESP-*`), *dashboards* | **1.8** | — |
| Control visible para elegir el modo de color y selector de idioma | **1.8** (`OPEN-DS-03` resuelta) | Necesitan un sitio en el *layout*; ver §9.5 |
| Tablas avanzadas (TanStack Table) | **1.9** | `table` se adapta a las reglas de color, nada más |
| Pantalla de configuración de marca (con previsualización de contraste, `ADR-052 §4`) | Posterior a 1.8 (pantallas de `REQ-CORE`, `OPEN-CORE-02`) | 1.7 deja la función reutilizable (§6) y `refresh()` (§7) listos para ella |
| Validación de contraste en servidor contra los fondos | No se hace | `ADR-052 §4` |
| Extracción a `packages/ui` y acento del backoffice | ADR propio al abrir la SPA del backoffice | `ADR-052 §5` |
| *Restyle* general de las pantallas de `REQ-AUTH` | Sin paso | `REQ-AUTH/funcional.md §1.6` lo asumía «cuando 1.7 exista»; 1.7 solo las migra al mecanismo global y a las reglas de §10 |

### 1.3 Dependencias

Ninguna dependencia no implementada. `GET /tenant/branding` existe desde 1.1 y no cambia. `@vueuse/core` ya es dependencia (`^14.4.0`). **No se introduce ninguna dependencia nueva** (`ADR-052 §3` y Alternativas descartadas): ni Pinia, ni librería de color, ni paquete de tokens. La conversión de color y la fórmula de contraste se escriben en TypeScript propio.

---

## 2. Estado de partida verificado (2026-09-22, rama `feature/1.7-design-system`)

Contrastado contra el código, no solo contra el ADR:

- `src/style.css` contiene a la vez los *imports* de Tailwind, `@custom-variant dark`, el mapeo `@theme inline` y las paletas `:root`/`.dark`. Nada activa `.dark`.
- `src/components/ui/` contiene los ocho componentes que cita el ADR: `badge`, `button`, `input`, `label`, `radio-group`, `select`, `table`, `textarea`. Leídos: usan el color de marca sobre el fondo en `button` (variante `link`: `text-primary`), `badge` (variante `link`: `text-primary`) y `radio-group` (`data-checked:border-primary`, `aria-invalid:aria-checked:border-primary`). `input` y `textarea` usan `border-border`, no `border-input` (relevante para `OPEN-DS-02`). **Limitación de esta verificación**: la sesión de especificación no tenía herramienta de listado de directorios; se leyeron los `index.ts`/`.vue` de los ocho y se comprobó que `card`, `checkbox` y `alert` **no** existen, pero no se puede afirmar que no haya un noveno directorio. El test de §10 recorre `src/` entero, así que cualquier componente no inventariado queda cubierto igualmente.
- Fuera de `components/ui`, `src/modules/auth/views/LoginView.vue` usa `text-primary` en un `RouterLink`. Es previsible que haya más usos en `src/modules/**`; **no se inventarían aquí a mano**: el test de §10.2 es el inventario, y todos los que señale se corrigen en 1.7.
- `PublicAuthShell.vue` liga `--primary`/`--primary-foreground` como `:style` sobre la tarjeta. `usePublicAuthScreen.ts` llama a `getTenantBranding()` en cada `onMounted`, siembra la cookie CSRF y resuelve el idioma (`Accept-Language` ∩ idiomas activos del centro).
- `index.html` sirve `<link rel="icon" type="image/svg+xml" href="/favicon.svg">` fijo; `favicon_url` no se consume.
- `main.ts` monta la SPA sin esperar nada.
- `components.json` declara `"font": "geist-sans"` sin uso.
- `scripts/check-i18n-literals.mjs` excluye `src/components/ui` a propósito.

---

## 3. Estructura de ficheros

```
apps/web/src/
├── style.css                          # solo: imports, @custom-variant, @theme inline, @layer base
├── design-system/                     # FRONTERA del design system (§10.4), junto con components/ui
│   ├── tokens.css                     # ÚNICA hoja de tokens: :root, .dark, bloque pre-JS, movimiento
│   ├── color/
│   │   ├── color.ts                   # hex ↔ sRGB ↔ OKLab/OKLCH, gama sRGB (puro)
│   │   ├── contrast.ts                # luminancia relativa y contraste WCAG 2.x (puro)
│   │   ├── surfaces.ts                # superficies neutras por modo (excepción de §10.3, sincronizada por test)
│   │   └── deriveOnBackground.ts      # §6 (puro)
│   ├── theme/
│   │   └── brandPalette.ts            # capa A (§5)
│   └── color-mode/
│       └── useColorScheme.ts          # modo oscuro (§9), único envoltorio de useColorMode
├── components/ui/                     # componentes base (§12) — también dentro de la frontera
└── tenant/                            # capa B (§7), FUERA de la frontera: conoce el tenant
    ├── useTenantBranding.ts
    └── favicon.ts
```

Motivos de la ubicación:

- **`src/design-system/`** agrupa todo lo que `ADR-052 §5` declara extraíble (hoja de tokens, capa A, modo de color), para que la futura extracción a `packages/ui` sea mover una carpeta más `components/ui/`. `components/ui/` no se mueve: es la ruta del alias `ui` de `components.json` que usa la CLI de shadcn-vue.
- **`src/tenant/`** es de nivel de aplicación, como `src/i18n/`: la consumen módulos distintos (`auth` hoy, el *layout* de 1.8 mañana). No va dentro de `src/modules/core/` para no convertir un *composable* de presentación en parte de la superficie pública del módulo `core` (`INV-007`); consume `getTenantBranding()` de `@/modules/core/api`, que sí es su superficie pública.

---

## 4. Catálogo de tokens

### 4.1 Niveles (`ADR-052 §3.1`)

| Nivel | Nombres | Quién escribe | Dónde |
|-------|---------|---------------|-------|
| **Entrada de marca** | `--brand-primary`, `--brand-primary-foreground`, `--brand-primary-on-background-light`, `--brand-primary-on-background-dark` | **Solo la capa A**, por CSSOM, sobre `<html>` (§5) | Ninguna hoja de estilos los define (`RN-DS-01`) |
| **Semántico** | Nombres de shadcn-vue + añadidos (§4.3) | La hoja de tokens | `tokens.css`, en `:root` y `.dark` |
| **Utilidad** | `--color-*`, `--radius-*`, `--font-*`, `--default-transition-duration` | La hoja de tokens | `@theme inline` de `style.css` |

**`RN-DS-01`** · Ningún fichero salvo `design-system/theme/brandPalette.ts` escribe una propiedad `--brand-*`, y ninguno salvo `tokens.css` la lee. Lo verifica `CA-DS-041`.

**`RN-DS-02`** · Solo los semánticos de «primario» dependen de la marca: `--primary`, `--primary-foreground`, `--primary-on-background`, `--ring`, `--sidebar-primary`, `--sidebar-primary-foreground`, `--sidebar-ring` (este último indirectamente, vía `--ring`). Todo semántico de marca tiene la forma `var(--brand-…, <neutro>)`. `color_secondary` alimenta `--primary-foreground`, **nunca** `--secondary` (`ADR-052 §3.1`, `P1`).

### 4.2 Entrada de marca

| Variable | Valor | Origen |
|----------|-------|--------|
| `--brand-primary` | `color_primary` normalizado a `#RRGGBB` | `GET /tenant/branding` |
| `--brand-primary-foreground` | `color_secondary` normalizado a `#RRGGBB` | `GET /tenant/branding` (texto sobre el primario, `ADR-052 P1`) |
| `--brand-primary-on-background-light` | `deriveOnBackground(color_primary, 'light')` | §6 |
| `--brand-primary-on-background-dark` | `deriveOnBackground(color_primary, 'dark')` | §6 |

Las cuatro se escriben juntas o no se escribe ninguna (`RN-DS-05`).

### 4.3 Semánticos

Leyenda de la columna «Cambio»: **=** igual que hoy · **nuevo** · **marca** (pasa a depender de `--brand-*`) · **AA** (valor corregido porque el actual incumple el contraste que exige §11; motivo en §4.4).

Todos los valores en `oklch()`. Los valores de los tokens **nuevos** y **AA** son propuestos con el cálculo de §4.4; **el test de §11 es la autoridad**: si alguno no alcanza su umbral, el implementador ajusta solo la `L` en pasos de `0.005` hacia el lado que aumenta el contraste y **actualiza esta tabla** en el mismo *commit*.

| Semántico | Modo claro (`:root`) | Modo oscuro (`.dark`) | Cambio |
|-----------|----------------------|------------------------|--------|
| `--background` | `oklch(1 0 0)` | `oklch(0.145 0 0)` | = |
| `--foreground` | `oklch(0.145 0 0)` | `oklch(0.985 0 0)` | = |
| `--card` | `oklch(1 0 0)` | `oklch(0.205 0 0)` | = |
| `--card-foreground` | `oklch(0.145 0 0)` | `oklch(0.985 0 0)` | = |
| `--popover` | `oklch(1 0 0)` | `oklch(0.205 0 0)` | = |
| `--popover-foreground` | `oklch(0.145 0 0)` | `oklch(0.985 0 0)` | = |
| `--primary` | `var(--brand-primary, oklch(0.205 0 0))` | `var(--brand-primary, oklch(0.922 0 0))` | marca |
| `--primary-foreground` | `var(--brand-primary-foreground, oklch(0.985 0 0))` | `var(--brand-primary-foreground, oklch(0.205 0 0))` | marca |
| `--primary-on-background` | `var(--brand-primary-on-background-light, oklch(0.205 0 0))` | `var(--brand-primary-on-background-dark, oklch(0.922 0 0))` | nuevo, marca |
| `--secondary` | `oklch(0.97 0 0)` | `oklch(0.269 0 0)` | = |
| `--secondary-foreground` | `oklch(0.205 0 0)` | `oklch(0.985 0 0)` | = |
| `--muted` | `oklch(0.97 0 0)` | `oklch(0.269 0 0)` | = |
| `--muted-foreground` | `oklch(0.54 0 0)` (hoy `0.556`) | `oklch(0.708 0 0)` | AA (claro) |
| `--accent` | `oklch(0.97 0 0)` | `oklch(0.269 0 0)` | = |
| `--accent-foreground` | `oklch(0.205 0 0)` | `oklch(0.985 0 0)` | = |
| `--destructive` | `oklch(0.505 0.213 27.518)` (hoy `0.577 0.245 27.325`) | `oklch(0.704 0.191 22.216)` | AA (claro) |
| `--success` | `oklch(0.527 0.154 150.069)` | `oklch(0.792 0.209 151.711)` | nuevo |
| `--success-foreground` | `oklch(0.985 0 0)` | `oklch(0.205 0 0)` | nuevo |
| `--warning` | `oklch(0.555 0.163 48.998)` | `oklch(0.828 0.189 84.429)` | nuevo |
| `--warning-foreground` | `oklch(0.985 0 0)` | `oklch(0.205 0 0)` | nuevo |
| `--info` | `oklch(0.488 0.243 264.376)` | `oklch(0.707 0.165 254.624)` | nuevo |
| `--info-foreground` | `oklch(0.985 0 0)` | `oklch(0.205 0 0)` | nuevo |
| `--border` | `oklch(0.922 0 0)` | `oklch(1 0 0 / 10%)` | = (decorativo, fuera de §11) |
| `--input` | `oklch(0.64 0 0)` (≈ 3,36:1 sobre `--background`) | `oklch(1 0 0 / 36%)` (≈ 3,25:1 sobre `--background`) | AA (`OPEN-DS-02` resuelta: sí) |
| `--ring` | `var(--primary-on-background)` (hoy `0.708`) | `var(--primary-on-background)` (hoy `0.556`) | marca, AA |
| `--chart-1` … `--chart-5` | sin cambio | sin cambio | = (sin consumidor en 1.7; fuera de §11) |
| `--radius` | `0.625rem` | — | = |
| `--sidebar` | `oklch(0.985 0 0)` | `oklch(0.205 0 0)` | = |
| `--sidebar-foreground` | `oklch(0.145 0 0)` | `oklch(0.985 0 0)` | = |
| `--sidebar-primary` | `var(--brand-primary, oklch(0.205 0 0))` | `var(--brand-primary, oklch(0.488 0.243 264.376))` | marca |
| `--sidebar-primary-foreground` | `var(--brand-primary-foreground, oklch(0.985 0 0))` | `var(--brand-primary-foreground, oklch(0.985 0 0))` | marca |
| `--sidebar-accent` | `oklch(0.97 0 0)` | `oklch(0.269 0 0)` | = |
| `--sidebar-accent-foreground` | `oklch(0.205 0 0)` | `oklch(0.985 0 0)` | = |
| `--sidebar-border` | `oklch(0.922 0 0)` | `oklch(1 0 0 / 10%)` | = |
| `--sidebar-ring` | `var(--ring)` | `var(--ring)` | marca (indirecta) |

Además, en la misma hoja:

| Token | Claro | Oscuro | Nota |
|-------|-------|--------|------|
| `color-scheme` (propiedad, no variable) | `light` en `:root` | `dark` en `.dark` | Controles nativos y barras de desplazamiento acompañan (`ADR-052 §2`) |
| `--motion-duration-fast` | `100ms` | igual | §4.6 |
| `--motion-duration-normal` | `150ms` | igual | Es el valor por defecto de transición de Tailwind v4; se mapea a `--default-transition-duration` |
| `--motion-duration-slow` | `300ms` | igual | §4.6 |

**Mapeo a utilidades** (`@theme inline` de `style.css`), además del existente: `--color-primary-on-background`, `--color-success`, `--color-success-foreground`, `--color-warning`, `--color-warning-foreground`, `--color-info`, `--color-info-foreground`, `--default-transition-duration: var(--motion-duration-normal)`. Con ello existen `text-primary-on-background`, `border-primary-on-background`, `ring-primary-on-background`, `outline-primary-on-background`, `bg-success`, `text-success`, etc.

**No se redefinen** espaciado, sombras ni escala tipográfica (`ADR-052 §3.2`).

### 4.4 Por qué cambian `--muted-foreground`, `--destructive` y `--ring`

Cálculo con la fórmula de WCAG (para grises OKLCH, luminancia relativa `Y = L³`):

- `--muted-foreground` claro `0.556` (`Y ≈ 0.172`) sobre `--muted` `0.97` (`Y ≈ 0.913`): **≈ 4,34:1 < 4,5**. Es texto de ayuda y *placeholder* y se pinta sobre `bg-muted` (p. ej. el aviso de `LoginView`). Con `0.54`: ≈ 4,64:1.
- `--destructive` claro `0.577 0.245 27.325` (≈ `#E7000B`) como texto sobre `bg-destructive/10` (variantes `destructive` de `button` y `badge`): ≈ **3,99:1**. Con `0.505 0.213 27.518` (≈ `#C10007`): ≈ 5,3:1. En oscuro el par actual ya cumple (≈ 5,3:1 sobre `bg-destructive/20`).
- `--ring` claro `0.708` sobre blanco: **≈ 2,6:1 < 3** (incumple 1.4.11 como indicador de foco). Al pasar a `var(--primary-on-background)` cumple ≥ 4,5:1 por construcción, con o sin marca (el neutro de respaldo es `0.205`/`0.922`).
- `--input` (`OPEN-DS-02`, `CA-DS-050`): el valor de partida propuesto (`oklch(0.66 0 0)` claro, `oklch(1 0 0 / 34%)` oscuro) da ≈ 3,11:1 y ≈ 3,02:1 — un margen menor al 4 % sobre el umbral de 3:1, que el redondeo a 8 bits por canal del navegador podía dejar por debajo. Se amplía el margen a `oklch(0.64 0 0)`/`oklch(1 0 0 / 36%)` (≈ 3,36:1 / ≈ 3,25:1, verificado con el redondeo real en `tokens-contrast.spec.ts`) sin cambiar el criterio.

### 4.5 Bloque previo a JavaScript

Para que con preferencia `system` no haya destello (`ADR-052 §2`), la hoja incluye:

```css
@media (prefers-color-scheme: dark) {
  :root:not(.light):not(.dark) { /* solo --background, --foreground y color-scheme: dark */ }
}
```

`useColorScheme` (§9) pone siempre `.light` o `.dark` en `<html>` en cuanto corre `main.ts`, así que este bloque solo rige hasta entonces y nunca contradice una preferencia explícita. **`RN-DS-03`**: sus dos valores son idénticos a los de `.dark`; lo verifica `CA-DS-007`. No se duplica el resto de la paleta oscura: antes de montar la SPA solo se ve el fondo del `<body>`.

### 4.6 Movimiento (`RUX-005`)

```css
@media (prefers-reduced-motion: reduce) {
  :root { --motion-duration-fast: 0ms; --motion-duration-normal: 0ms; --motion-duration-slow: 0ms; }
  *, *::before, *::after {
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.01ms !important;
    scroll-behavior: auto !important;
  }
}
```

La segunda regla es la que anula en bloque lo que no pasa por los tokens: clases fijas como `duration-100` de `SelectContent` y las animaciones de `tw-animate-css`. `0.01ms` y no `0`: conserva los eventos `transitionend`/`animationend` de los que dependen los componentes de Reka UI para desmontar. Los componentes **no** resuelven `prefers-reduced-motion` uno a uno (`ADR-052 §3.2`).

### 4.7 Tipografía

`--font-sans: system-ui, 'Segoe UI', Roboto, sans-serif` (ya en `@theme inline`, se mantiene). Sin `@font-face` ni petición a terceros (`ADR-052 P4`). Si `0.11c` fija tipografía, cambia este token y nada más.

### 4.8 Colores de marca de un tercero (fijos)

**Hallazgo de 1.7 no anticipado por el inventario de §2**: `GoogleSignInButton.vue` e `IdentityProviderLoginList.vue` (`src/modules/auth/components/`) pintan el logotipo oficial de Google como cuatro colores hexadecimales literales (`#4285F4`, `#34A853`, `#FBBC05`, `#EA4335`), fijos por la guía de marca de Google e idénticos en ambos modos — el mismo patrón que §10.3 ya preveía en abstracto («un color fijo que no dependa del modo», con el QR de MFA como candidato previsible). El QR no lo necesitaba (`QrCode.vue` ya pinta con `currentColor`, `ADR-041`); el logotipo de Google sí.

Se añaden como tokens `--google-blue`/`--google-green`/`--google-yellow`/`--google-red` en `tokens.css` (mismo valor en `:root` y `.dark`, siguiendo la regla de §10.3), mapeados en `@theme inline` (`--color-google-*`) y consumidos como `class="fill-google-blue"` etc. en los dos componentes. **No** llevan el prefijo `--brand-`: ese prefijo está reservado a la entrada de marca del centro (§4.1); estos son un tercero ajeno al tenant.

### 4.9 Velo de capa (`--overlay`), añadido en 1.8

Mismo criterio que §4.8: color fijo que no depende del modo. El componente `sheet` (§12.3, vendorizado en 1.8) necesita un velo oscuro traslúcido detrás del panel de navegación en móvil/tableta, en ambos modos — no forma parte de la paleta semántica del centro ni tiene requisito de contraste (decorativo, como `--border`). `--overlay: oklch(0 0 0 / 40%)` en `tokens.css` (mismo valor en `:root` y `.dark`), mapeado a `--color-overlay` en `@theme inline`, consumido como `bg-overlay` en `SheetOverlay.vue` — sustituye al `bg-black/10` literal que trae el registro de `shadcn-vue` por defecto (`RN-DS-19`).

### 4.10 *Breakpoints* (`RN-CORE-29`, paso 1.8)

`docs/modulos/REQ-CORE/funcional.md §12.7`. 320 px es el ancho mínimo soportado (sin desplazamiento horizontal, WCAG 1.4.10, `CA-CORE-080`); 768, 1024, 1440 y 1920 px son puntos de cambio. Declarados en `@theme` de `style.css` (no en `tokens.css`: son geometría, no color):

```css
--breakpoint-md: 48rem;   /* 768px, igual que el valor por defecto de Tailwind v4 */
--breakpoint-lg: 64rem;   /* 1024px, igual que el valor por defecto */
--breakpoint-xl: 90rem;   /* 1440px, sustituye los 80rem (1280px) por defecto */
--breakpoint-2xl: 120rem; /* 1920px, sustituye los 96rem (1536px) por defecto */
```

`md`/`lg` gobiernan los regímenes de navegación de `RN-CORE-30` (`src/layouts/AppShellLayout.vue`); `2xl` limita el ancho del contenido principal a `max-w-7xl` (80rem) para no superar una longitud de línea legible en pantallas muy anchas — entre `lg` y `2xl` el contenido usa el ancho disponible.

---

## 5. Capa A · `design-system/theme/brandPalette.ts`

### 5.1 Contrato

```ts
export interface BrandPalette {
  primary: string            // '#RRGGBB' (mayúsculas o minúsculas)
  primaryForeground: string  // '#RRGGBB'
}

export function applyBrandPalette(
  palette: BrandPalette | null,
  target?: HTMLElement,      // por defecto document.documentElement; parámetro para test
): void
```

- **`RN-DS-04`** · Escribe **solo** las cuatro variables de §4.2, mediante `target.style.setProperty(...)`; con `null`, las retira con `removeProperty(...)`. Nunca crea un elemento `<style>` ni escribe un semántico (`--primary`, …): eso choca con una CSP sin `'unsafe-inline'` en `style-src` y rompe el nivel de §4.1.
- **`RN-DS-05`** · Todo o nada: si cualquiera de los dos colores no casa con `^#[0-9A-Fa-f]{6}$`, se comporta exactamente como `null` (retira las cuatro). No hay estado parcial. El servidor ya garantiza el formato (`REQ-CORE`), así que esto solo protege de una caché local manipulada.
- Normaliza a `#RRGGBB` en mayúsculas antes de escribir.
- Calcula las dos variantes derivadas en la misma llamada con `deriveOnBackground` (§6).
- Idempotente. Síncrona. No importa nada de `@/modules`, `@/api`, `@/tenant`, el *router* ni `vue-i18n` (§10.4).
- **No conoce el tenant ni el modo de color**: escribe ambas variantes (claro y oscuro) y es `tokens.css` quien elige cuál usa cada modo. Por eso el cambio de modo no vuelve a llamar a la capa A.

---

## 6. Derivación de `--primary-on-background` · `design-system/color/deriveOnBackground.ts`

### 6.1 Contrato

```ts
export type ColorMode = 'light' | 'dark'

export function deriveOnBackground(hex: string, mode: ColorMode): string // '#RRGGBB' en mayúsculas
```

- Función **pura**: sin DOM, sin estado, sin E/S. Su test corre con `// @vitest-environment node` para probarlo (`CA-DS-018`).
- Entrada inválida (no casa con `^#[0-9A-Fa-f]{6}$`): lanza `TypeError`. La capa A valida antes (`RN-DS-05`), así que en ejecución nunca llega una.
- Módulos auxiliares, también puros: `color.ts` (hex ↔ sRGB, sRGB ↔ lineal, lineal ↔ OKLab ↔ OKLCH con las matrices de Björn Ottosson de CSS Color 4, comprobación de gama sRGB) y `contrast.ts` (luminancia relativa y razón de contraste de WCAG 2.x, `(L1 + 0.05) / (L2 + 0.05)`). La función de transferencia sRGB usa el mismo umbral que `ContrastRatioCalculator` del servidor (el implementador lo comprueba en el PHP y lo cita en un comentario; WCAG 2.2 publica `0.04045`). Duplicar la fórmula está aceptado por `ADR-052 §3.3`.

### 6.2 Superficies contra las que se garantiza el contraste

`--primary-on-background` se usa como texto, borde y anillo de foco **sobre cualquier superficie neutra**, no solo sobre `--background`: el enlace «¿Olvidaste la contraseña?» está en una tarjeta, un radio marcado puede estar en un `popover`, un enlace puede estar sobre `bg-muted`. En modo oscuro, además, `--card` (`0.205`) y `--muted` (`0.269`) son más claros que `--background` (`0.145`), así que garantizar contra el fondo solo **no** garantiza contra la tarjeta.

**`RN-DS-06`** (`OPEN-DS-01` resuelta: opción A): la derivación garantiza **≥ 4,5:1 contra todas las superficies neutras opacas del modo**:

| Modo | Superficies (semántico → valor) |
|------|---------------------------------|
| Claro | `--background`, `--card`, `--popover` (`1`), `--sidebar` (`0.985`), `--muted`, `--secondary`, `--accent`, `--sidebar-accent` (`0.97`) |
| Oscuro | `--background` (`0.145`), `--card`, `--popover`, `--sidebar` (`0.205`), `--muted`, `--secondary`, `--accent`, `--sidebar-accent` (`0.269`) |

En la práctica basta con las dos más exigentes de cada modo, pero la función recorre la lista completa: si mañana cambia un valor, no hay que razonar cuál es el peor.

Esas superficies viven en `surfaces.ts` como constantes (la función no puede leer CSS: es pura y jsdom no calcula estilos). Es la **única** excepción a la prohibición de colores literales fuera de la hoja de tokens (§10.3), y un test (`CA-DS-017`) comprueba que coinciden exactamente con `tokens.css`: cambiar una superficie en un sitio sin el otro rompe CI.

### 6.3 Algoritmo

1. Convertir `hex` a OKLCH `(L₀, C₀, h₀)`.
2. Si el color ya cumple ≥ 4,5:1 contra todas las superficies del modo, **devolverlo sin cambios** (normalizado).
3. Si no, buscar por bisección (≥ 24 iteraciones) la luminosidad `L` más cercana a `L₀` que cumpla, **conservando `h₀` y `C₀`**:
   - modo claro: `L ∈ [0, L₀]` (oscurecer);
   - modo oscuro: `L ∈ [L₀, 1]` (aclarar).
4. Si `(L, C₀, h₀)` cae fuera de la gama sRGB, reducir **solo** el croma por bisección hasta entrar en gama (mapeo de gama por reducción de croma de CSS Color 4, simplificado; `L` y `h` no se tocan).
5. Redondear a `#RRGGBB` y **verificar el contraste sobre el hex redondeado**. Si el redondeo lo dejó por debajo de 4,5:1 (o la bisección no fue monótona por el mapeo de gama), avanzar `L` en pasos de `0.005` en la misma dirección hasta cumplir. Termina siempre: `L = 0` (negro) cumple contra todas las superficies claras y `L = 1` (blanco) contra todas las oscuras.
6. Devolver el hex en mayúsculas.

Propiedades que se derivan y se prueban: **idempotencia**, **monotonía** (en claro nunca aclara, en oscuro nunca oscurece), **minimalidad** (si ajustó, acercar `L` 0,01 hacia `L₀` rompe el umbral), **conservación del tono** (si el croma resultante es ≥ 0,02, `|h_out − h₀| ≤ 2°`; por debajo el tono no es perceptible y no se exige).

### 6.4 Casos de prueba concretos

Las cifras de contraste son aproximadas (orientativas para el lector); el test comprueba el umbral, no la cifra.

| Entrada | Modo | Resultado esperado | Por qué |
|---------|------|--------------------|---------|
| `#FFFF00` (amarillo puro) | claro | **Ajustado**: tono amarillo-oliva oscuro, ≥ 4,5:1 contra todas las superficies claras | ≈ 1,07:1 contra blanco |
| `#FFFF00` | oscuro | **Sin cambios** | ≈ 17:1 contra `0.269` |
| `#FFF59D` (amarillo claro) | claro | **Ajustado** | ≈ 1,1:1 |
| `#1E3A8A` (azul oscuro) | claro | **Sin cambios** | ≈ 10:1 contra `0.97` |
| `#1E3A8A` | oscuro | **Ajustado**: azul más claro, ≥ 4,5:1 contra `0.269` | ≈ 1,6:1 contra `0.145` |
| `#1D4ED8` (azul corporativo típico) | oscuro | **Ajustado** | ≈ 3:1 contra `0.145` (el ejemplo de `ADR-052 §Contexto`) |
| `#808080` (gris medio) | claro | **Ajustado** (más oscuro) | ≈ 3,9:1 contra blanco |
| `#808080` | oscuro | **Ajustado** (más claro) | ≈ 5:1 contra `0.145` pero ≈ 3,8:1 contra `0.269`: solo se detecta con `RN-DS-06` |
| `#767676` | claro | **Ajustado** | ≈ 4,54:1 contra blanco (cumpliría contra el fondo) pero ≈ 4,2:1 contra `0.97` |
| `#000000` | claro | **Sin cambios** | |
| `#000000` | oscuro | **Ajustado** a un gris claro (croma 0) | |
| `#FFFFFF` | claro | **Ajustado** a un gris oscuro | |
| `#FFFFFF` | oscuro | **Sin cambios** | |
| `#1d4ed8` (minúsculas) | claro | Igual que `#1D4ED8` en claro, en mayúsculas | Normalización |
| `'1D4ED8'`, `'#12345'`, `'#GGGGGG'` | cualquiera | `TypeError` | Contrato |

Más un **barrido** de las 216 combinaciones de `R, G, B ∈ {00, 33, 66, 99, CC, FF}` en ambos modos, comprobando umbral, monotonía, minimalidad e idempotencia.

### 6.5 Uso futuro

La pantalla de configuración de marca (posterior a 1.8) puede importar `contrast.ts` y `deriveOnBackground` para **previsualizar**. Nunca decide nada: el `422` de `RN-CORE-15` es la única validación (`ADR-052 §4`, `INV-010`).

---

## 7. Capa B · `tenant/useTenantBranding.ts`

### 7.1 Contrato

```ts
export type TenantBrandingStatus = 'loading' | 'ready' | 'not-found' | 'unavailable'

export const BRANDING_BOOT_TIMEOUT_MS = 1000
export const BRAND_CACHE_KEY = 'plataforma.brand'

/** Arranque, síncrono: aplica la paleta cacheada, si la hay y es válida. */
export function primeBrandingFromCache(): void

/** Arranque: lanza la única petición. Resuelve cuando llega la respuesta o
 *  al vencer timeoutMs, lo que ocurra antes. Nunca rechaza. */
export function bootstrapTenantBranding(options?: { timeoutMs?: number }): Promise<void>

export function useTenantBranding(): {
  branding: Readonly<Ref<TenantBranding | null>>   // tipo de @/modules/core/types
  status: Readonly<Ref<TenantBrandingStatus>>
  refresh(): Promise<void>                         // nunca rechaza
  reportAssetError(url: string): void              // §7.4
}
```

Singleton de ámbito de módulo (`shallowRef` a nivel de fichero), sin Pinia (`ADR-052`, Alternativas). Los tests aíslan el estado con `vi.resetModules()`; **no** se exporta una función de *reset* para tests.

### 7.2 Comportamiento ante cada respuesta de `GET /tenant/branding`

| Resultado | `branding` | `status` | Paleta (capa A) | Caché `plataforma.brand` | *Favicon* |
|-----------|-----------|----------|-----------------|--------------------------|-----------|
| `200` con `color_primary` y `color_secondary` no nulos | respuesta | `ready` | `{primary, primaryForeground}` | se escribe | `favicon_url` o el por defecto |
| `200` con alguno de los dos nulo | respuesta | `ready` | `null` (neutros) | se **borra** | `favicon_url` o el por defecto |
| `404` (host sin tenant) | `null` | `not-found` | `null` | se **borra** | por defecto |
| Error de red, `429`, `5xx` | se conserva el anterior (o `null`) | `unavailable` | **se conserva** la aplicada (la de la caché, si la había) | **no se toca** | sin cambios |

- **`RN-DS-07`** · Los dos colores se aplican solo si **ambos** vienen informados: es el comportamiento actual de `PublicAuthShell` y lo que el par validado por `RN-CORE-15` presupone.
- **`RN-DS-08`** · La caché guarda **exactamente** `{"v":1,"primary":"#RRGGBB","primaryForeground":"#RRGGBB"}`. **Nunca** una URL (las firmadas caducan, `REQ-CORE/api.md §2`), ni el nombre, ni los idiomas. Es por origen del navegador, y el origen es el tenant (resolución por *host*, `RUX-DOM-001`/`002`), así que no hay mezcla entre centros. Al leerla, un valor que no parsee, con `v` distinto de `1` o con colores fuera de formato **se borra** y no se aplica. Todo acceso a `localStorage` va en `try/catch` (navegación privada, almacenamiento bloqueado): si falla, se sigue sin caché.
- Con `unavailable` y paleta cacheada, las pantallas públicas muestran los colores del centro sin nombre ni logo. Hoy muestran los neutros. Es la consecuencia prevista de `ADR-052 §1` (los colores son públicos y estables) y se acepta.

### 7.3 `refresh()`

- Repite la petición y aplica la tabla de §7.2.
- **Deduplicada**: llamadas concurrentes comparten la misma petición en vuelo.
- Si falla, **no degrada** un estado `ready`: conserva `branding` y la paleta, y deja `status` en `ready` (el dato que había sigue siendo válido; lo que falló es la renovación).
- Consumidores previstos: la futura pantalla de configuración de marca tras un `PATCH /tenant/settings` o un cambio de activo (posterior a 1.8), y `reportAssetError` (§7.4). En 1.7 no hay ningún consumidor de pantalla.

### 7.4 Caducidad de URL firmada en sesiones largas

`reportAssetError(url)` la llama quien pinta un activo (`<img @error>`) cuando falla su carga. Lanza `refresh()` **una sola vez por URL**: si la URL que falla ya se reportó, no hace nada. Evita el bucle petición → URL que falla → petición cuando el fallo no es de caducidad (activo borrado, red caída).

### 7.5 *Favicon* (`RUX-BRAND-003`) · `tenant/favicon.ts`

```ts
export function applyFavicon(url: string | null): void
```

- Opera sobre el primer `link[rel~="icon"]` del documento. En la primera llamada memoriza su `href` y su `type` originales.
- Con URL: fija `href` y **retira** `type` (el *favicon* del centro puede ser PNG, ICO o SVG, `REQ-CORE/api.md §2.2`).
- Con `null`: restaura `href` y `type` originales.
- No escribe nada en caché.

---

## 8. Arranque (`main.ts`)

Orden obligatorio:

```
import './style.css'
initColorScheme()               // §9, síncrono: pone .light/.dark en <html>
primeBrandingFromCache()        // §7, síncrono: paleta de la visita anterior, sin destello
document.documentElement.lang = …   (como hoy)
bootstrapTenantBranding().then(() => createApp(App).use(router).use(i18n).mount('#app'))
```

- **`RN-DS-09`** · Tiempo máximo de espera antes de montar: **1000 ms** (`BRANDING_BOOT_TIMEOUT_MS`), el valor que `ADR-052 §1` delega en esta especificación. Motivo: por encima de un segundo el retraso se percibe como espera; por debajo, en una red lenta la primera visita pintaría casi siempre con neutros. Solo afecta a la **primera** visita a un centro desde un navegador: en las siguientes la caché aplica la paleta antes de la espera. Pasado el plazo se monta con lo que haya y la marca se aplica en cuanto llegue (la capa B es reactiva).
- Sin `await` de nivel superior en `main.ts`: una promesa encadenada, para no depender del *target* de compilación.
- `bootstrapTenantBranding` **nunca rechaza**: un fallo de la petición no puede impedir montar la SPA.
- **No va en un *guard* de *router*** (`ADR-052 §1`).

---

## 9. Modo oscuro · `design-system/color-mode/useColorScheme.ts`

### 9.1 Contrato

```ts
export type ColorModePreference = 'system' | 'light' | 'dark'
export const COLOR_MODE_STORAGE_KEY = 'plataforma.color-mode'

/** Arranque, síncrono. Idempotente. */
export function initColorScheme(): void

export function useColorScheme(): {
  preference: Readonly<Ref<ColorModePreference>>
  resolved: Readonly<Ref<'light' | 'dark'>>   // lo que se está pintando
  setPreference(value: ColorModePreference): void
}
```

### 9.2 Reglas

- **`RN-DS-10`** · Tres estados; por defecto `system`, que sigue `prefers-color-scheme` y **reacciona** a su cambio en caliente.
- **`RN-DS-11`** · En `<html>` queda siempre **exactamente una** de las clases `light` o `dark` tras `initColorScheme()` (también en `system`: se pone la resuelta). `color-scheme` lo pone la hoja de tokens según la clase (§4.3), no JavaScript.
- **`RN-DS-12`** · Persistencia en `localStorage`, clave `plataforma.color-mode` (mismo prefijo que `plataforma.locale`, `docs/i18n.md`). Valor: una de tres cadenas, sin dato personal. Un valor desconocido equivale a `system`. Acceso protegido contra excepciones de almacenamiento. **No** se guarda en servidor (`ADR-052 P2`).
- **`RN-DS-13`** · Implementado sobre `useColorMode` de `@vueuse/core` (v14) **envuelto**: `useColorScheme.ts` es el único fichero de `src/` que importa `useColorMode`, `usePreferredDark` o `usePreferredColorScheme` (`RNF-MANT-007`, `CA-DS-040`). La traducción `system` ↔ `auto` de VueUse queda dentro del envoltorio; ningún consumidor ve `auto`. El implementador comprueba la API exacta de v14 (Context7) antes de escribirlo.
- **`RN-DS-14`** · `disableTransition: false`. La opción por defecto de VueUse inyecta un elemento `<style>` al cambiar de modo, que una CSP estricta sin `'unsafe-inline'` bloquea (`SECURITY.md`, `OPEN-11`). La supresión de transiciones al cambiar de modo no se necesita.
- **`RN-DS-15`** · Ortogonal a la marca (`ADR-052 §2`): cambiar de modo **no** llama a la capa A ni toca `--brand-*`. Lo que cambia es qué neutros y qué variante derivada eligen los semánticos en CSS.
- Destello de un fotograma con preferencia explícita contraria al sistema: aceptado (`ADR-052 §2`). **No** se añade `<script>` en línea a `index.html`.

### 9.3 Funciona antes y después del *login*

El *composable* no depende de sesión ni de tenant. En orígenes distintos (subdominio o dominio propio de cada centro) la preferencia es independiente: consecuencia aceptada en `ADR-052`.

### 9.4 Un único punto de verdad para `dark:`

La variante `@custom-variant dark (&:is(.dark *))` se conserva: casa con cualquier descendiente de `<html class="dark">`.

### 9.5 Control visible

1.7 **no** entrega el control para elegir el modo (ni el selector de idioma que `docs/i18n.md` situaba en «1.7/1.8»): ambos necesitan un sitio en el *layout*, que es 1.8, y sus etiquetas generan claves de traducción que pertenecen al *layout*. Con 1.7, el modo oscuro funciona siguiendo el sistema operativo (estado `system`), y `setPreference` queda listo para el control. Confirmado en `OPEN-DS-03`: el control entra en 1.8.

---

## 10. Reglas de uso y tests de arquitectura

Todas se comprueban en **Vitest** (`src/design-system/architecture.spec.ts`, más los que se indiquen), leyendo ficheros con `node:fs`. Fallan en CI; no dependen de que un revisor las recuerde (`ADR-052 §3.3`). **No hay comentario de exclusión en línea** («`// ds-ignore`» o similar): las excepciones son una lista cerrada en el propio test, y ampliarla es un cambio revisable.

### 10.1 Alcance de los ficheros analizados

- **Incluidos**: `apps/web/src/**/*.{vue,ts,css}`.
- **Excluidos siempre**: `**/*.spec.ts`, `**/*.test.ts` (los tests usan colores literales como datos de entrada), y las excepciones nominales de cada regla.
- Antes de buscar, se eliminan los **comentarios** (`//…`, `/* … */`, `<!-- … -->`): un `#251` en un comentario no es un color.

### 10.2 Uso del color de marca (`RN-DS-16`)

Falla ante cualquier aparición, con o sin prefijo de variante (`hover:`, `data-checked:`, `dark:`…) y con o sin modificador de opacidad (`/50`), de:

- `text-primary`, `border-primary` (y los lados `border-{t,r,b,l,x,y,s,e}-primary`), `outline-primary`, `ring-primary`, `ring-offset-primary`, `decoration-primary`, `divide-primary`, `fill-primary`, `stroke-primary`, `caret-primary`, `accent-primary`;
- las mismas con `sidebar-primary`.

Las cuatro primeras son las que enumera `ADR-052 §3.3`; el resto son la misma regla aplicada a las demás propiedades que pintan el color sobre el fondo. **No** casan (límite de palabra con guion): `text-primary-foreground`, `text-primary-on-background`, `border-primary-on-background`, etc. Sustituto: la variante `*-primary-on-background`.

Además:

- **`RN-DS-17`** · `bg-primary` con modificador de opacidad (`bg-primary/80`, también en variantes como `[a]:hover:bg-primary/80`) está prohibido: el contraste del par solo está garantizado sobre el color sólido. Mismo criterio para `bg-sidebar-primary/NN`.
- **`RN-DS-18`** · Toda lista de clases (atributo `class`/`:class` de plantilla, o cadena de `cva`) que contenga `bg-primary` contiene también `text-primary-foreground` (idem `bg-sidebar-primary` → `text-sidebar-primary-foreground`). Se comprueba por cadena literal de clases; la variante `data-checked:bg-primary` casa con `data-checked:text-primary-foreground`.

### 10.3 Colores literales (`RN-DS-19`)

Falla ante, fuera de las excepciones:

- hexadecimales `#RGB`, `#RGBA`, `#RRGGBB`, `#RRGGBBAA` en contexto de valor (precedidos de comilla, espacio, `:`, `(`, `[` o `,`);
- funciones de color: `rgb(`, `rgba(`, `hsl(`, `hsla(`, `hwb(`, `lab(`, `lch(`, `oklab(`, `oklch(`, `color(`;
- clases arbitrarias de Tailwind con color: `-[#…]`, `-[rgb(…)]`, `-[oklch(…)]`, etc. (las cubren los dos puntos anteriores);
- clases de la **paleta por defecto de Tailwind**: `{bg,text,border,…}-{slate,gray,zinc,neutral,stone,red,orange,amber,yellow,lime,green,emerald,teal,cyan,sky,blue,indigo,violet,purple,fuchsia,pink,rose}-{50…950}` y `-white`/`-black`. Son colores literales con otro nombre: saltan los tokens igual que un hex. Se permiten `transparent`, `current` e `inherit`.

**Excepciones nominales (cerradas)**: `src/design-system/tokens.css` (es la hoja de tokens) y `src/design-system/color/surfaces.ts` (§6.2, sincronizado por `CA-DS-017`).

Si algún componente necesita un color fijo que no dependa del modo (candidato previsible: el QR de alta de MFA, que debe ser oscuro sobre claro en ambos modos para que lo lean las aplicaciones), se añade **como token** en `tokens.css`, con el mismo valor en `:root` y `.dark`, se mapea en `@theme inline` y se documenta en §4.3. Nunca como literal en el componente.

### 10.4 Frontera del *design system* (`RN-DS-20`, `ADR-052 §5`)

Alcance: `src/design-system/**` y `src/components/ui/**`. Falla si alguno importa (estático o dinámico, por alias o por ruta relativa resuelta):

- `@/modules/**`, `@/api/**`, `@/tenant/**`, `@/router/**`, `@/i18n/**`, `@/layouts/**`, `@/views/**`;
- `vue-router`, `vue-i18n`.

Destinos `@/` permitidos: `@/design-system/**`, `@/components/ui/**`, `@/lib/utils`. Los paquetes de terceros no listados están permitidos (`vue`, `reka-ui`, `@vueuse/core`, `class-variance-authority`, `clsx`, `tailwind-merge`, `@lucide/vue`).

### 10.5 Reglas de un solo punto de entrada

- **`RN-DS-21`** · `useColorMode`, `usePreferredDark` y `usePreferredColorScheme` solo se importan en `src/design-system/color-mode/useColorScheme.ts`. (Otras utilidades de `@vueuse/core`, como `reactiveOmit` en los componentes base, siguen permitidas.)
- **`RN-DS-22`** · El identificador `getTenantBranding` solo aparece en `src/modules/core/api/**` (donde se define) y `src/tenant/**` (`ADR-052 §1`: la capa B es el único llamador).
- **`RN-DS-01`** (§4.1) · La cadena `--brand-` solo aparece en `src/design-system/theme/**` y `src/design-system/tokens.css`.

### 10.6 Los tests prueban que saben fallar

Cada regla de §10.2-§10.5 tiene casos con **contenido fijo en el propio test** (cadenas, no ficheros del repositorio) que deben detectarse y casos que no. Sin esto, un test que siempre pasa es indistinguible de uno correcto.

---

## 11. Contraste de los pares semánticos estáticos (`RUX-004`, `RNF-UX-002`)

Test `src/design-system/tokens-contrast.spec.ts`. Lee `tokens.css` como texto y extrae el valor de cada semántico en `:root` y `.dark`.

- Resolución: `var(--brand-…, X)` → `X` (el neutro: es el caso estático; el caso con marca lo cubre §6); `var(--otro)` → valor de `--otro` en el mismo modo; `oklch(L C H)` y `oklch(L C H / A%)` → color. **Cualquier otro formato hace fallar el test** (`CA-DS-006`): obliga a que la hoja siga siendo analizable.
- Los pares con opacidad (`bg-destructive/10`) se componen sobre la superficie indicada en sRGB, como hace el navegador.

| Tipo | Primer plano | Sobre | Umbral |
|------|--------------|-------|--------|
| Texto | `foreground` | `background`, `muted`, `secondary`, `accent` | 4,5 |
| Texto | `card-foreground` / `popover-foreground` | `card` / `popover` | 4,5 |
| Texto | `primary-foreground` | `primary` (neutro) | 4,5 |
| Texto | `secondary-foreground` / `accent-foreground` | `secondary` / `accent` | 4,5 |
| Texto | `muted-foreground` | `background`, `card`, `popover`, `muted` | 4,5 |
| Texto | `destructive` | `background`, `card`, `popover`, `muted` | 4,5 |
| Texto | `destructive` | `destructive` al 10 % (claro) / 20 % (oscuro) sobre `background` — las opacidades que usan `button` y `badge` | 4,5 |
| Texto | `success`, `warning`, `info` | `background`, `card`, `popover`, `muted` | 4,5 |
| Texto | `success-foreground`, `warning-foreground`, `info-foreground` | `success`, `warning`, `info` | 4,5 |
| Texto | `primary-on-background` (neutro) | las superficies de §6.2 | 4,5 |
| Texto | `sidebar-foreground` / `sidebar-accent-foreground` / `sidebar-primary-foreground` | `sidebar` / `sidebar-accent` / `sidebar-primary` (neutro) | 4,5 |
| No textual | `ring` | las superficies de §6.2 | 3 (lo cumple por ser `primary-on-background`) |
| No textual | `input` | `background` | 3 (`OPEN-DS-02` resuelta: sí; `CA-DS-050`) |

Fuera a propósito: `border`/`sidebar-border` (separadores decorativos, no identifican un control) y `chart-*` (sin consumidor en 1.7; los gráficos llegarán con su propio criterio de accesibilidad).

---

## 12. Componentes base

### 12.1 Catálogo de 1.7

Se adoptan los ocho ya vendorizados. **No se añade ninguno**: ningún entregable de 1.7 los necesita, y los candidatos obvios (`alert`, `card`, `dialog`, `dropdown-menu`, `skeleton`, `sonner`, `switch`, `checkbox`) los pedirá 1.8 o 1.9 cuando tengan consumidor. Añadirlos ahora sería adelantar alcance sin uso que los valide.

| Componente | Adaptación en 1.7 |
|------------|--------------------|
| `button` | `link`: `text-primary` → `text-primary-on-background`. `default`: se retira `[a]:hover:bg-primary/80` (`RN-DS-17`) y `border-transparent` pasa a `border-primary-on-background` en esa variante (mitigación de `ADR-052 §Consecuencias`: con un primario muy oscuro en modo oscuro, o muy claro en claro, el botón conserva un contorno distinguible del fondo; con un primario que ya contrasta, borde y relleno coinciden y no se ve diferencia). Sin cambio en el resto de variantes |
| `badge` | Igual que `button`: `link` y `default` |
| `radio-group` | `data-checked:border-primary` y `aria-invalid:aria-checked:border-primary` → `…-primary-on-background`. El relleno `data-checked:bg-primary` se mantiene (con `text-primary-foreground`, que ya lleva): el estado marcado queda identificado por un borde ≥ 3:1 contra el fondo aunque el relleno no lo sea (1.4.11). Se retira también `dark:data-checked:bg-primary`: era redundante (`--primary` ya cambia por modo) y, sin su `dark:data-checked:text-primary-foreground` emparejado, incumplía `RN-DS-18` |
| `input`, `textarea` | Sin cambio de color de marca. `border-border` → `border-input` (`OPEN-DS-02` resuelta: sí) |
| `select` | Sin uso de marca detectado. Se somete a §10 como el resto |
| `label`, `table` | Sin cambio previsto. Se someten a §10 |

**`RN-DS-23`** · Indicador de foco: todo componente enfocable de la lista mantiene un elemento de opacidad completa con el color `ring` al enfocarse con teclado (hoy `focus-visible:border-ring`). El halo `ring-ring/50` es complementario y no cuenta para el contraste. Lo comprueba `CA-DS-043`.

**`RN-DS-24`** · Un componente base no tiene texto propio: todo lo visible, incluidos `aria-label` y textos solo para lectores de pantalla, llega por *props* o *slots* (§14).

### 12.1b Objetivos táctiles (`OPEN-CORE-14`, resuelta por el usuario, paso 1.8)

`docs/modulos/REQ-CORE/funcional.md §12.7`, `RUX-RESP-007`: variante `any-pointer: coarse` (sintaxis arbitraria de Tailwind v4, `[@media(any-pointer:coarse)]:…`) que eleva la altura mínima a 44 px en dispositivos táctiles **sin cambiar el escritorio con ratón** — opción B de `OPEN-CORE-14`, lectura literal de «objetivos **táctiles**». Toca los ocho componentes base:

| Componente | Cambio |
|------------|--------|
| `button` | Las ocho variantes de `size` ganan `[@media(any-pointer:coarse)]:min-h-11` (las de solo icono, además `min-w-11`) |
| `badge` | Cuando se usa como enlace (`[a]:hover:…`), `[@media(any-pointer:coarse)]:[a]:min-h-11` |
| `input`, `select` (`select-trigger`) | `[@media(any-pointer:coarse)]:min-h-11` |
| `textarea` | Sin cambio: `min-h-24` (96 px) ya supera 44 px |
| `radio-group` (`radio-group-item`) | La zona de contacto invisible que ya amplía el círculo visible (`after:-inset-x-3 after:-inset-y-2`) sube a 44×44 px exactos en punteros gruesos: `[@media(any-pointer:coarse)]:after:-inset-x-[14px] [@media(any-pointer:coarse)]:after:-inset-y-[14px]` |
| `label` | Una etiqueta asociada activa su control al pulsarla: `[@media(any-pointer:coarse)]:inline-flex [@media(any-pointer:coarse)]:min-h-11 [@media(any-pointer:coarse)]:items-center` |
| `table` | Sin objetivo de contacto propio (las celdas no son controles); sin cambio |

`CA-CORE-084` (ampliado): comprueba en `button` que la altura mínima solo sube a 44 px cuando el test emula un puntero grueso, no en el caso por defecto (Playwright, `browser.newContext({ hasTouch: true|false })`).

### 12.2 Cómo añadir un componente (sección permanente de este documento)

1. `npx shadcn-vue@latest add <componente>` desde `apps/web` (respeta `components.json`).
2. Ejecutar `npm run test`: los tests de §10 señalan cualquier uso prohibido que traiga el código generado; se corrige en el fichero vendorizado (es código del repositorio, `ADR-052 §3.3`).
3. Si trae texto propio (`sr-only`, `aria-label` literal), convertirlo en *prop* o *slot* (`RN-DS-24`); `npm run lint:i18n` lo detecta (§14).
4. Si necesita un semántico nuevo, se añade a `tokens.css` en ambos modos, a `@theme inline`, a la tabla de §4.3 y, si es un par de texto, a §11.
5. Añadirlo a la tabla de §12.1.

### 12.3 Componentes añadidos en 1.8: `sheet` y `dropdown-menu`

Primeros componentes nuevos desde el catálogo cerrado de 1.7 (§12.1: «los candidatos obvios… los pedirá 1.8 o 1.9 cuando tengan consumidor»). El *shell* de `docs/modulos/REQ-CORE/funcional.md §12.7` los necesita: `sheet` para el panel de navegación en móvil/tableta (*drawer*/hamburguesa, `RN-CORE-30`), `dropdown-menu` para el menú de usuario (`AppUserMenu.vue`).

Vendorizados **sin la CLI de `shadcn-vue`**: el `npx shadcn-vue@latest add` de §12.2 punto 1 falla en este entorno de desarrollo (`npm error code EALLOWSCRIPTS`, política de `allowScripts` del sandbox sobre paquetes ya instalados). Se descargó el JSON del registro (`https://shadcn-vue.com/r/styles/reka-nova/<componente>.json`, mismo contenido que serviría la CLI) y se escribieron los ficheros a mano en `src/components/ui/{sheet,dropdown-menu}/`. Queda anotado como hallazgo de infraestructura de desarrollo, no de este *design system*; el paso 2 de §12.2 (ejecutar `npm run test` y corregir lo que señalen los tests de §10) se hizo igual.

Adaptaciones sobre el código del registro:

| Componente | Adaptación |
|------------|------------|
| `sheet` (`SheetContent.vue`) | El botón de cierre solo-icono traía `sr-only>Close</sr-only>` como literal (inglés, y contra `RN-DS-24`): se convirtió en la *prop* obligatoria `closeLabel: string`, que quien use el componente traduce (`AppShellLayout.vue` pasa `t('shell.sheetClose')`) |
| `sheet` (`SheetOverlay.vue`) | Traía `bg-black/10` literal (`RN-DS-19`): sustituido por el token nuevo `bg-overlay` (§4.9) |
| `dropdown-menu` | Sin hallazgos de §10 (revisado: sin color literal, sin texto propio) |

Ninguno de los dos declara `size`/altura fija en sus elementos de fila (`sheet`: el panel entero; `dropdown-menu`: `py-1` ≈ 32 px por ítem) — los usos del *shell* añaden `min-h-11` por instancia donde corresponde (`RN-CORE-32`), en vez de tocar la altura por defecto del componente base: a diferencia de §12.1b, aquí no es «todo el producto en punteros gruesos», es «todo control del *shell*, siempre» (`funcional.md §12.7`).

---

## 13. Migración de `PublicAuthShell` y `usePublicAuthScreen`

### 13.1 `PublicAuthShell.vue`

- Se retiran `brandVars` y el `:style="brandVars"` de la tarjeta: el tema es global y la tarjeta hereda (`ADR-052 §1`).
- Deja de recibir `branding` por *prop*: lee `branding` de `useTenantBranding()`. Las vistas que hoy le pasan `:branding` dejan de hacerlo.
- Conserva nombre, logo y fondo de login con el mismo marcado y las mismas clases. El `<img>` del logo y el fondo llaman a `reportAssetError` si fallan (§7.4; el fondo es CSS, así que solo el logo tiene evento `error`: el fondo queda sin recuperación automática en 1.7 y se acepta).
- Se actualiza su comentario de cabecera: la afirmación «sin recalcular contraste en el cliente (`CA-AUTH-062`)» deja de ser cierta para los usos sobre el fondo.

### 13.2 `usePublicAuthScreen.ts`

- Deja de importar y llamar a `getTenantBranding()` (`RN-DS-22`).
- Sigue sembrando la cookie CSRF en `onMounted`, exactamente como hoy.
- Sigue resolviendo el idioma (`resolveTenantLocale`, sin cambios) y llamando a `setLocale(...)`: en `onMounted` si `branding` ya está disponible; si no, en cuanto lo esté (vigilancia que se desactiva tras la primera aplicación). Resultado observable idéntico al actual (`CA-AUTH-063`).
- Devuelve la misma forma `{ branding, brandingFailed }`, ahora de solo lectura: `branding` es el de la capa B; `brandingFailed` es `true` cuando `status` es `not-found` o `unavailable`.

### 13.3 Cambio visible previsto (el único)

Los usos de `text-primary` sobre la tarjeta (p. ej. el enlace de recuperación de `LoginView`) pasan a `text-primary-on-background`. Con un color de centro que ya contrasta con las superficies, **no cambia nada**. Con uno que no (el caso que hoy incumple `RNF-UX-002`), el enlace se ve del mismo tono, más oscuro o más claro. Es exactamente la corrección que motiva `ADR-052 §3.3`.

Los tests existentes de `REQ-AUTH` (`CA-AUTH-061`/`062`/`063` y los de cada vista) siguen en verde; los que hoy comprueben `--primary` en el `style` de la tarjeta se reescriben para comprobar que la capa A recibió el par (`CA-DS-045`).

---

## 14. Traducción (`INV-009`)

- **1.7 no añade ninguna clave de traducción.** Los componentes base no tienen literales (`RN-DS-24`); la capa A, la derivación, la capa B y el modo de color no pintan texto. Los únicos mensajes nuevos posibles serían de consola para desarrolladores, que no son texto visible.
- **`scripts/check-i18n-literals.mjs` deja de excluir `src/components/ui`** y pasa a cubrir también `src/design-system`. La exclusión se justificaba en `docs/i18n.md` porque eran «componentes de shadcn-vue, no contenido del centro»; con `ADR-052 §5` («un componente base no tiene literales propios») la regla ya se cumple por construcción, y el *script* pasa a demostrarlo en vez de suponerlo. Si aparece algún literal en los ocho, se convierte en *prop*/*slot*.
- `docs/i18n.md` se actualiza: exclusión retirada, y el selector de idioma sale de «1.7/1.8» hacia 1.8 (§9.5).

---

## 15. `components.json` (issue [#251](https://github.com/pirexia/plataforma-educativa/issues/251))

**Se resuelve en 1.7**, como fija `ADR-052 §3.2` («incoherencia menor a corregir en 1.7»), aunque 1.7 no añada componentes. Procedimiento:

1. El implementador consulta el esquema de `components.json` de la versión instalada de `shadcn-vue` (`^2.8.2`, Context7 o `https://shadcn-vue.com/schema.json`).
2. Si `font` es opcional, se **elimina** la clave. Si admite un valor que signifique «sin fuente web», se usa ese.
3. Si es obligatoria y todos sus valores son fuentes web, se deja como está, se documenta en §4.7 que el campo solo afecta al *scaffolding* de la CLI y no a la aplicación (la tipografía la fija `--font-sans`), y se cierra #251 con esa explicación.
4. En los tres casos: comprobar que `npx shadcn-vue@latest add --help` sigue funcionando con el fichero resultante, y referenciar `#251` en el *commit*.

---

## 16. Seguridad y operación

- **CSP**: todo el tema se aplica por CSSOM (`style.setProperty`, y los `:style` de Vue, que también lo son); ningún `<style>` generado (`RN-DS-04`, `RN-DS-14`); ningún `<script>` en línea en `index.html` (§9.2). Compatible con `style-src` sin `'unsafe-inline'`.
- **Datos en `localStorage`**: dos colores públicos y una preferencia de modo. Ninguna credencial, ninguna URL firmada, ningún dato personal (`ADR-025` no se ve afectado).
- **Carga sobre la API**: una petición anónima a `GET /tenant/branding` por carga de la SPA (antes, una por pantalla pública montada): igual o menor. El endpoint ya está limitado por IP (`429`), que la capa B trata como `unavailable`.
- Sin variables de entorno, colas, tareas programadas ni migraciones.

---

## 17. Documentación a actualizar al cerrar 1.7

- Este documento: tabla de §4.3 con los valores finales (si §11 obligó a ajustar alguno) y §12.1 con el estado final.
- `docs/i18n.md` (§14).
- `ARCHITECTURE.md`: sección de frontend con los niveles de tokens, las dos capas y la frontera del *design system*.
- `SECURITY.md`: nota de CSP de §16.
- `CHANGELOG.md`: entrada del paso.
- `docs/manual-usuario/*`: una línea sobre el modo oscuro, que sigue la preferencia del sistema operativo (hasta que 1.8 añada el control).

---

## 18. Criterios de aceptación

Todos con test Vitest salvo los marcados **[Playwright]**, que necesitan cálculo real de estilos (jsdom no aplica hojas ni *media queries*). Cada test cita su `CA-DS-NNN`.

### Tokens

- **`CA-DS-001`** [`RUX-002`] · **Dado** `tokens.css`, **cuando** se analiza, **entonces** cada semántico de §4.3 está definido en `:root` y en `.dark` con el valor de la tabla, y `style.css` no define ningún semántico de color (solo importa `tokens.css` y mapea).
- **`CA-DS-002`** [`RUX-002`] · **Dado** `style.css`, **cuando** se analiza `@theme inline`, **entonces** cada semántico de color de §4.3 tiene su `--color-<nombre>: var(--<nombre>)`, incluidos `primary-on-background`, `success`, `warning`, `info` y sus `-foreground`, y `--default-transition-duration: var(--motion-duration-normal)`.
- **`CA-DS-003`** [`RUX-BRAND-002`] · **Dado** `tokens.css`, **cuando** se analiza, **entonces** los semánticos de `RN-DS-02` tienen la forma `var(--brand-…, <neutro>)` (o `var(--primary-on-background)`/`var(--ring)`), `--primary-foreground` usa `--brand-primary-foreground`, y ningún otro semántico referencia `--brand-`.
- **`CA-DS-004`** [`RUX-004`, `RNF-UX-002`, `RNF-UX-004`] · **Dado** los pares de §11, **cuando** se calcula su contraste en modo claro y oscuro, **entonces** todos alcanzan su umbral.
- **`CA-DS-005`** [`RUX-004`] · **Dado** una copia en memoria de `tokens.css` con `--muted-foreground: oklch(0.7 0 0)` en `:root`, **cuando** se le aplica la comprobación de `CA-DS-004`, **entonces** informa del par `muted-foreground`/`muted` como incumplido.
- **`CA-DS-006`** [`RUX-002`] · **Dado** una copia en memoria de `tokens.css` con un semántico en formato no admitido (p. ej. `hsl(0 0% 50%)`), **cuando** se analiza, **entonces** el test falla nombrando el semántico.
- **`CA-DS-007`** [`RNF-UX-004`] · **Dado** `tokens.css`, **entonces** `:root` declara `color-scheme: light`, `.dark` declara `color-scheme: dark`, y el bloque `@media (prefers-color-scheme: dark)` de §4.5 usa el selector `:root:not(.light):not(.dark)` y declara `color-scheme: dark` y los mismos `--background`/`--foreground` que `.dark`.
- **`CA-DS-008`** [`RUX-005`] · **Dado** `tokens.css`, **entonces** contiene el bloque `prefers-reduced-motion: reduce` de §4.6 con los tres `--motion-duration-*` a `0ms` y las cuatro declaraciones universales.
- **`CA-DS-009`** [`RUX-005`] **[Playwright]** · **Dado** la pantalla `/entrar`, **cuando** el navegador emula `reducedMotion: 'reduce'`, **entonces** la `transition-duration` calculada del botón de envío es ≤ `0.01ms`; **y cuando** no la emula, es `150ms`.
- **`CA-DS-010`** [`RUX-002`] · **Dado** `src/`, **entonces** no hay `@font-face`, ni `@import` de fuentes externas, y `--font-sans` es la pila de §4.7.

### Derivación

- **`CA-DS-011`** [`RUX-BRAND-006`] · **Dado** `deriveOnBackground`, **cuando** recibe `'1D4ED8'`, `'#12345'` o `'#GGGGGG'`, **entonces** lanza `TypeError`; **y cuando** recibe `'#1d4ed8'`, devuelve lo mismo que para `'#1D4ED8'`, en mayúsculas.
- **`CA-DS-012`** [`RUX-BRAND-006`] · **Dado** los casos «sin cambios» de §6.4, **cuando** se derivan, **entonces** el resultado es la entrada normalizada.
- **`CA-DS-013`** [`RUX-BRAND-006`, `RNF-UX-002`, `RNF-UX-004`] · **Dado** los casos «ajustado» de §6.4, **cuando** se derivan, **entonces** el resultado alcanza ≥ 4,5:1 contra cada superficie del modo (§6.2), es distinto de la entrada y, si su croma es ≥ 0,02, su tono difiere ≤ 2° del de la entrada.
- **`CA-DS-014`** [`RNF-UX-002`] · **Dado** el barrido de 216 colores de §6.4, **cuando** se derivan en ambos modos, **entonces** todos alcanzan ≥ 4,5:1 contra cada superficie del modo, y la `L` OKLCH del resultado es ≤ la de la entrada en claro y ≥ en oscuro.
- **`CA-DS-015`** [`RUX-BRAND-002`] · **Dado** cualquier caso del barrido que resultó ajustado, **cuando** se acerca su `L` 0,01 hacia la de la entrada (mismo `C` y `h`, con el mapeo de gama de §6.3), **entonces** ese color ya no alcanza 4,5:1 contra alguna superficie (el ajuste es mínimo, no un oscurecimiento arbitrario).
- **`CA-DS-016`** · **Dado** cualquier color del barrido, **entonces** `deriveOnBackground(deriveOnBackground(x, m), m) === deriveOnBackground(x, m)`.
- **`CA-DS-017`** [`RUX-004`] · **Dado** `surfaces.ts` y `tokens.css`, **entonces** las superficies de cada modo coinciden valor a valor con los semánticos de §6.2 en la hoja.
- **`CA-DS-018`** · **Dado** el *spec* de la derivación ejecutado con `@vitest-environment node`, **entonces** pasa (la función no toca `window` ni `document`).

### Capa A

- **`CA-DS-019`** [`RUX-BRAND-002`] · **Dado** un elemento, **cuando** se llama `applyBrandPalette({ primary: '#1d4ed8', primaryForeground: '#ffffff' }, el)`, **entonces** su `style` tiene exactamente las cuatro propiedades de §4.2, con `--brand-primary: #1D4ED8`, `--brand-primary-foreground: #FFFFFF` y las dos derivadas iguales a `deriveOnBackground('#1D4ED8', 'light'|'dark')`.
- **`CA-DS-020`** · **Dado** un elemento con la paleta aplicada, **cuando** se llama `applyBrandPalette(null, el)`, **entonces** no queda ninguna propiedad `--brand-*`.
- **`CA-DS-021`** · **Dado** un elemento con la paleta aplicada, **cuando** se llama con `{ primary: '#1D4ED8', primaryForeground: 'blanco' }`, **entonces** no queda ninguna propiedad `--brand-*` (todo o nada).
- **`CA-DS-022`** · **Dado** un documento, **cuando** se aplica y retira la paleta, **entonces** el número de elementos `<style>` del documento no cambia y no se escribe ninguna propiedad que no empiece por `--brand-`.

### Capa B

- **`CA-DS-023`** [`RUX-BRAND-002`] · **Dado** `getTenantBranding` simulado que no responde, **cuando** se llama `bootstrapTenantBranding()`, **entonces** la promesa resuelve a los 1000 ms (temporizadores simulados) con `status = 'loading'`; **y cuando** después responde con colores, `branding` se actualiza, `status` pasa a `ready` y la capa A recibe el par. `getTenantBranding` se llamó **una** vez.
- **`CA-DS-024`** [`RUX-BRAND-002`] · **Dado** cada fila de la tabla de §7.2, **cuando** llega esa respuesta, **entonces** `branding`, `status`, la paleta aplicada, la caché y el *favicon* quedan como indica la fila; y la caché, cuando existe, contiene exactamente las claves `v`, `primary`, `primaryForeground`.
- **`CA-DS-025`** · **Dado** `plataforma.brand` con un JSON válido, **cuando** se llama `primeBrandingFromCache()`, **entonces** la paleta se aplica de forma síncrona (antes de cualquier `await`); **y dado** un valor malformado, con `v: 2` o con un color inválido, **entonces** la clave se borra y no se aplica nada; **y dado** un `localStorage` que lanza al leer, **entonces** no lanza.
- **`CA-DS-026`** [`RUX-BRAND-003`] · **Dado** un documento con `<link rel="icon" type="image/svg+xml" href="/favicon.svg">`, **cuando** llega `favicon_url` no nulo, **entonces** `href` es esa URL y no hay atributo `type`; **y cuando** después llega `null`, se restauran `href` y `type` originales.
- **`CA-DS-027`** · **Dado** el contexto en `ready`, **cuando** se llama `refresh()` tres veces sin esperar, **entonces** hay una sola petición; **y cuando** esa petición falla, la promesa resuelve (no rechaza), `status` sigue en `ready` y `branding` no cambia.
- **`CA-DS-028`** · **Dado** el contexto en `ready`, **cuando** se llama `reportAssetError(url)` dos veces con la misma URL, **entonces** se lanza un solo `refresh()`.
- **`CA-DS-029`** · **Dado** `src/`, **entonces** el identificador `getTenantBranding` solo aparece en `src/modules/core/api/**` y `src/tenant/**` (`RN-DS-22`).

### Arranque

- **`CA-DS-030`** · **Dado** `main.ts`, **entonces** llama, en este orden, a `initColorScheme()`, `primeBrandingFromCache()` y `bootstrapTenantBranding()`, y monta la aplicación en la continuación de esta última (test con los tres simulados que registra el orden de llamada y comprueba que `mount` no se ejecuta antes de que resuelva el *bootstrap*).

### Modo oscuro

- **`CA-DS-031`** [`RNF-UX-004`] · **Dado** `localStorage` vacío y `matchMedia('(prefers-color-scheme: dark)')` simulado a `true`, **cuando** se llama `initColorScheme()`, **entonces** `<html>` tiene la clase `dark` y no `light`, `preference = 'system'` y `resolved = 'dark'`; y con `false`, la clase `light` y no `dark`.
- **`CA-DS-032`** [`RNF-UX-004`] · **Dado** el modo inicializado, **cuando** se llama `setPreference('dark')`, **entonces** `<html>` tiene `dark`, `plataforma.color-mode` guarda la preferencia, y una nueva inicialización (módulo recargado) la conserva.
- **`CA-DS-033`** · **Dado** `plataforma.color-mode = 'sepia'`, **cuando** se inicializa, **entonces** `preference = 'system'`.
- **`CA-DS-034`** [`RNF-UX-004`] · **Dado** `preference = 'system'` y el sistema en claro, **cuando** la *media query* simulada emite cambio a oscuro, **entonces** `<html>` pasa a `dark` sin llamar a `setPreference`.
- **`CA-DS-035`** · **Dado** el modo inicializado, **cuando** se cambia la preferencia dos veces, **entonces** no se ha añadido ningún elemento `<style>` al documento (`RN-DS-14`) y las propiedades `--brand-*` de `<html>` no han cambiado (`RN-DS-15`).
- **`CA-DS-036`** [`RNF-UX-004`, `RNF-UX-002`] **[Playwright]** · **Dado** un centro de desarrollo con `color_primary = #1D4ED8`, **cuando** se abre `/entrar` en modo claro y luego en oscuro (emulando `colorScheme`), **entonces** el color calculado del enlace de recuperación es `deriveOnBackground('#1D4ED8', 'light')` en claro y la variante oscura en oscuro, y el del botón de envío es `#1D4ED8` en ambos.

### Reglas de arquitectura

- **`CA-DS-037`** [`RNF-UX-002`] · **Dado** `src/` según §10.1, **entonces** no hay ninguna aparición de las utilidades de `RN-DS-16`; **y dado** los casos fijos del test (`'hover:text-primary'`, `'data-checked:border-primary'`, `'ring-primary/50'`, `'border-x-primary'`, `'text-sidebar-primary'`), se detectan; y `'text-primary-foreground'`, `'text-primary-on-background'`, `'border-primary-on-background'` no.
- **`CA-DS-038`** [`RNF-UX-002`] · **Dado** `src/`, **entonces** no hay `bg-primary/NN` ni `bg-sidebar-primary/NN` (`RN-DS-17`), y toda cadena de clases con `bg-primary` contiene `text-primary-foreground` con el mismo prefijo de variante (`RN-DS-18`); con casos fijos que lo prueban en ambos sentidos.
- **`CA-DS-039`** [`RUX-002`] · **Dado** `src/` según §10.1 salvo las excepciones de §10.3, **entonces** no hay colores literales; **y dado** los casos fijos (`'#1d4ed8'`, `'rgb(0 0 0)'`, `'oklch(0.5 0.1 200)'`, `'bg-[#fff]'`, `'text-blue-600'`, `'bg-white'`), se detectan; y `'bg-transparent'`, `'text-current'`, `'#251'` dentro de un comentario, `'#app'` no.
- **`CA-DS-040`** [`RNF-MANT-007`] · **Dado** `src/`, **entonces** `useColorMode`, `usePreferredDark` y `usePreferredColorScheme` solo se importan en `useColorScheme.ts`.
- **`CA-DS-041`** · **Dado** `src/`, **entonces** la cadena `--brand-` solo aparece en `src/design-system/theme/**` y `tokens.css`.
- **`CA-DS-042`** [`RUX-002`] · **Dado** `src/design-system/**` y `src/components/ui/**`, **entonces** ninguno importa lo prohibido en §10.4; **y dado** los casos fijos (`import x from '@/modules/core/api'`, `import { useT } from '@/i18n'`, `import { useRoute } from 'vue-router'`, `import y from '../../tenant/useTenantBranding'` desde un fichero de `design-system/theme/`), se detectan.
- **`CA-DS-043`** [`RUX-004`] · **Dado** `button`, `badge`, `input`, `textarea`, `select-trigger` y `radio-group-item`, **entonces** su cadena de clases contiene `focus-visible:border-ring` (`RN-DS-23`).

### Componentes y migración

- **`CA-DS-044`** [`RUX-004`, `RNF-UX-002`] · **Dado** `RadioGroupItem` montado con `checked`, **entonces** su clase contiene `data-checked:border-primary-on-background`; **dado** `Button` con `variant="link"`, contiene `text-primary-on-background`; **dado** `Button` y `Badge` con la variante por defecto, contienen `border-primary-on-background` y ninguna `bg-primary/`.
- **`CA-DS-045`** [`RUX-BRAND-002`, `RUX-BRAND-004`] · **Dado** el contexto de la capa B con un *branding* simulado, **cuando** se monta `PublicAuthShell`, **entonces** pinta nombre, logo y fondo del contexto, su tarjeta **no** tiene `--primary` ni `--primary-foreground` en `style`, y el componente no declara la *prop* `branding`.
- **`CA-DS-046`** [`CA-AUTH-063`] · **Dado** `usePublicAuthScreen` con el contexto en `loading`, **cuando** el contexto pasa a `ready` con `default_locale = 'fr'`, `active_locales = ['es-ES','fr']` y `navigator.languages = ['de-DE']`, **entonces** `setLocale('fr')` se llama una vez; **y** `getTenantBranding` no se llama desde el *composable*; **y** con `status = 'not-found'` o `'unavailable'`, `brandingFailed` es `true`.
- **`CA-DS-047`** · **Dado** la suite completa de `apps/web`, **cuando** se ejecuta tras la migración, **entonces** todos los tests preexistentes de `REQ-AUTH` pasan (con la única reescritura permitida de §13.3).

### Traducción y configuración

- **`CA-DS-048`** [`INV-009`] · **Dado** `scripts/check-i18n-literals.mjs` sin la exclusión de `components/ui` y cubriendo `src/design-system`, **cuando** se ejecuta `npm run lint:i18n`, **entonces** termina sin hallazgos; y los cuatro `locales/*.json` no ganan claves en 1.7.
- **`CA-DS-049`** · **Dado** `components.json`, **entonces** está en uno de los tres estados de §15 y el *commit* referencia `#251`.

### Resueltos por la respuesta a las preguntas abiertas (§20)

- **`CA-DS-050`** [`RUX-004`] (activo: `OPEN-DS-02` resuelta «sí») · **Dado** los pares de §11, **entonces** `input` sobre `background` alcanza 3:1 en ambos modos, e `Input`/`Textarea` usan `border-input`.
- `RN-DS-06` y `CA-DS-013`/`014`/`015`/`017` usan la lista completa de superficies de §6.2 (`OPEN-DS-01` resuelta: opción A).

---

## 19. Definición de terminado de 1.7

Además de `CLAUDE.md §10`: `CA-DS-001`-`050` en verde (las 50, §20 resuelto sin descartar ninguno), `lint`, `lint:i18n`, `vue-tsc`+`build`, Vitest y Playwright en verde; comprobación manual en navegador real (no solo `curl`, lección de 1.2) de `/entrar` en ambos modos con y sin marca; documentación de §17 actualizada; revisión independiente de `doc-reviewer` y `security-reviewer` (CSP y `localStorage`).

---

## 20. Preguntas abiertas para el usuario — RESUELTAS (2026-09-22)

Ninguna reabre `ADR-052`. Las tres nacen del detalle operable y cambian lo que ve un centro, así que no las decidía la especificación. Las tres se resuelven con la opción recomendada; el resto de este documento ya está editado en consecuencia.

### `OPEN-DS-01` · ¿Contra qué superficies se garantiza `--primary-on-background`? → **A: todas las superficies neutras del modo**

`ADR-052 §3.3` dice «contra el fondo del modo correspondiente». Leído al pie de la letra (solo `--background`), el enlace sobre una tarjeta o sobre `bg-muted` puede quedar por debajo de 4,5:1: en modo oscuro las tarjetas (`0.205`) y `muted` (`0.269`) son **más claras** que el fondo (`0.145`), y `#808080` da ≈ 5:1 contra el fondo pero ≈ 3,8:1 contra `muted`. El enlace de recuperación del login ya está sobre una tarjeta.

- **A · Todas las superficies neutras del modo** (§6.2) — **recomendada**. Garantiza `RNF-UX-002` donde de verdad se pinta el color. Coste: más colores de centro se ajustan (ligeramente) respecto a su valor exacto.
- **B · Solo `--background`**. Literalidad del ADR, mayor fidelidad al color del centro, y un incumplimiento AA conocido en tarjetas y superficies `muted`.

### `OPEN-DS-02` · ¿Los bordes de los campos de formulario deben cumplir 3:1 (WCAG 1.4.11)? → **Sí**

Hoy `--input`/`--border` claro (`0.922`) sobre blanco da ≈ 1,3:1, y en oscuro ≈ 1,5:1. Es el valor por defecto de shadcn-vue. Que 1.4.11 obligue o no depende de si el borde es lo único que identifica el campo: en `Input`/`Textarea`/`SelectTrigger` sobre fondo blanco, lo es. `RNF-UX-002` exige AA y, para centros públicos, lo exige la Ley 11/2023.

- **Sí** — **recomendada**. `--input` pasa a un gris que alcance 3:1 en ambos modos (del orden de `L ≤ 0.66` en claro; en oscuro, opacidad del blanco ≈ 34 % o un gris opaco equivalente), `Input`/`Textarea` pasan de `border-border` a `border-input`, y se revisan los usos de `dark:bg-input/30` como relleno. Cambio visible: bordes de campo notablemente más marcados en todo el producto. Activa `CA-DS-050`.
- **No**. Se mantiene el aspecto actual y se asume el riesgo en una auditoría de accesibilidad.

### `OPEN-DS-03` · ¿El control visible de modo de color entra en 1.7 o en 1.8? → **1.8**

- **1.8** — **recomendada**. Necesita un sitio en el *layout* y claves de traducción del *layout*. Con 1.7 el modo oscuro ya funciona siguiendo el sistema operativo, que es el caso mayoritario. `RNF-UX-004` queda cubierto en su mecanismo en 1.7 y en su control en 1.8.
- **1.7**. Obligaría a decidir ahora dónde vive el control en las pantallas públicas (sin *layout*) y adelantaría claves de i18n de 1.8.

---

## 21. Hallazgos fuera del ámbito de este documento

No se corrigen aquí; se reportan para quien corresponda:

1. ~~`REQ-CORE/api.md`, ejemplo de `GET /api/v1/tenant/branding` con el par `#1D4ED8`/`#475569`~~ — **resuelto**: issue [#248](https://github.com/pirexia/plataforma-educativa/issues/248), corregido y mezclado a `develop` (PR #249) antes de escribir este documento. Los tres ejemplos de `api.md` usan `#FFFFFF`.
2. **`CLAUDE.md §6`** no incluye `docs/i18n.md` ni `docs/design-system.md` en la estructura obligatoria (`ADR-052 §Hallazgos 3`, sigue vigente).
3. **`docs/i18n.md`** sitúa el selector de idioma en «1.7/1.8»; se corrige al cerrar 1.7 (§14), no aquí.
