import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { i18n, setLocale } from '@/i18n'

// api.md §E.4.2: lista cerrada de códigos de resultado del *callback*.
// `getCsrfCookie` (usePublicAuthScreen) y los tres de MfaChallengeStep se
// mockean para no depender de la API real; ambos ficheros importan
// '../api' desde su propio directorio, pero resuelven al mismo módulo
// (`src/modules/auth/api/index.ts`), así que un único mock cubre los dos.
const getCsrfCookie = vi.fn().mockResolvedValue(undefined)
const getCurrentMfaChallenge = vi.fn()
const switchMfaChallenge = vi.fn()
const verifyMfaChallenge = vi.fn()

vi.mock('../api', () => ({
  getCsrfCookie: (...args: unknown[]) => getCsrfCookie(...args),
  getCurrentMfaChallenge: (...args: unknown[]) => getCurrentMfaChallenge(...args),
  switchMfaChallenge: (...args: unknown[]) => switchMfaChallenge(...args),
  verifyMfaChallenge: (...args: unknown[]) => verifyMfaChallenge(...args),
}))

const getTenantBranding = vi.fn().mockResolvedValue({
  name: 'Centro de ejemplo',
  color_primary: null,
  color_secondary: null,
  logo_url: null,
  login_background_url: null,
  default_locale: 'es-ES',
  active_locales: ['es-ES', 'en', 'de', 'fr'],
})

vi.mock('@/modules/core/api', () => ({
  getTenantBranding: (...args: unknown[]) => getTenantBranding(...args),
}))

const { default: GoogleCallbackResultView } = await import('./GoogleCallbackResultView.vue')

async function mountAt(pathWithQuery: string) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/entrar', name: 'login', component: { template: '<div/>' } },
      {
        path: '/entrar/google',
        name: 'oauth-google-callback',
        component: GoogleCallbackResultView,
      },
      { path: '/', name: 'home', component: { template: '<div/>' } },
      { path: '/cuenta/seguridad', name: 'mfa-security', component: { template: '<div/>' } },
      {
        path: '/cuenta/seguridad/obligatorio',
        name: 'mfa-enrollment-wall',
        component: { template: '<div/>' },
      },
    ],
  })

  await router.push(pathWithQuery)
  await router.isReady()

  const wrapper = mount(GoogleCallbackResultView, { global: { plugins: [i18n, router] } })
  await flushPromises()

  return { wrapper, router }
}

describe('GoogleCallbackResultView', () => {
  beforeEach(() => {
    getCsrfCookie.mockClear()
    getCurrentMfaChallenge.mockReset()
    switchMfaChallenge.mockReset()
    verifyMfaChallenge.mockReset()
    getTenantBranding.mockClear()
    setLocale('es')
    // `usePublicAuthScreen` resuelve el idioma como Accept-Language ∩
    // idiomas activos del centro (composables/usePublicAuthScreen.ts) —
    // sobrescribe el `setLocale` de arriba en cuanto carga el branding.
    // Se fija el idioma del navegador para que las aserciones de texto
    // sean deterministas, no para probar la negociación en sí.
    Object.defineProperty(window.navigator, 'language', {
      value: 'es-ES',
      configurable: true,
    })
    Object.defineProperty(window.navigator, 'languages', {
      value: ['es-ES'],
      configurable: true,
    })
  })

  it('CA-AUTH-216, api.md §E.5b: resultado=segundo_factor recupera y pinta el desafío con GET /auth/mfa-challenges', async () => {
    getCurrentMfaChallenge.mockResolvedValue({
      public_id: '01J-CHALLENGE',
      method: 'totp',
      available_methods: ['totp'],
      expires_at: new Date(Date.now() + 300_000).toISOString(),
      has_unused_recovery_codes: false,
    })

    const { wrapper } = await mountAt('/entrar/google?resultado=segundo_factor')

    expect(getCurrentMfaChallenge).toHaveBeenCalledTimes(1)
    expect(wrapper.text()).toContain('Verifica tu identidad')
  })

  it('funcional.md §E.4.2: resultado=alta_mfa_requerida redirige al muro de MFA', async () => {
    const { router } = await mountAt('/entrar/google?resultado=alta_mfa_requerida')

    expect(router.currentRoute.value.name).toBe('mfa-enrollment-wall')
  })

  it('funcional.md §E.4.2: resultado=vinculado vuelve a /cuenta/seguridad con el aviso de éxito', async () => {
    const { router } = await mountAt('/entrar/google?resultado=vinculado')

    expect(router.currentRoute.value.name).toBe('mfa-security')
    expect(router.currentRoute.value.query.linked).toBe('')
  })

  it('RN-AUTH-93/§E.4.6: resultado=sin_cuenta muestra el mensaje condicional y vuelve a /entrar', async () => {
    const { wrapper, router } = await mountAt('/entrar/google?resultado=sin_cuenta')

    expect(wrapper.text()).toContain('entra con tu contraseña')
    const link = wrapper.get('a')
    await link.trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('login')
  })

  it('api.md §E.4.2: resultado=cuenta_bloqueada reutiliza el mensaje genérico de bloqueo', async () => {
    const { wrapper } = await mountAt('/entrar/google?resultado=cuenta_bloqueada')

    expect(wrapper.text()).toContain('bloqueada temporalmente')
  })

  it('funcional.md §4.7/§E.4.2: resultado=acceso_denegado reutiliza el mensaje genérico del login local', async () => {
    const { wrapper } = await mountAt('/entrar/google?resultado=acceso_denegado')

    expect(wrapper.text()).toContain('no son correctos')
  })

  it('§E.4.4: resultado=ya_vinculado ofrece volver a /cuenta/seguridad, no a /entrar', async () => {
    const { wrapper } = await mountAt('/entrar/google?resultado=ya_vinculado')

    expect(wrapper.text()).toContain('Volver a mi cuenta')
    expect(wrapper.text()).not.toContain('Volver a iniciar sesión')
  })

  it('§E.4.4: resultado=proveedor_ya_vinculado no nombra al otro usuario', async () => {
    const { wrapper } = await mountAt('/entrar/google?resultado=proveedor_ya_vinculado')

    expect(wrapper.text()).toContain('ya está vinculada a otra persona')
  })
})
