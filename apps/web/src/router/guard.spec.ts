/**
 * `docs/modulos/REQ-CORE/funcional.md §12.11`
 * (`RN-CORE-35`/`CA-CORE-141`, `CA-CORE-096`, `CA-CORE-093`/`CA-CORE-094`).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { installNavigationGuard } from './guard'

const brandingStatus = vi.hoisted(() => ({ value: 'ready' as string }))
vi.mock('@/tenant/useTenantBranding', () => ({
  useTenantBranding: () => ({ status: brandingStatus }),
}))

const sessionStatus = vi.hoisted(() => ({ value: 'idle' as string }))
const sessionUser = vi.hoisted(() => ({ value: null as { session_walls?: string[] } | null }))
const activeWall = vi.hoisted(() => ({ value: null as string | null }))
const ensureSessionLoaded = vi.hoisted(() => vi.fn().mockResolvedValue(undefined))
const clearModuleUnavailableDetail = vi.hoisted(() => vi.fn())
vi.mock('@/session/useSession', () => ({
  useSession: () => ({ status: sessionStatus, user: sessionUser, activeWall }),
  ensureSessionLoaded: (...args: unknown[]) => ensureSessionLoaded(...args),
  clearModuleUnavailableDetail: (...args: unknown[]) => clearModuleUnavailableDetail(...args),
}))

function makeRouter() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/',
        name: 'home',
        component: { template: '<div/>' },
        meta: { layout: 'app', permissions: [] },
      },
      {
        path: '/entrar',
        name: 'login',
        component: { template: '<div/>' },
        meta: { layout: 'public' },
      },
      {
        path: '/cuenta/seguridad/obligatorio',
        name: 'mfa-enrollment-wall',
        component: { template: '<div/>' },
        meta: { layout: 'bare', permissions: [] },
      },
      {
        path: '/condiciones-de-uso',
        name: 'fam-unit-terms-wall',
        component: { template: '<div/>' },
        meta: { layout: 'bare', permissions: [] },
      },
      {
        path: '/centro-no-encontrado',
        name: 'tenant-not-found',
        component: { template: '<div/>' },
        meta: { layout: 'public' },
      },
      {
        path: '/:pathMatch(.*)*',
        name: 'not-found',
        component: { template: '<div/>' },
        meta: { layout: 'app', permissions: [] },
      },
    ],
  })

  installNavigationGuard(router)

  return router
}

beforeEach(() => {
  brandingStatus.value = 'ready'
  sessionStatus.value = 'idle'
  sessionUser.value = null
  activeWall.value = null
  ensureSessionLoaded.mockClear()
  clearModuleUnavailableDetail.mockClear()
})

afterEach(() => {
  vi.restoreAllMocks()
})

describe('RN-CORE-35 / CA-CORE-141: host sin tenant', () => {
  it('cualquier ruta pedida acaba en tenant-not-found sin llamar a GET /me', async () => {
    brandingStatus.value = 'not-found'

    const router = makeRouter()
    await router.push('/cuenta/seguridad/obligatorio')
    await router.isReady()

    expect(router.currentRoute.value.name).toBe('tenant-not-found')
    expect(ensureSessionLoaded).not.toHaveBeenCalled()
  })
})

describe('CA-CORE-094: rutas públicas no llaman a GET /me', () => {
  it('/entrar no pide sesión', async () => {
    const router = makeRouter()
    await router.push('/entrar')
    await router.isReady()

    expect(router.currentRoute.value.name).toBe('login')
    expect(ensureSessionLoaded).not.toHaveBeenCalled()
  })
})

describe('CA-CORE-096: catch-all intenta cargar sesión pero nunca redirige a /entrar', () => {
  it('con sesión anónima, se queda en not-found (no navega a login)', async () => {
    sessionStatus.value = 'anonymous'

    const router = makeRouter()
    await router.push('/algo/que/no/existe')
    await router.isReady()

    expect(router.currentRoute.value.name).toBe('not-found')
    expect(ensureSessionLoaded).toHaveBeenCalledTimes(1)
  })
})

describe('CA-CORE-093: el muro de MFA es la única ruta alcanzable', () => {
  it('con status wall-required (403 de /me), cualquier ruta app/bare redirige al muro', async () => {
    sessionStatus.value = 'wall-required'
    activeWall.value = 'mfa-enrollment'

    const router = makeRouter()
    await router.push('/')
    await router.isReady()

    expect(router.currentRoute.value.name).toBe('mfa-enrollment-wall')
  })

  it('ya en el muro, se queda (sin bucle)', async () => {
    sessionStatus.value = 'wall-required'
    activeWall.value = 'mfa-enrollment'

    const router = makeRouter()
    await router.push('/cuenta/seguridad/obligatorio')
    await router.isReady()

    expect(router.currentRoute.value.name).toBe('mfa-enrollment-wall')
  })
})

describe('CA-067-09 ADR-067 §5.11 (OPEN-067-02/-03): el guard lleva al primer muro de session_walls', () => {
  it('con session_walls [mfa-enrollment], un usuario ready aterriza en el muro de MFA (REQ-CORE §12.3.5)', async () => {
    sessionStatus.value = 'ready'
    sessionUser.value = { session_walls: ['mfa-enrollment'] }

    const router = makeRouter()
    await router.push('/')
    await router.isReady()

    expect(router.currentRoute.value.name).toBe('mfa-enrollment-wall')
  })

  it('con session_walls [terms-acceptance], cualquier ruta app/bare lleva al muro de condiciones', async () => {
    sessionStatus.value = 'ready'
    sessionUser.value = { session_walls: ['terms-acceptance'] }

    const router = makeRouter()
    await router.push('/')
    await router.isReady()

    expect(router.currentRoute.value.name).toBe('fam-unit-terms-wall')
  })

  it('con los dos pendientes manda el primero del registro (MFA), no el segundo', async () => {
    sessionStatus.value = 'ready'
    sessionUser.value = { session_walls: ['mfa-enrollment', 'terms-acceptance'] }

    const router = makeRouter()
    await router.push('/condiciones-de-uso')
    await router.isReady()

    expect(router.currentRoute.value.name).toBe('mfa-enrollment-wall')
  })

  it('ya en la ruta del muro, se queda (sin bucle)', async () => {
    sessionStatus.value = 'ready'
    sessionUser.value = { session_walls: ['terms-acceptance'] }

    const router = makeRouter()
    await router.push('/condiciones-de-uso')
    await router.isReady()

    expect(router.currentRoute.value.name).toBe('fam-unit-terms-wall')
  })

  it('sin muros pendientes (lista vacía o ausente) no redirige', async () => {
    sessionStatus.value = 'ready'
    sessionUser.value = { session_walls: [] }

    const router = makeRouter()
    await router.push('/')
    await router.isReady()
    expect(router.currentRoute.value.name).toBe('home')

    sessionUser.value = {}
    await router.push('/condiciones-de-uso')
    expect(router.currentRoute.value.name).toBe('fam-unit-terms-wall')
  })

  it('un código de muro que la SPA no conoce no bloquea la navegación (lo hace cumplir la API, INV-010)', async () => {
    sessionStatus.value = 'ready'
    sessionUser.value = { session_walls: ['muro-desconocido'] }

    const router = makeRouter()
    await router.push('/')
    await router.isReady()

    expect(router.currentRoute.value.name).toBe('home')
  })
})

describe('anónimo en una ruta app: redirige a /entrar con el destino', () => {
  it('CA-CORE-090', async () => {
    sessionStatus.value = 'anonymous'

    const router = makeRouter()
    await router.push('/')
    await router.isReady()

    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.redirect).toBe('/')
  })
})

describe('limpia moduleUnavailableDetail en cada navegación', () => {
  it('llama a clearModuleUnavailableDetail incluso en rutas públicas', async () => {
    const router = makeRouter()
    await router.push('/entrar')
    await router.isReady()

    expect(clearModuleUnavailableDetail).toHaveBeenCalled()
  })
})
