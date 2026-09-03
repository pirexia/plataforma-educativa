import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { i18n, setLocale } from '@/i18n'
import { ApiError } from '@/api/client'
import type { IdentityProviderOidcSummary, IdentityProviderSamlSummary } from '../types'

// REQ-AUTH-004 (1.4b), funcional.md §F.9/§F.11, api.md §F.2. Issue #147:
// `AdminSsoView.vue` (el catálogo de administración) no tenía ningún test
// propio. Mismo patrón de mock que `GoogleCallbackResultView.spec.ts`.
const getIdentityProvidersCatalog = vi.fn()
const deleteIdentityProvider = vi.fn()

vi.mock('../api', () => ({
  getIdentityProvidersCatalog: (...args: unknown[]) => getIdentityProvidersCatalog(...args),
  deleteIdentityProvider: (...args: unknown[]) => deleteIdentityProvider(...args),
}))

const { default: AdminSsoView } = await import('./AdminSsoView.vue')

function provider(
  overrides: Partial<IdentityProviderOidcSummary> = {},
): IdentityProviderOidcSummary {
  return {
    public_id: '01J-PROVIDER-A',
    display_name: 'Entra ID del centro',
    protocol: 'oidc',
    issuer: 'https://login.microsoftonline.com/tenant-x/v2.0',
    client_id: 'client-abc',
    is_enabled: true,
    provisioning_mode: 'emparejamiento',
    claims_source: 'id_token',
    email_claim: 'email',
    scopes: ['openid', 'email', 'profile'],
    allowed_email_domains: ['sucentro.es'],
    discovery_fetched_at: '2026-08-20T10:00:00Z',
    discovery_failed_at: null,
    secret_status: { has_active: true, active_expires_at: null, expiring_soon: false },
    ...overrides,
  }
}

/** Hermana SAML de `provider()` — REQ-AUTH-004 (1.4c), api.md §G.2. */
function samlProvider(
  overrides: Partial<IdentityProviderSamlSummary> = {},
): IdentityProviderSamlSummary {
  return {
    public_id: '01J-SAML-PROVIDER',
    display_name: 'ADFS del centro',
    protocol: 'saml',
    issuer: 'https://adfs.sucentro.es/adfs/services/trust',
    is_enabled: true,
    provisioning_mode: 'emparejamiento',
    allowed_email_domains: ['sucentro.es'],
    certificate_status: { vigentes: 1, proximo_vencimiento: '2027-01-01T00:00:00Z' },
    ...overrides,
  }
}

async function mountView() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/entrar', name: 'login', component: { template: '<div/>' } },
      { path: '/administracion/sso', name: 'sso-administration', component: AdminSsoView },
      {
        path: '/administracion/sso/nuevo',
        name: 'sso-administration-new',
        component: { template: '<div/>' },
      },
      {
        path: '/administracion/sso/:publicId',
        name: 'sso-administration-edit',
        component: { template: '<div/>' },
      },
    ],
  })

  await router.push('/administracion/sso')
  await router.isReady()

  const wrapper = mount(AdminSsoView, { global: { plugins: [i18n, router] } })
  await flushPromises()

  return { wrapper, router }
}

