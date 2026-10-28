/**
 * `docs/modulos/REQ-CORE/funcional.md §12.3.1`, `§12.3.5`, `RN-CORE-35`,
 * `docs/adr/ADR-067-registro-de-muros-de-sesion.md §5.11`.
 * Un solo *guard* global. La comprobación de `meta.permissions` de la ruta
 * de destino **no** vive aquí (`§12.3.2` punto 3): es experiencia de
 * usuario, no seguridad (`INV-002`), y la resuelve el *layout* al pintar
 * — `sin acceso` dentro del *shell*, sin dejar de navegar — para que la
 * miga de pan y `document.title` de la ruta sigan actualizándose.
 */
import type { Router } from 'vue-router'
import { useTenantBranding } from '@/tenant/useTenantBranding'
import { clearModuleUnavailableDetail, ensureSessionLoaded, useSession } from '@/session/useSession'
import { findSessionWallByCode } from '@/navigation/registry'
import { buildRedirectQuery } from './redirect'

const TENANT_NOT_FOUND_ROUTE = 'tenant-not-found'
const NOT_FOUND_ROUTE = 'not-found'
const LOGIN_ROUTE = 'login'

export function installNavigationGuard(router: Router): void {
  router.beforeEach(async (to) => {
    // `ADR-053 §6`: el mensaje de «módulo no disponible» solo sobrevive
    // hasta la siguiente navegación — se limpia aquí, al principio de
    // cada una, sin importar el destino.
    clearModuleUnavailableDetail()

    const branding = useTenantBranding()

    // `RN-CORE-35`: host sin tenant, sin importar la ruta pedida — sin
    // *shell* y sin `GET /me`.
    if (branding.status.value === 'not-found') {
      return to.name === TENANT_NOT_FOUND_ROUTE ? true : { name: TENANT_NOT_FOUND_ROUTE }
    }

    if (to.name === TENANT_NOT_FOUND_ROUTE) {
      return true
    }

    // `CA-CORE-096`: la página no encontrada se pinta dentro del *shell*
    // si hay sesión y en régimen público si no — nunca redirige a
    // `/entrar`, así que se resuelve aparte del flujo `app`/`bare` normal.
    if (to.name === NOT_FOUND_ROUTE) {
      await ensureSessionLoaded()
      return true
    }

    if (to.meta.layout === 'public') {
      return true
    }

    // Regímenes `app`/`bare`: el *guard* pide `GET /me` una sola vez
    // (deduplicada dentro de `ensureSessionLoaded`).
    await ensureSessionLoaded()

    const { status, user, activeWall } = useSession()

    // `ADR-067 §5.11`: `GET /me` respondió el `403` de un muro registrado.
    if (status.value === 'wall-required') {
      const wall = activeWall.value === null ? undefined : findSessionWallByCode(activeWall.value)

      if (wall !== undefined) {
        return to.name === wall.route ? true : { name: wall.route }
      }
    }

    // `ADR-067 §5.11`, `REQ-CORE §12.3.5`: con `session_walls` no vacío en `GET /me` (los muros activos y
    // bloqueantes, en el orden del registro) solo se entra en la ruta del primero, **también para el muro de MFA**.
    // Sin bucle: no se vuelve a pedir `/me` al entrar en la ruta del muro. Un código que esta SPA no conoce no
    // bloquea la navegación (la API sigue haciendo cumplir el muro con `403`, `INV-010`).
    if (status.value === 'ready') {
      const walls = user.value?.session_walls ?? []
      const wall = walls.length > 0 ? findSessionWallByCode(walls[0]!) : undefined

      if (wall !== undefined) {
        return to.name === wall.route ? true : { name: wall.route }
      }
    }

    if (status.value === 'anonymous') {
      return { name: LOGIN_ROUTE, query: buildRedirectQuery(to.fullPath) }
    }

    // `ready` o `error`: se deja navegar. `ready` + `meta.permissions` sin
    // cumplir lo resuelve el *layout* (§12.3.2 punto 3). `error` lo pinta
    // el *layout* a pantalla completa con reintento (§12.3.1 punto 4).
    return true
  })
}
