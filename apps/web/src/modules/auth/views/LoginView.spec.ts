/**
 * `docs/modulos/REQ-CORE/funcional.md §12.3.3`, `§12.11`
 * (`CA-CORE-090`, `CA-CORE-091`). Único cambio funcional permitido a una
 * pantalla de `REQ-AUTH` en este paso (`§12.3.3`): tras un *login* sin
 * segundo factor, navega al `redirect` saneado (`RN-CORE-28`) en vez de
 * siempre a `home`.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { i18n, setLocale } from '@/i18n'

const login = vi.fn()
const getCsrfCookie = vi.fn().mockResolvedValue(undefined)

vi.mock('../api', () => ({
  login: (...args: unknown[]) => login(...args),
  verifyMfaChallenge: (...args: unknown[]) => verifyMfaChallenge(...args),
  getCsrfCookie: (...args: unknown[]) => getCsrfCookie(...args),
}))

const getTenantBranding = vi.fn().mockResolvedValue({
  name: 'Centro de ejemplo',
  color_primary: null,
  color_secondary: null,
  logo_url: null,
  favicon_url: null,
  login_background_url: null,
  default_locale: 'es-ES',
  active_locales: ['es-ES', 'en', 'de', 'fr'],
})

vi.mock('@/modules/core/api', () => ({
  getTenantBranding: (...args: unknown[]) => getTenantBranding(...args),
}))

// #427: `reloadSession` se mockea para comprobar que se invoca antes de navegar.
const reloadSession = vi.fn()

vi.mock('@/session/useSession', () => ({
  reloadSession: (...args: unknown[]) => reloadSession(...args),
}))

const verifyMfaChallenge = vi.fn()

const { default: LoginView } = await import('./LoginView.vue')

function makeRouter(): Router {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/entrar',
        name: 'login',
        component: LoginView,
        meta: { layout: 'public' },
      },
      {
        path: '/',
        name: 'home',
        component: { template: '<div/>' },
        meta: { layout: 'app', permissions: [] },
      },
      {
        path: '/cuenta/sesiones',
        name: 'sessions',
        component: { template: '<div/>' },
        meta: { layout: 'app', permissions: [] },
      },
      {
        path: '/administracion/mfa',
        name: 'mfa-administration',
        component: { template: '<div/>' },
        meta: { layout: 'app', permissions: ['mfa.leer'] },
      },
    ],
  })
}

async function mountAt(pathWithQuery: string) {
  const router = makeRouter()
  await router.push(pathWithQuery)
  await router.isReady()

  const wrapper = mount(LoginView, {
    global: { plugins: [i18n, router], stubs: { IdentityProviderLoginList: true } },
  })
  await flushPromises()

  return { wrapper, router }
}

beforeEach(() => {
  login.mockReset()
  getCsrfCookie.mockClear()
  getTenantBranding.mockClear()
  reloadSession.mockReset()
  reloadSession.mockResolvedValue(undefined)
  verifyMfaChallenge.mockReset()
  setLocale('es')
})

describe('LoginView — redirect tras el login (CA-CORE-090/091)', () => {
  it('sin redirect, navega a home', async () => {
    login.mockResolvedValue({ kind: 'authenticated', user: {} })
    const { wrapper, router } = await mountAt('/entrar')

    await wrapper.get('#login-email').setValue('ana@example.com')
    await wrapper.get('#login-password').setValue('correcta')
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('home')
  })

  it('con un redirect a una ruta app registrada, navega a ese destino (CA-CORE-090)', async () => {
    login.mockResolvedValue({ kind: 'authenticated', user: {} })
    const { wrapper, router } = await mountAt('/entrar?redirect=%2Fcuenta%2Fsesiones')

    await wrapper.get('#login-email').setValue('ana@example.com')
    await wrapper.get('#login-password').setValue('correcta')
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('sessions')
  })

  it.each([
    ['//evil.example'],
    ['https://evil.example'],
    ['/\\evil.example'],
    ['javascript:alert(1)'],
    ['/esto/no/existe'],
  ])('con redirect=%s, navega a home (CA-CORE-091)', async (redirect) => {
    login.mockResolvedValue({ kind: 'authenticated', user: {} })
    const { wrapper, router } = await mountAt(`/entrar?redirect=${encodeURIComponent(redirect)}`)

    await wrapper.get('#login-email').setValue('ana@example.com')
    await wrapper.get('#login-password').setValue('correcta')
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('home')
  })
})

describe('LoginView — recarga de la sesión antes de navegar (#427)', () => {
  it('#427, RN-AUTH-21, RN-CORE-28, CA-CORE-090: login 200 recarga GET /me y solo entonces navega al destino', async () => {
    login.mockResolvedValue({ kind: 'authenticated', user: {} })
    const { wrapper, router } = await mountAt('/entrar?redirect=%2Fcuenta%2Fsesiones')
    let routeWhenReloading: string | undefined
    reloadSession.mockImplementation(async () => {
      routeWhenReloading = String(router.currentRoute.value.name)
    })

    await wrapper.get('#login-email').setValue('ana@example.com')
    await wrapper.get('#login-password').setValue('correcta')
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(reloadSession).toHaveBeenCalledTimes(1)
    expect(routeWhenReloading).toBe('login')
    expect(router.currentRoute.value.name).toBe('sessions')
  })

  it('#427, RN-AUTH-21: un login 401 no recarga la sesión ni navega', async () => {
    login.mockRejectedValue(Object.assign(new Error('x'), { status: 401 }))
    const { wrapper, router } = await mountAt('/entrar')

    await wrapper.get('#login-email').setValue('ana@example.com')
    await wrapper.get('#login-password').setValue('mala')
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(reloadSession).not.toHaveBeenCalled()
    expect(router.currentRoute.value.name).toBe('login')
  })

  it('#427, RN-AUTH-21, RN-CORE-28: el desafío MFA superado recarga la sesión y solo entonces navega a home', async () => {
    login.mockResolvedValue({
      kind: 'mfa-challenge',
      challenge: {
        public_id: '01J-CHALLENGE',
        method: 'totp',
        available_methods: ['totp'],
        expires_at: new Date(Date.now() + 300_000).toISOString(),
        has_unused_recovery_codes: false,
      },
    })
    verifyMfaChallenge.mockResolvedValue(undefined)
    const { wrapper, router } = await mountAt('/entrar?redirect=%2Fcuenta%2Fsesiones')
    let routeWhenReloading: string | undefined
    reloadSession.mockImplementation(async () => {
      routeWhenReloading = String(router.currentRoute.value.name)
    })

    await wrapper.get('#login-email').setValue('ana@example.com')
    await wrapper.get('#login-password').setValue('correcta')
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    await wrapper.get('#mfa-challenge-code').setValue('123456')
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(verifyMfaChallenge).toHaveBeenCalledTimes(1)
    expect(reloadSession).toHaveBeenCalledTimes(1)
    expect(routeWhenReloading).toBe('login')
    expect(router.currentRoute.value.name).toBe('home')
  })
})