describe('AdminSsoView', () => {
  beforeEach(() => {
    getIdentityProvidersCatalog.mockReset()
    deleteIdentityProvider.mockReset()
    setLocale('es')
    vi.spyOn(window, 'confirm').mockReturnValue(true)
  })

  it('funcional.md §F.9: catálogo vacío muestra el estado vacío, no una tabla', async () => {
    getIdentityProvidersCatalog.mockResolvedValue({ data: [], meta: { total: 0 } })
    const { wrapper } = await mountView()

    expect(wrapper.text()).toContain(
      'Este centro todavía no tiene ningún proveedor de identidad catalogado.',
    )
    expect(wrapper.find('table').exists()).toBe(false)
  })

  it('CA-AUTH-268: una credencial a menos de 30 días de caducar se muestra con el aviso y la fecha', async () => {
    getIdentityProvidersCatalog.mockResolvedValue({
      data: [
        provider({
          secret_status: {
            has_active: true,
            active_expires_at: '2026-09-15T00:00:00Z',
            expiring_soon: true,
          },
        }),
      ],
      meta: { total: 1 },
    })
    const { wrapper } = await mountView()

    expect(wrapper.text()).toContain('Caduca pronto')
    expect(wrapper.text()).not.toContain('Sin credencial vigente')
  })

  it('sin credencial vigente se avisa aunque no haya ninguna a punto de caducar', async () => {
    getIdentityProvidersCatalog.mockResolvedValue({
      data: [
        provider({
          secret_status: { has_active: false, active_expires_at: null, expiring_soon: false },
        }),
      ],
      meta: { total: 1 },
    })
    const { wrapper } = await mountView()

    expect(wrapper.text()).toContain('Sin credencial vigente')
    expect(wrapper.text()).not.toContain('Caduca pronto')
  })

  it('una credencial vigente sin caducidad próxima no muestra ningún aviso', async () => {
    getIdentityProvidersCatalog.mockResolvedValue({ data: [provider()], meta: { total: 1 } })
    const { wrapper } = await mountView()

    expect(wrapper.text()).toContain('Vigente')
    expect(wrapper.text()).not.toContain('Caduca pronto')
    expect(wrapper.text()).not.toContain('Sin credencial vigente')
  })

  it('un proveedor no activo se distingue del activo en la misma tabla', async () => {
    getIdentityProvidersCatalog.mockResolvedValue({
      data: [
        provider({ public_id: '01J-A', display_name: 'Activo', is_enabled: true }),
        provider({ public_id: '01J-B', display_name: 'Inactivo', is_enabled: false }),
      ],
      meta: { total: 2 },
    })
    const { wrapper } = await mountView()

    expect(wrapper.text()).toContain('Activo')
    expect(wrapper.text()).toContain('Inactivo')
    expect(wrapper.text()).toContain('No activo')
  })

  it('al retirar, se pide confirmación y, si se acepta, se llama a la API con el public_id y desaparece la fila', async () => {
    getIdentityProvidersCatalog.mockResolvedValue({ data: [provider()], meta: { total: 1 } })
    deleteIdentityProvider.mockResolvedValue(undefined)
    const { wrapper } = await mountView()

    const deleteButton = wrapper.findAll('button').find((b) => b.text() === 'Eliminar')
    await deleteButton?.trigger('click')
    await flushPromises()

    expect(window.confirm).toHaveBeenCalled()
    expect(deleteIdentityProvider).toHaveBeenCalledWith('01J-PROVIDER-A')
    expect(wrapper.text()).not.toContain('Entra ID del centro')
    expect(wrapper.text()).toContain(
      'Este centro todavía no tiene ningún proveedor de identidad catalogado.',
    )
  })

  it('si se cancela la confirmación, no se llama a la API y la fila permanece', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(false)
    getIdentityProvidersCatalog.mockResolvedValue({ data: [provider()], meta: { total: 1 } })
    const { wrapper } = await mountView()

    const deleteButton = wrapper.findAll('button').find((b) => b.text() === 'Eliminar')
    await deleteButton?.trigger('click')
    await flushPromises()

    expect(deleteIdentityProvider).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('Entra ID del centro')
  })

  it('si la retirada falla en el servidor, la fila NO desaparece y se muestra el error', async () => {
    getIdentityProvidersCatalog.mockResolvedValue({ data: [provider()], meta: { total: 1 } })
    deleteIdentityProvider.mockRejectedValue(new ApiError('conflict', 409, null))
    const { wrapper } = await mountView()

    const deleteButton = wrapper.findAll('button').find((b) => b.text() === 'Eliminar')
    await deleteButton?.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Entra ID del centro')
    expect(wrapper.text()).toContain('No se ha podido cargar el catálogo de proveedores.')
  })

  it('funcional.md §F.9: un 401 al cargar el catálogo redirige a /entrar en vez de mostrar un error genérico', async () => {
    getIdentityProvidersCatalog.mockRejectedValue(new ApiError('unauthenticated', 401, null))
    const { router } = await mountView()

    expect(router.currentRoute.value.name).toBe('login')
  })
})

// REQ-AUTH-004 (1.4c), funcional.md §G.9, api.md §G.2. certificate_status
// es el hermano SAML de secret_status, pero con forma distinta
// ({vigentes, proximo_vencimiento}, sin booleano precalculado) —
// funcional.md §G.9 y §CA-AUTH-335 dejan el aviso de caducidad al comando
// diario, no a un umbral recalculado en la SPA.
describe('AdminSsoView — proveedor SAML', () => {
  beforeEach(() => {
    getIdentityProvidersCatalog.mockReset()
    deleteIdentityProvider.mockReset()
    setLocale('es')
    vi.spyOn(window, 'confirm').mockReturnValue(true)
  })

  it('api.md §G.2: la columna de protocolo distingue OIDC de SAML en la misma tabla', async () => {
    getIdentityProvidersCatalog.mockResolvedValue({
      data: [provider(), samlProvider()],
      meta: { total: 2 },
    })
    const { wrapper } = await mountView()

    expect(wrapper.text()).toContain('OIDC')
    expect(wrapper.text()).toContain('SAML 2.0')
  })

  it('un proveedor SAML sin ningún certificado vigente se distingue del que sí tiene', async () => {
    getIdentityProvidersCatalog.mockResolvedValue({
      data: [
        samlProvider({
          public_id: '01J-SAML-A',
          certificate_status: { vigentes: 0, proximo_vencimiento: null },
        }),
      ],
      meta: { total: 1 },
    })
    const { wrapper } = await mountView()

    expect(wrapper.text()).toContain('Sin certificado de firma vigente')
  })

  it('funcional.md §G.9: al retirar un proveedor SAML, la confirmación añade el aviso de que la ACS URL cambiará', async () => {
    getIdentityProvidersCatalog.mockResolvedValue({ data: [samlProvider()], meta: { total: 1 } })
    const { wrapper } = await mountView()

    const deleteButton = wrapper.findAll('button').find((b) => b.text() === 'Eliminar')
    await deleteButton?.trigger('click')
    await flushPromises()

    expect(window.confirm).toHaveBeenCalledWith(expect.stringContaining('la URL del ACS cambiará'))
  })

  it('al retirar un proveedor OIDC, la confirmación NO lleva el aviso de la ACS URL (propio de SAML)', async () => {
    getIdentityProvidersCatalog.mockResolvedValue({ data: [provider()], meta: { total: 1 } })
    const { wrapper } = await mountView()

    const deleteButton = wrapper.findAll('button').find((b) => b.text() === 'Eliminar')
    await deleteButton?.trigger('click')
    await flushPromises()

    expect(window.confirm).toHaveBeenCalledWith(
      expect.not.stringContaining('la URL del ACS cambiará'),
    )
  })
})
