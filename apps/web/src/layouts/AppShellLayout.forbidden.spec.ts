/**
 * `docs/modulos/REQ-CORE/funcional.md §12.6`, `§12.3.4`; REQ-CORE-008,
 * `CA-CORE-270` (`CA-CORE-098`), `CA-CORE-271`, `CA-CORE-272`; issues #300,
 * #302, #303. Pila real (App, *router* con su *guard*, *shell*, sesión y
 * cliente HTTP); solo se simula `fetch`.
 *
 * Una vista dentro del *shell* que recibe un `403` persistente de un recurso
 * hace UNA petición al recurso, UNA recarga de `GET /me` y pinta «Sin acceso»
 * sin desmontarse: la recarga no debe pasar por `loading` con sesión `ready`.
 * Si la recarga revela otra sesión (401, otra identidad, otros permisos), la
 * vista no puede quedarse con los datos del usuario anterior.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, onMounted, shallowRef } from 'vue'

vi.setConfig({ testTimeout: 30000 })

// Tope de respuestas 403: evita que el test cuelgue si el bucle reaparece.
const FORBIDDEN_CAP = 6

let fetchLog: string[] = []
let resourceHits = 0
let meHits = 0
let mounts = 0

function meBody(id = 'ME', permissions: string[] = []) {
  return {
    public_id: id,
    email: 'yo@example.com',
    status: 'activo',
    person: { given_name: 'Admin', family_name_1: 'Prueba', locale: 'es-ES' },
    roles: [],
    permissions,
    email_verified_at: null,
    created_at: '2026-09-01T10:00:00Z',
    updated_at: '2026-09-01T10:00:00Z',
    deleted_at: null,
  }
}

function json(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

let meHandler: (n: number) => Response = () => json(200, meBody())

beforeEach(() => {
  vi.resetModules()
  fetchLog = []
  resourceHits = 0
  meHits = 0
  mounts = 0
  meHandler = () => json(200, meBody())

  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL) => {
      const path = new URL(String(input)).pathname.replace(/^\/api\/v1/, '')

      fetchLog.push(path)

      if (path === '/me') {
        meHits += 1

        return Promise.resolve(meHandler(meHits))
      }

      if (path === '/recurso-prohibido') {
        resourceHits += 1

        return Promise.resolve(
          resourceHits > FORBIDDEN_CAP ? json(404, { status: 404 }) : json(403, { status: 403 }),
        )
      }

      return Promise.resolve(json(404, { status: 404 }))
    }),
  )

  Object.defineProperty(window, 'matchMedia', {
    configurable: true,
    writable: true,
    value: (query: string) => ({
      matches: query.includes('min-width'),
      media: query,
      addEventListener: () => undefined,
      removeEventListener: () => undefined,
    }),
  })
})

afterEach(() => {
  document.body.innerHTML = ''
  vi.unstubAllGlobals()
})

async function boot(permissions: string[] = []) {
  const { flushPromises, mount } = await import('@vue/test-utils')
  const { default: router } = await import('@/router')
  const { default: App } = await import('@/App.vue')
  const { i18n, setLocale } = await import('@/i18n')
  const { apiFetch } = await import('@/api/client')
  const { default: ErrorState } = await import('@/layouts/components/ErrorState.vue')
  const { resolveErrorState } = await import('@/layouts/errorState')

  setLocale('es')

  const View = defineComponent({
    setup() {
      const state = shallowRef<ReturnType<typeof resolveErrorState>>(null)

      onMounted(async () => {
        mounts += 1

        try {
          await apiFetch('/recurso-prohibido')
        } catch (err) {
          state.value = resolveErrorState(err)
        }
      })

      return () => (state.value ? h(ErrorState, { state: state.value }) : h('p', 'cargando'))
    },
  })

  router.addRoute({
    path: '/prueba-403',
    name: 'prueba-403',
    component: View,
    meta: { layout: 'app', permissions, titleKey: 'shell.states.error.notFound.title' },
  })

  const wrapper = mount(App, { global: { plugins: [i18n, router] }, attachTo: document.body })

  await router.push('/prueba-403')
  await flushPromises()
  await new Promise((resolve) => setTimeout(resolve, 50))
  await flushPromises()

  return { router, wrapper }
}

function mainText(): string {
  return document.querySelector('main#main-content')?.textContent ?? ''
}

describe('CA-CORE-070 / CA-CORE-073 / CA-CORE-270 (REQ-CORE-008, #300): 403 persistente dentro del shell', () => {
  it('una sola petición al recurso, una sola recarga de /me y «Sin acceso» dentro del shell', async () => {
    const { wrapper } = await boot()

    expect(resourceHits).toBe(1)
    expect(meHits).toBe(2) // arranque + una recarga
    expect(mounts).toBe(1)
    expect(fetchLog.indexOf('/me', fetchLog.indexOf('/recurso-prohibido'))).toBeGreaterThan(-1)
    expect(document.querySelector('header')).not.toBeNull()
    expect(document.querySelector('main#main-content [role="alert"]')).not.toBeNull()
    expect(mainText()).toContain('Sin acceso')

    wrapper.unmount()
  })
})

describe('CA-CORE-271 (REQ-CORE-008, #302): sesión perdida en la recarga tras un 403', () => {
  it('ready, 403 en un recurso y /me responde 401: navega a login con redirect', async () => {
    meHandler = (n) => (n === 1 ? json(200, meBody()) : json(401, { status: 401 }))

    const { router, wrapper } = await boot()

    // La navegación a login carga su vista de forma asíncrona.
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'), { timeout: 5000 })
    expect(router.currentRoute.value.query.redirect).toBe('/prueba-403')
    expect(resourceHits).toBe(1)

    wrapper.unmount()
  })
})

describe('CA-CORE-272 (REQ-CORE-008, #303): identidad o permisos cambiados en la recarga tras un 403', () => {
  it('/me devuelve otro public_id: la vista se remonta', async () => {
    meHandler = (n) => json(200, meBody(n === 1 ? 'A' : 'B'))

    const { wrapper } = await boot()

    expect(mounts).toBeGreaterThanOrEqual(2)

    wrapper.unmount()
  })

  it('/me devuelve menos permisos y la ruta ya no está permitida: «Sin acceso»', async () => {
    meHandler = (n) => json(200, meBody('A', n === 1 ? ['x.leer'] : []))

    const { wrapper } = await boot(['x.leer'])

    expect(mainText()).toContain('Sin acceso')
    expect(document.querySelector('main#main-content [role="alert"]')).not.toBeNull()

    wrapper.unmount()
  })

  it('/me devuelve menos permisos pero conserva el de la ruta: la vista se remonta', async () => {
    meHandler = (n) => json(200, meBody('A', n === 1 ? ['x.leer', 'y.leer'] : ['x.leer']))

    const { wrapper } = await boot(['x.leer'])

    expect(mounts).toBeGreaterThanOrEqual(2)

    wrapper.unmount()
  })

  it('/me idéntico: no se remonta (sin bucle)', async () => {
    const { wrapper } = await boot()

    expect(mounts).toBe(1)
    expect(resourceHits).toBe(1)

    wrapper.unmount()
  })
})
