import { describe, expect, it, vi, beforeEach } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { i18n, setLocale } from '@/i18n'
import { ApiError } from '@/api/client'

// REQ-AUTH-004 (1.4b), funcional.md §F.9/§F.11, api.md §F.6. Issue #147:
// `IdentityProviderLoginList.vue` (la lista de botones institucionales de
// `/entrar`) no tenía ningún test propio — solo el backend
// (`IdentityProviderCatalogTest.php:206`) prueba que `GET
// /auth/identity-providers` devuelve `data: []`, que **no** es lo mismo
// que probar que la pantalla deja de pintar el botón. Mismo patrón que
// `GoogleSignInButton.spec.ts`: se mockea `../api`, no `fetch` global.
const getIdentityProviders = vi.fn()
const beginOAuthAuthorization = vi.fn()

vi.mock('../api', () => ({
  getIdentityProviders: (...args: unknown[]) => getIdentityProviders(...args),
  beginOAuthAuthorization: (...args: unknown[]) => beginOAuthAuthorization(...args),
}))

const { default: IdentityProviderLoginList } = await import('./IdentityProviderLoginList.vue')

function mountList() {
  return mount(IdentityProviderLoginList, { global: { plugins: [i18n] } })
}

describe('IdentityProviderLoginList', () => {
  beforeEach(() => {
    getIdentityProviders.mockReset()
    beginOAuthAuthorization.mockReset()
    setLocale('es')
    Object.defineProperty(window, 'location', {
      writable: true,
      value: { ...window.location, href: '' },
    })
  })

  it('CA-AUTH-269/RN-AUTH-98: un tenant sin proveedores catalogados no pinta ningún botón', async () => {
    getIdentityProviders.mockResolvedValue({ data: [] })
    const wrapper = mountList()
    await flushPromises()

    expect(wrapper.findAll('button')).toHaveLength(0)
    expect(wrapper.find('ul').exists()).toBe(false)
  })

  it('CA-AUTH-269: si la consulta de descubrimiento falla, tampoco pinta ningún botón', async () => {
    getIdentityProviders.mockRejectedValue(new Error('network'))
    const wrapper = mountList()
    await flushPromises()

    expect(wrapper.findAll('button')).toHaveLength(0)
  })

  it('RN-AUTH-98: con proveedores activos, pinta un botón por cada uno devuelto', async () => {
    getIdentityProviders.mockResolvedValue({
      data: [
        { id: 'idp-centro-a', display_name: 'Acceso del Centro A' },
        { id: 'idp-centro-b', display_name: 'Acceso del Centro B' },
      ],
    })
    const wrapper = mountList()
    await flushPromises()

    const buttons = wrapper.findAll('button')
    expect(buttons).toHaveLength(2)
    expect(wrapper.text()).toContain('Acceso del Centro A')
    expect(wrapper.text()).toContain('Acceso del Centro B')
  })

  it('funcional.md §F.9: el nombre de un proveedor institucional es el que puso el centro, sin traducir (display_name antes que display_name_key)', async () => {
    getIdentityProviders.mockResolvedValue({
      data: [
        {
          id: 'idp-centro-a',
          display_name: 'Mi Nombre Literal',
          display_name_key: 'auth.provider.google',
        },
      ],
    })
    const wrapper = mountList()
    await flushPromises()

    expect(wrapper.text()).toContain('Mi Nombre Literal')
    expect(wrapper.text()).not.toContain('Google')
  })

  it('sin display_name ni display_name_key, cae al id opaco como último recurso', async () => {
    getIdentityProviders.mockResolvedValue({ data: [{ id: 'idp-sin-nombre' }] })
    const wrapper = mountList()
    await flushPromises()

    expect(wrapper.text()).toContain('idp-sin-nombre')
  })

  it('funcional.md §F.9: un proveedor institucional no lleva logotipo, solo el driver global de Google lo conserva', async () => {
    getIdentityProviders.mockResolvedValue({
      data: [
        { id: 'google', display_name_key: 'auth.provider.google' },
        { id: 'idp-centro-a', display_name: 'Acceso del Centro A' },
      ],
    })
    const wrapper = mountList()
    await flushPromises()

    const items = wrapper.findAll('li')
    expect(items).toHaveLength(2)
    expect(items[0]?.find('svg').exists()).toBe(true)
    expect(items[1]?.find('svg').exists()).toBe(false)
  })

  it('api.md §F.6: al pulsar un proveedor institucional, arranca el flujo con SU id, no con el "google" por defecto', async () => {
    getIdentityProviders.mockResolvedValue({
      data: [{ id: 'idp-centro-a', display_name: 'Acceso del Centro A' }],
    })
    beginOAuthAuthorization.mockResolvedValue({
      authorization_url: 'https://idp.sucentro.es/authorize?x=1',
      expires_at: '2026-09-01T10:10:00Z',
    })
    const wrapper = mountList()
    await flushPromises()

    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(beginOAuthAuthorization).toHaveBeenCalledWith('login', 'idp-centro-a')
    expect(window.location.href).toBe('https://idp.sucentro.es/authorize?x=1')
  })

  it('RNF-UX-002: mientras un proveedor está arrancando, el resto de botones queda deshabilitado', async () => {
    getIdentityProviders.mockResolvedValue({
      data: [
        { id: 'idp-centro-a', display_name: 'Acceso del Centro A' },
        { id: 'idp-centro-b', display_name: 'Acceso del Centro B' },
      ],
    })
    // La promesa de arranque no se resuelve todavía: permite observar el
    // estado intermedio "arrancando".
    let resolveAuth: (() => void) | undefined
    beginOAuthAuthorization.mockReturnValue(
      new Promise((resolve) => {
        resolveAuth = () =>
          resolve({ authorization_url: 'https://idp/x', expires_at: '2026-09-01T10:10:00Z' })
      }),
    )
    const wrapper = mountList()
    await flushPromises()

    const buttons = wrapper.findAll('button')
    await buttons[0]?.trigger('click')
    await flushPromises()

    const buttonsAfter = wrapper.findAll('button')
    expect(buttonsAfter[0]?.attributes('disabled')).toBeDefined()
    expect(buttonsAfter[1]?.attributes('disabled')).toBeDefined()

    resolveAuth?.()
    await flushPromises()
  })

  it('en 429 muestra el tiempo de reintento y no navega', async () => {
    getIdentityProviders.mockResolvedValue({
      data: [{ id: 'idp-centro-a', display_name: 'Acceso del Centro A' }],
    })
    const headers = new Headers({ 'Retry-After': '45' })
    beginOAuthAuthorization.mockRejectedValue(new ApiError('rate limited', 429, null, headers))
    const wrapper = mountList()
    await flushPromises()

    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('45 segundos')
    expect(window.location.href).toBe('')
  })
})
