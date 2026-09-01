import { describe, expect, it, vi, beforeEach } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { i18n, setLocale } from '@/i18n'
import { ApiError } from '@/api/client'

// REQ-AUTH-002 (1.4), RN-AUTH-98/CA-AUTH-200: el botón se pinta solo si
// GET /auth/identity-providers dice que hay proveedor — nunca por una
// constante del cliente. Se mockea `../api` para no depender de la API
// real, mismo patrón que `useMfaUserSearch.spec.ts`.
const getIdentityProviders = vi.fn()
const beginOAuthAuthorization = vi.fn()

vi.mock('../api', () => ({
  getIdentityProviders: (...args: unknown[]) => getIdentityProviders(...args),
  beginOAuthAuthorization: (...args: unknown[]) => beginOAuthAuthorization(...args),
}))

const { default: GoogleSignInButton } = await import('./GoogleSignInButton.vue')

describe('GoogleSignInButton', () => {
  beforeEach(() => {
    getIdentityProviders.mockReset()
    beginOAuthAuthorization.mockReset()
    // El idioma inicial depende de `navigator.language`, no controlado
    // por este test (INV-009 no es lo que se prueba aquí): se fija
    // explícitamente para que las aserciones de texto sean estables.
    setLocale('es')
    Object.defineProperty(window, 'location', {
      writable: true,
      value: { ...window.location, href: '' },
    })
  })

  it('CA-AUTH-200: no pinta nada si el descubrimiento no incluye google', async () => {
    getIdentityProviders.mockResolvedValue({ data: [] })
    const wrapper = mount(GoogleSignInButton, { global: { plugins: [i18n] } })
    await flushPromises()

    expect(wrapper.find('button').exists()).toBe(false)
  })

  it('no pinta nada si la consulta al descubrimiento falla', async () => {
    getIdentityProviders.mockRejectedValue(new Error('network'))
    const wrapper = mount(GoogleSignInButton, { global: { plugins: [i18n] } })
    await flushPromises()

    expect(wrapper.find('button').exists()).toBe(false)
  })

  it('RN-AUTH-98: pinta el botón de login cuando el proveedor está disponible', async () => {
    getIdentityProviders.mockResolvedValue({
      data: [{ provider: 'google', label_key: 'auth.providers.google' }],
    })
    const wrapper = mount(GoogleSignInButton, { global: { plugins: [i18n] } })
    await flushPromises()

    expect(wrapper.find('button').exists()).toBe(true)
    expect(wrapper.text()).toContain('Continuar con Google')
  })

  it('funcional.md §E.4.4: con intent="link" pinta la etiqueta de vincular', async () => {
    getIdentityProviders.mockResolvedValue({
      data: [{ provider: 'google', label_key: 'auth.providers.google' }],
    })
    const wrapper = mount(GoogleSignInButton, {
      props: { intent: 'link' },
      global: { plugins: [i18n] },
    })
    await flushPromises()

    expect(wrapper.text()).toContain('Vincular con Google')
  })

  it('api.md §E.3: al pulsar, arranca el flujo y navega con window.location, no con un formulario', async () => {
    getIdentityProviders.mockResolvedValue({
      data: [{ provider: 'google', label_key: 'auth.providers.google' }],
    })
    beginOAuthAuthorization.mockResolvedValue({
      authorization_url: 'https://accounts.google.com/o/oauth2/v2/auth?client_id=x',
      expires_at: '2026-09-01T10:10:00Z',
    })
    const wrapper = mount(GoogleSignInButton, { global: { plugins: [i18n] } })
    await flushPromises()

    expect(wrapper.find('form').exists()).toBe(false)

    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(beginOAuthAuthorization).toHaveBeenCalledWith('login')
    expect(window.location.href).toBe('https://accounts.google.com/o/oauth2/v2/auth?client_id=x')
  })

  it('en 429 muestra el tiempo de reintento y no navega', async () => {
    getIdentityProviders.mockResolvedValue({
      data: [{ provider: 'google', label_key: 'auth.providers.google' }],
    })
    const headers = new Headers({ 'Retry-After': '30' })
    beginOAuthAuthorization.mockRejectedValue(new ApiError('rate limited', 429, null, headers))
    const wrapper = mount(GoogleSignInButton, { global: { plugins: [i18n] } })
    await flushPromises()

    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('30 segundos')
    expect(window.location.href).toBe('')
  })
})
