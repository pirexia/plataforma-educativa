/**
 * #427 — integración REAL de sesión + router + guard + `LoginView`
 * (`docs/modulos/REQ-CORE/funcional.md §12.3`, `RN-CORE-26`, `RN-CORE-28`,
 * `CA-CORE-090`; INV-015). Solo se simula la capa HTTP: `login` y
 * `getMe`. El estado de `useSession` es un singleton de módulo, así que
 * cada caso reimporta todo tras `vi.resetModules()`.
 *
 * Regresión: con el estado de sesión ya en `anonymous` (un `GET /me` previo
 * con 401, o `clearSession()` tras un logout), `ensureSessionLoaded()` no
 * repite la petición y el guard devolvía a `/entrar` tras un login 200.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter, RouterView } from 'vue-router'
import { defineComponent, h } from 'vue'

const login = vi.fn()
const getMe = vi.fn()

vi.mock('../api', () => ({
  login: (...args: unknown[]) => login(...args),
  verifyMfaChallenge: vi.fn(),
  getCsrfCookie: vi.fn().mockResolvedValue(undefined),
}))

vi.mock('@/modules/core/api', () => ({
  getMe: (...args: unknown[]) => getMe(...args),
  updateMe: vi.fn(),
  getTenantBranding: vi.fn().mockResolvedValue({
    name: 'Centro de ejemplo',
    color_primary: null,
    color_secondary: null,
    logo_url: null,
    favicon_url: null,
    login_background_url: null,
    default_locale: 'es-ES',
    active_locales: ['es-ES', 'en', 'de', 'fr'],
  }),
}))

const stub = { template: '<div/>' }

async function setup() {
  vi.resetModules()

  const { ApiError } = await import('@/api/client')
  const { i18n } = await import('@/i18n')
  const { installNavigationGuard } = await import('@/router/guard')
  const session = await import('@/session/useSession')
  const { default: LoginView } = await import('./LoginView.vue')

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: stub, meta: { layout: 'app', permissions: [] } },
      {
        path: '/cuenta/sesiones',
        name: 'sessions',
        component: stub,
        meta: { layout: 'app', permissions: [] },
      },
      { path: '/entrar', name: 'login', component: LoginView, meta: { layout: 'public' } },
      {
        path: '/cuenta/seguridad/obligatorio',
        name: 'mfa-enrollment-wall',
        component: stub,
        meta: { layout: 'bare', permissions: [] },
      },
      {
        path: '/:pathMatch(.*)*',
        name: 'not-found',
        component: stub,
        meta: { layout: 'app', permissions: [] },
      },
    ],
  })
  installNavigationGuard(router)

  // Ruta pública de partida: evita la navegación inicial implícita a `/` al
  // instalar el router, que consumiría el primer `GET /me` simulado.
  await router.push('/entrar')

  const App = defineComponent({ render: () => h(RouterView) })
  const wrapper = mount(App, {
    global: { plugins: [i18n, router], stubs: { IdentityProviderLoginList: true } },
  })

  return { ApiError, router, wrapper, session }
}

const USER = { id: 'u1', person: { locale: 'es-ES' } }

function problem(ApiError: new (...a: never[]) => Error, status: number, type?: string) {
  const Ctor = ApiError as unknown as new (m: string, s: number, b: unknown) => Error
  return new Ctor('x', status, type ? { type } : {})
}

async function submitLogin(wrapper: ReturnType<typeof mount>) {
  await wrapper.get('#login-email').setValue('ana@example.com')
  await wrapper.get('#login-password').setValue('correcta')
  await wrapper.get('form').trigger('submit.prevent')
  await flushPromises()
}

beforeEach(() => {
  login.mockReset()
  getMe.mockReset()
  login.mockResolvedValue({ kind: 'authenticated', user: {} })
})

describe('#427 login tras sesión anónima (RN-CORE-26, RN-CORE-28, CA-CORE-090)', () => {
  it('a) estado anonymous previo (GET /me 401): tras el login 200 llega al destino saneado', async () => {
    const { ApiError, router, wrapper, session } = await setup()
    getMe.mockRejectedValueOnce(problem(ApiError, 401))

    await router.push('/cuenta/sesiones')
    await flushPromises()

    expect(session.useSession().status.value).toBe('anonymous')
    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.redirect).toBe('/cuenta/sesiones')

    getMe.mockResolvedValueOnce(USER)
    await submitLogin(wrapper)

    expect(session.useSession().status.value).toBe('ready')
    expect(router.currentRoute.value.name).toBe('sessions')
  })

  it('b) tras clearSession() (logout): login 200 llega a home', async () => {
    const { router, wrapper, session } = await setup()
    getMe.mockResolvedValueOnce(USER)
    await router.push('/')
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('home')

    session.clearSession()
    await router.push('/entrar')
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('login')

    getMe.mockResolvedValueOnce(USER)
    await submitLogin(wrapper)

    expect(router.currentRoute.value.name).toBe('home')
  })

  it('c) login 200 pero GET /me posterior 401: falla cerrado y acaba en /entrar', async () => {
    const { ApiError, router, wrapper, session } = await setup()
    getMe.mockRejectedValueOnce(problem(ApiError, 401))
    await router.push('/')
    await flushPromises()

    getMe.mockRejectedValueOnce(problem(ApiError, 401))
    await submitLogin(wrapper)

    expect(session.useSession().status.value).toBe('anonymous')
    expect(router.currentRoute.value.name).toBe('login')
  })

  it('d) login 200 pero GET /me posterior 403 mfa-enrollment-required: acaba en el muro', async () => {
    const { ApiError, router, wrapper, session } = await setup()
    getMe.mockRejectedValueOnce(problem(ApiError, 401))
    await router.push('/')
    await flushPromises()

    getMe.mockRejectedValueOnce(problem(ApiError, 403, 'urn:pge:error:mfa-enrollment-required'))
    await submitLogin(wrapper)

    expect(session.useSession().status.value).toBe('mfa-required')
    expect(router.currentRoute.value.name).toBe('mfa-enrollment-wall')
  })
})
