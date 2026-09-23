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
 * `ADR-053 §1`. Cada módulo declara un único `shell.ts` en su superficie
 * pública, con tres listas, cualquiera de ellas vacía.
 */
export interface ModuleShell {
  routes: readonly RouteRecordRaw[]
  navigation: readonly NavigationEntry[]
  dashboardBlocks: readonly DashboardBlock[]
}
