import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
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

/** Respuesta paginada completa (el componente de tabla lee `current_page` y `last_page`). */
function page(data: unknown[], meta: Record<string, number> = {}) {
  return {
    data,
    meta: { current_page: 1, per_page: 25, total: data.length, last_page: 1, ...meta },
  }
}

function dialog(): Element | null {
  return document.body.querySelector('[role="alertdialog"]')
}

/** Botón de confirmar del diálogo (el que no es «Cancelar»). */
function confirmButton(): HTMLButtonElement {
  const buttons = [...dialog()!.querySelectorAll('button')]

  return buttons.find((button) => button.textContent?.trim() !== 'Cancelar') as HTMLButtonElement
}

function cancelButton(): HTMLButtonElement {
  return [...dialog()!.querySelectorAll('button')].find(
    (button) => button.textContent?.trim() === 'Cancelar',
  ) as HTMLButtonElement
}

async function openDelete(wrapper: { findAll: (s: string) => { text: () => string; trigger: (e: string) => Promise<void> }[] }) {
  const deleteButton = wrapper.findAll('button').find((b) => b.text() === 'Eliminar')
  await deleteButton?.trigger('click')
  await flushPromises()
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

  const wrapper = mount(AdminSsoView, {
    global: { plugins: [i18n, router] },
    attachTo: document.body,
  })
  await flushPromises()

  return { wrapper, router }
}

afterEach(() => {
  document.body.innerHTML = ''
})

