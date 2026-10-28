/**
 * `docs/adr/ADR-053-registro-de-navegacion-y-bloques-del-panel.md` §1, §3,
 * §4, §5. Tipos de nivel de aplicación: los módulos importan de aquí
 * **solo tipos** (`INV-007`); `src/navigation` no importa nada de ningún
 * módulo salvo su `shell.ts` (`src/navigation/modules.ts`).
 */
import type { Component } from 'vue'
import type { RouteRecordRaw } from 'vue-router'

/**
 * `docs/modulos/REQ-CORE/funcional.md §12.1.1` punto 1: tres regímenes de
 * *layout* por ruta.
 */
export type LayoutRegime = 'public' | 'app' | 'bare'

/**
 * `ADR-053 §3`. `layout` es obligatorio en toda ruta; `permissions` es
 * obligatorio (aunque sea `[]` explícito) en toda ruta `app`/`bare`, y no
 * se declara en rutas `public`.
 */
export interface AppRouteMeta {
  layout: LayoutRegime
  /** anyOf. Ausente solo en rutas `public`. */
  permissions?: readonly string[]
  /** Clave de traducción del título de la vista (`document.title`, encabezado). */
  titleKey?: string
  /** Clave de traducción de la miga de pan, si difiere de `titleKey`. */
  breadcrumbKey?: string
  /**
   * Nombre de la ruta «padre» en la miga de pan (`ADR-053 §4.4`): pantallas
   * secundarias (alta, edición, detalle) que no son entrada de menú, pero
   * cuelgan de la de su entrada. P. ej. `sso-administration-edit` declara
   * `breadcrumbParent: 'sso-administration'`.
   */
  breadcrumbParent?: string
  /**
   * `docs/modulos/REQ-ACAD/funcional.md §10.2` (aprobada): la ruta muestra el
   * selector de curso académico en la barra superior del *shell*. Amplía el
   * contenido de `meta`, no las tres listas de `ADR-053`.
   */
  academicYearScoped?: boolean
}

// Ampliación del módulo de vue-router: `meta` queda tipado en todo el
// proyecto con los campos de `ADR-053 §3`.
declare module 'vue-router' {
  // La fusión de declaraciones de TypeScript para ampliar un módulo de
  // terceros exige `interface`, no `type`: no hay forma de añadir campos
  // sin un cuerpo, aunque esté vacío.
  // eslint-disable-next-line @typescript-eslint/no-empty-object-type
  interface RouteMeta extends AppRouteMeta {}
}

/**
 * `ADR-053 §4.2`. Catálogo cerrado de aplicación (`src/navigation/sections.ts`),
 * no una sección por módulo.
 */
export type SectionId = 'inicio' | 'cuenta' | 'administracion'

/** `ADR-053 §4.1`. La entrada no declara `permissions`: se derivan de la ruta (§3). */
export interface NavigationEntry {
  /** `<módulo>.<nombre>`, estable, único en todo el registro. */
  id: string
  /** Nombre de ruta (nunca una URL) de una ruta `app`. */
  route: string
  /** Clave de traducción, en el espacio de nombres del módulo (`INV-009`). */
  labelKey: string
  /** Componente de `@lucide/vue`, decorativo (`aria-hidden`). */
  icon: Component
  section: SectionId
  /** Si aparece en «Accesos directos» del panel. `false` por defecto. */
  shortcut?: boolean
}

/** `ADR-053 §5.1`. `permissions` es anyOf y **no puede estar vacía**. */
export interface DashboardBlock {
  id: string
  titleKey: string
  permissions: readonly string[]
  component: () => Promise<Component>
}

/**
 * `docs/adr/ADR-067-registro-de-muros-de-sesion.md §5.11`. Un muro de sesión que
 * el *shell* de un módulo declara: la API lo expone por su `code` en
 * `GET /me` (`session_walls`) y por el `type` de su `403`; la SPA solo decide
 * adónde llevar al usuario (la API es la que hace cumplir el muro, `INV-010`).
 */
export interface SessionWallEntry {
  /** Código estable del muro (`mfa-enrollment`, `terms-acceptance`), el de `GET /me`. */
  code: string
  /** Slug del `type` del `403` del muro, sin el prefijo `urn:pge:error:`. */
  errorType: string
  /** Nombre de la ruta `bare` de la pantalla del muro. */
  route: string
}

/**
 * `ADR-053 §1`. Cada módulo declara un único `shell.ts` en su superficie
 * pública, con tres listas, cualquiera de ellas vacía. `ADR-067 §5.11` añade
 * una cuarta, **opcional**: los muros de sesión del módulo.
 */
export interface ModuleShell {
  routes: readonly RouteRecordRaw[]
  navigation: readonly NavigationEntry[]
  dashboardBlocks: readonly DashboardBlock[]
  sessionWalls?: readonly SessionWallEntry[]
}
