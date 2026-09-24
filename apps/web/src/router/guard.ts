/**
 * `docs/modulos/REQ-CORE/funcional.md §12.3.1`, `§12.3.5`, `RN-CORE-35`.
 * Un solo *guard* global. La comprobación de `meta.permissions` de la ruta
 * de destino **no** vive aquí (`§12.3.2` punto 3): es experiencia de
 * usuario, no seguridad (`INV-002`), y la resuelve el *layout* al pintar
 * — `sin acceso` dentro del *shell*, sin dejar de navegar — para que la
 * miga de pan y `document.title` de la ruta sigan actualizándose.
 */
import type { Router } from 'vue-router'
import { useTenantBranding } from '@/tenant/useTenantBranding'
import { clearModuleUnavailableDetail, ensureSessionLoaded, useSession } from '@/session/useSession'
import { buildRedirectQuery } from './redirect'

const TENANT_NOT_FOUND_ROUTE = 'tenant-not-found'
const NOT_FOUND_ROUTE = 'not-found'
const LOGIN_ROUTE = 'login'
const MFA_ENROLLMENT_WALL_ROUTE = 'mfa-enrollment-wall'

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

    const { status } = useSession()

    if (status.value === 'mfa-required') {
      return to.name === MFA_ENROLLMENT_WALL_ROUTE ? true : { name: MFA_ENROLLMENT_WALL_ROUTE }
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