describe('AdminSsoView', () => {
  beforeEach(() => {
    getIdentityProvidersCatalog.mockReset()
    deleteIdentityProvider.mockReset()
    setLocale('es')
    Object.defineProperty(window, 'matchMedia', {
      configurable: true,
      writable: true,
      value: (query: string) => ({
        matches: false,
        media: query,
        addEventListener: () => undefined,
        removeEventListener: () => undefined,
      }),
    })
    window.localStorage.clear()
  })

  it('funcional.md §F.9: catálogo vacío muestra el estado vacío, no una tabla', async () => {
    getIdentityProvidersCatalog.mockResolvedValue(page([]))
    const { wrapper } = await mountView()

    expect(wrapper.text()).toContain(
      'Este centro todavía no tiene ningún proveedor de identidad catalogado.',
    )
    expect(wrapper.find('table').exists()).toBe(false)
  })

  it('CA-AUTH-268: una credencial a menos de 30 días de caducar se muestra con el aviso y la fecha', async () => {
    getIdentityProvidersCatalog.mockResolvedValue(page([
        provider({
          secret_status: {
            has_active: true,
            active_expires_at: '2026-09-15T00:00:00Z',
            expiring_soon: true,
          },
        }),
      ]))
    const { wrapper } = await mountView()

    expect(wrapper.text()).toContain('Caduca pronto')
    expect(wrapper.text()).not.toContain('Sin credencial vigente')
  })

  it('sin credencial vigente se avisa aunque no haya ninguna a punto de caducar', async () => {
    getIdentityProvidersCatalog.mockResolvedValue(page([
        provider({
          secret_status: { has_active: false, active_expires_at: null, expiring_soon: false },
        }),
      ]))
    const { wrapper } = await mountView()

    expect(wrapper.text()).toContain('Sin credencial vigente')
    expect(wrapper.text()).not.toContain('Caduca pronto')
  })

  it('una credencial vigente sin caducidad próxima no muestra ningún aviso', async () => {
    getIdentityProvidersCatalog.mockResolvedValue(page([provider()]))
    const { wrapper } = await mountView()

    expect(wrapper.text()).toContain('Vigente')
    expect(wrapper.text()).not.toContain('Caduca pronto')
    expect(wrapper.text()).not.toContain('Sin credencial vigente')
  })

  it('un proveedor no activo se distingue del activo en la misma tabla', async () => {
    getIdentityProvidersCatalog.mockResolvedValue(page([
        provider({ public_id: '01J-A', display_name: 'Activo', is_enabled: true }),
        provider({ public_id: '01J-B', display_name: 'Inactivo', is_enabled: false }),
      ]))
    const { wrapper } = await mountView()

    expect(wrapper.text()).toContain('Activo')
    expect(wrapper.text()).toContain('Inactivo')
    expect(wrapper.text()).toContain('No activo')
  })

  it('al retirar, se pide confirmación y, si se acepta, se llama a la API con el public_id y se vuelve a pedir el catálogo (RN-CORE-96)', async () => {
    getIdentityProvidersCatalog.mockResolvedValue(page([provider()]))
    deleteIdentityProvider.mockResolvedValue(undefined)
    const { wrapper } = await mountView()

    await openDelete(wrapper)

    expect(dialog()).not.toBeNull()
    expect(deleteIdentityProvider).not.toHaveBeenCalled()

    // Tras el 204, el catálogo se vuelve a pedir y se pinta lo que devuelve el servidor.
    getIdentityProvidersCatalog.mockResolvedValue(page([]))
    const loads = getIdentityProvidersCatalog.mock.calls.length

    confirmButton().dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()

    expect(deleteIdentityProvider).toHaveBeenCalledWith('01J-PROVIDER-A')
    expect(getIdentityProvidersCatalog.mock.calls.length).toBe(loads + 1)
    expect(getIdentityProvidersCatalog).toHaveBeenLastCalledWith({ page: 1, per_page: 25 })
    expect(wrapper.text()).not.toContain('Entra ID del centro')
    expect(wrapper.text()).toContain(
      'Este centro todavía no tiene ningún proveedor de identidad catalogado.',
    )
  })

  it('si se cancela la confirmación, no se llama a la API y la fila permanece', async () => {
    getIdentityProvidersCatalog.mockResolvedValue(page([provider()]))
    const { wrapper } = await mountView()

    await openDelete(wrapper)
    cancelButton().dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()

    expect(deleteIdentityProvider).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('Entra ID del centro')
  })

  it('si la retirada falla en el servidor, la fila NO desaparece y se muestra el error con role=alert', async () => {
    getIdentityProvidersCatalog.mockResolvedValue(page([provider()]))
    deleteIdentityProvider.mockRejectedValue(new ApiError('conflict', 409, null))
    const { wrapper } = await mountView()

    await openDelete(wrapper)
    confirmButton().dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()

    expect(wrapper.text()).toContain('Entra ID del centro')
    expect(wrapper.text()).toContain('No se ha podido cargar el catálogo de proveedores.')
    expect(
      document.body.querySelector('p[role="alert"]')?.textContent?.includes('No se ha podido'),
    ).toBe(true)
  })

  it('funcional.md §F.9: un 401 al cargar el catálogo redirige a /entrar en vez de mostrar un error genérico', async () => {
    getIdentityProvidersCatalog.mockRejectedValue(new ApiError('unauthenticated', 401, null))
    const { router } = await mountView()

    expect(router.currentRoute.value.name).toBe('login')
  })
})

