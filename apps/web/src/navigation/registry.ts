/**
 * `docs/adr/ADR-053-registro-de-navegacion-y-bloques-del-panel.md` §2.
 * Funciones de ensamblado sobre `moduleShells`: el *router*, el registro
 * de navegación, el panel y la miga de pan las usan en vez de recorrer
 * `moduleShells` a mano.
 */
import type { Router } from 'vue-router'
import { moduleShells } from './modules'
import type { DashboardBlock, NavigationEntry } from './types'
import type { RouteRecordRaw } from 'vue-router'

export function allModuleRoutes(): readonly RouteRecordRaw[] {
  return moduleShells.flatMap((shell) => shell.routes)
}

export function allNavigationEntries(): readonly NavigationEntry[] {
  return moduleShells.flatMap((shell) => shell.navigation)
}

export function allDashboardBlocks(): readonly DashboardBlock[] {
  return moduleShells.flatMap((shell) => shell.dashboardBlocks)
}

export function findNavigationEntryForRoute(routeName: string): NavigationEntry | undefined {
  return allNavigationEntries().find((entry) => entry.route === routeName)
}

/**
 * `ADR-053 §3`: una entrada es visible si y solo si el *guard* dejaría
 * montar la ruta a la que apunta — se deriva de `meta.permissions`
 * (anyOf) de esa ruta, nunca de un campo propio de la entrada.
 *
 * `INV-002`/`RPERM-011`, denegar por defecto: `[]` explícito es
 * identidad (RN-CORE-24) y se admite; `undefined` (campo ausente, que no
 * debería ocurrir en una ruta `app`/`bare` — lo exige
 * `src/navigation/modules.spec.ts`) **deniega**, no abre.
 */
export function hasAnyPermission(
  required: readonly string[] | undefined,
  granted: readonly string[],
): boolean {
  if (required === undefined) {
    return false
  }

  if (required.length === 0) {
    return true
  }

  return required.some((permission) => granted.includes(permission))
}

export function isEntryVisible(
  entry: NavigationEntry,
  router: Router,
  permissions: readonly string[],
): boolean {
  const route = router.getRoutes().find((candidate) => candidate.name === entry.route)

  if (!route) {
    return false
  }

  return hasAnyPermission(route.meta.permissions, permissions)
}

export function visibleNavigationEntries(
  router: Router,
  permissions: readonly string[],
): NavigationEntry[] {
  return allNavigationEntries().filter((entry) => isEntryVisible(entry, router, permissions))
}

export function visibleShortcuts(
  router: Router,
  permissions: readonly string[],
): NavigationEntry[] {
  return visibleNavigationEntries(router, permissions).filter((entry) => entry.shortcut === true)
}

export function visibleDashboardBlocks(permissions: readonly string[]): DashboardBlock[] {
  return allDashboardBlocks().filter((block) => hasAnyPermission(block.permissions, permissions))
}
