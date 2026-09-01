import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { i18n, setLocale } from '@/i18n'

// REQ-AUTH-004 (1.4b), funcional.md §F.9/§F.11, api.md §F.7. Issue #147:
// `SsoCallbackResultView.vue` no tenía ningún test propio — es la
// paralela institucional de `GoogleCallbackResultView.vue` (`1.4`), así
// que sigue exactamente su mismo patrón de test (`getCsrfCookie`/
// `getCurrentMfaChallenge`/... mockeados por '../api', y
// `getTenantBranding` por '@/modules/core/api').
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

const { default: SsoCallbackResultView } = await import('./SsoCallbackResultView.vue')

async function mountAt(pathWithQuery: string) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/entrar', name: 'login', component: { template: '<div/>' } },
      {
        path: '/entrar/sso',
        name: 'oauth-sso-callback',
        component: SsoCallbackResultView,
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

  const wrapper = mount(SsoCallbackResultView, { global: { plugins: [i18n, router] } })
  await flushPromises()

  return { wrapper, router }
}

describe('SsoCallbackResultView', () => {
  beforeEach(() => {
    getCsrfCookie.mockClear()
    getCurrentMfaChallenge.mockReset()
    switchMfaChallenge.mockReset()
    verifyMfaChallenge.mockReset()
    getTenantBranding.mockClear()
    setLocale('es')
    Object.defineProperty(window.navigator, 'language', {
      value: 'es-ES',
      configurable: true,
    })
    Object.defineProperty(window.navigator, 'languages', {
      value: ['es-ES'],
      configurable: true,
    })
  })

  it('funcional.md §F.9: el título es el de acceso institucional, no un copia-pega del de Google', async () => {
    const { wrapper } = await mountAt('/entrar/sso?resultado=cancelado')

    expect(wrapper.text()).toContain('Inicio de sesión institucional')
    expect(wrapper.text()).not.toContain('Inicio de sesión con Google')
  })

  it('CA-AUTH-282/RN-AUTH-107, api.md §F.7.1: resultado=dominio_no_permitido explica la restricción por dominio del centro, sin nombrar a nadie', async () => {
    const { wrapper } = await mountAt('/entrar/sso?resultado=dominio_no_permitido')

    expect(wrapper.text()).toContain(
      'Este centro solo admite cuentas de su propio dominio de correo.',
    )
  })

  it('api.md §F.7.1 (fila proveedor_no_disponible): un proveedor que se desactiva o se queda sin credencial entre el arranque y la vuelta avisa explícitamente, no un error genérico', async () => {
    const { wrapper } = await mountAt('/entrar/sso?resultado=proveedor_no_disponible')

    expect(wrapper.text()).toContain('Este proveedor de acceso no está disponible ahora mismo.')
  })

  it('api.md §F.7.1: resultado=segundo_factor recupera y pinta el desafío con GET /auth/mfa-challenges (herencia literal de 1.3)', async () => {
    getCurrentMfaChallenge.mockResolvedValue({
      public_id: '01J-CHALLENGE',
      method: 'totp',
      available_methods: ['totp'],
      expires_at: new Date(Date.now() + 300_000).toISOString(),
      has_unused_recovery_codes: false,
    })

    const { wrapper } = await mountAt('/entrar/sso?resultado=segundo_factor')

    expect(getCurrentMfaChallenge).toHaveBeenCalledTimes(1)
    expect(wrapper.text()).toContain('Verifica tu identidad')
  })

  it('api.md §F.7.1: resultado=alta_mfa_requerida redirige al muro de MFA, herencia literal de 1.3', async () => {
    const { router } = await mountAt('/entrar/sso?resultado=alta_mfa_requerida')

    expect(router.currentRoute.value.name).toBe('mfa-enrollment-wall')
  })

  it('api.md §F.7.1: resultado=vinculado vuelve a /cuenta/seguridad con el aviso de éxito', async () => {
    const { router } = await mountAt('/entrar/sso?resultado=vinculado')

    expect(router.currentRoute.value.name).toBe('mfa-security')
    expect(router.currentRoute.value.query.linked).toBe('')
  })

  it('CA-AUTH-301: resultado=cuenta_bloqueada reutiliza el mensaje genérico de bloqueo (sin mención de proveedor)', async () => {
    const { wrapper } = await mountAt('/entrar/sso?resultado=cuenta_bloqueada')

    expect(wrapper.text()).toContain('bloqueada temporalmente')
  })

  it('CA-AUTH-279: resultado=acceso_denegado reutiliza el mensaje genérico del login local (no distingue "sin sub" de "no hay cuenta", sin mención de proveedor)', async () => {
    const { wrapper } = await mountAt('/entrar/sso?resultado=acceso_denegado')

    expect(wrapper.text()).toContain('no son correctos')
  })

  // Los seis casos siguientes (`sin_cuenta`, `cancelado`, `estado_no_valido`,
  // `error_proveedor`, `ya_vinculado`, `proveedor_ya_vinculado`) comparten
  // *código* con `GoogleCallbackResultView.vue` ("herencia literal",
  // api.md §F.7.1), pero desde el issue #148 ya NO comparten *texto*: usan
  // el juego neutro `auth.ssoCallback`, que no menciona ningún proveedor
  // por nombre. Cada test fija explícitamente la ausencia de "Google" para
  // que una regresión futura (volver a apuntar a `auth.oauthCallback`) la
  // detecte de inmediato.
  it('resultado=sin_cuenta: pinta un mensaje sin mencionar Google y ofrece volver a /entrar (RN-AUTH-93)', async () => {
    const { wrapper, router } = await mountAt('/entrar/sso?resultado=sin_cuenta')

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('Google')
    const link = wrapper.get('a')
    await link.trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('login')
  })

  it('resultado=ya_vinculado: ofrece volver a /cuenta/seguridad, no a /entrar, sin mencionar Google', async () => {
    const { wrapper } = await mountAt('/entrar/sso?resultado=ya_vinculado')

    expect(wrapper.text()).toContain('Volver a mi cuenta')
    expect(wrapper.text()).not.toContain('Volver a iniciar sesión')
    expect(wrapper.text()).not.toContain('Google')
  })

  it('CA-AUTH-278/api.md §F.7.1: resultado=proveedor_ya_vinculado no nombra al otro usuario del centro ni menciona Google', async () => {
    const { wrapper } = await mountAt('/entrar/sso?resultado=proveedor_ya_vinculado')

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    // RN-AUTH-93: ningún dato personal en la respuesta del callback ni,
    // por tanto, en lo que esta pantalla puede llegar a mostrar.
    expect(wrapper.text()).not.toMatch(/@/)
    expect(wrapper.text()).not.toContain('Google')
  })

  it('CA-AUTH-275: resultado=estado_no_valido pinta un mensaje de alerta accionable sin mencionar Google, no una pantalla en blanco', async () => {
    const { wrapper } = await mountAt('/entrar/sso?resultado=estado_no_valido')

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Volver a iniciar sesión')
    expect(wrapper.text()).not.toContain('Google')
  })

  it('CA-AUTH-276/277: resultado=error_proveedor pinta un mensaje de alerta accionable sin mencionar Google, no una pantalla en blanco', async () => {
    const { wrapper } = await mountAt('/entrar/sso?resultado=error_proveedor')

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Volver a iniciar sesión')
    expect(wrapper.text()).not.toContain('Google')
  })

  it('sin ningún código de resultado reconocido, cae al mensaje genérico de error en vez de dejar la pantalla en blanco', async () => {
    const { wrapper } = await mountAt('/entrar/sso')

    expect(wrapper.text()).toContain(
      'No se ha podido contactar con el servidor. Inténtalo de nuevo.',
    )
  })
})