// 1.9f, `docs/modulos/REQ-CORE/funcional.md §14.13.4`: migración al componente de tabla.
describe('CA-CORE-293 (RN-CORE-84, RN-CORE-95, RN-CORE-96, REQ-AUTH-004): migración de AdminSsoView', () => {
  it('CA-CORE-257: pide la página 1 con per_page=25 (ya no per_page=100)', async () => {
    getIdentityProvidersCatalog.mockResolvedValue(page([provider()]))
    await mountView()

    expect(getIdentityProvidersCatalog).toHaveBeenCalledWith({ page: 1, per_page: 25 })
  })

  it('un OIDC sin secret_status pinta el valor vacío común y «Editar»/«Eliminar» tienen nombres accesibles distintos por proveedor', async () => {
    getIdentityProvidersCatalog.mockResolvedValue(
      page([
        provider({ secret_status: undefined } as Partial<IdentityProviderOidcSummary>),
        samlProvider(),
      ]),
    )
    await mountView()

    const rows = [...document.body.querySelectorAll('tbody tr')]
    const oidcRow = rows.find((row) => row.textContent?.includes('Entra ID del centro'))!
    const secretCell = oidcRow.querySelectorAll('td')[4]!

    expect(secretCell.querySelector('[data-slot="data-table-empty-value"]')).not.toBeNull()

    const names = [...document.body.querySelectorAll('tbody a, tbody button')].map((el) =>
      el.getAttribute('aria-label'),
    )

    expect(names).toEqual([
      'Editar Entra ID del centro',
      'Eliminar Entra ID del centro',
      'Editar ADFS del centro',
      'Eliminar ADFS del centro',
    ])
  })

  it('el diálogo de borrado nombra al proveedor; SAML lleva además el aviso del ACS y OIDC no', async () => {
    getIdentityProvidersCatalog.mockResolvedValue(page([provider(), samlProvider()]))
    await mountView()

    const buttons = [...document.body.querySelectorAll<HTMLButtonElement>('tbody button')]

    buttons[0]!.click()
    await flushPromises()

    expect(dialog()!.textContent).toContain('Eliminar Entra ID del centro')
    expect(dialog()!.textContent).not.toContain('la URL del ACS cambiará')

    cancelButton().dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()
    buttons[1]!.click()
    await flushPromises()

    expect(dialog()!.textContent).toContain('Eliminar ADFS del centro')
    expect(dialog()!.textContent).toContain('la URL del ACS cambiará')
  })

  it('una carga con 503 pinta el estado de error del componente con «Reintentar»', async () => {
    getIdentityProvidersCatalog.mockRejectedValue(new ApiError('unavailable', 503, null))
    await mountView()

    expect(document.body.textContent).toContain('Reintentar')
    expect(document.body.querySelector('table')).toBeNull()
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
    Object.defineProperty(window, 'matchMedia', {
      configurable: true,
      writable: true,
      value: (query: string) => ({
        matches: false,
        media: query,
        addEventListener: () => undefined,
        removeEventListener: () => undefined,
      }),
    })
    window.localStorage.clear()
  })

  it('api.md §G.2: la columna de protocolo distingue OIDC de SAML en la misma tabla', async () => {
    getIdentityProvidersCatalog.mockResolvedValue(page([provider(), samlProvider()]))
    const { wrapper } = await mountView()

    expect(wrapper.text()).toContain('OIDC')
    expect(wrapper.text()).toContain('SAML 2.0')
  })

  it('un proveedor SAML sin ningún certificado vigente se distingue del que sí tiene', async () => {
    getIdentityProvidersCatalog.mockResolvedValue(page([
        samlProvider({
          public_id: '01J-SAML-A',
          certificate_status: { vigentes: 0, proximo_vencimiento: null },
        }),
      ]))
    const { wrapper } = await mountView()

    expect(wrapper.text()).toContain('Sin certificado de firma vigente')
  })

  it('funcional.md §G.9: al retirar un proveedor SAML, la confirmación añade el aviso de que la ACS URL cambiará', async () => {
    getIdentityProvidersCatalog.mockResolvedValue(page([samlProvider()]))
    const { wrapper } = await mountView()

    await openDelete(wrapper)

    expect(dialog()!.textContent).toContain('la URL del ACS cambiará')
  })

  it('al retirar un proveedor OIDC, la confirmación NO lleva el aviso de la ACS URL (propio de SAML)', async () => {
    getIdentityProvidersCatalog.mockResolvedValue(page([provider()]))
    const { wrapper } = await mountView()

    await openDelete(wrapper)

    expect(dialog()!.textContent).not.toContain('la URL del ACS cambiará')
  })
})
