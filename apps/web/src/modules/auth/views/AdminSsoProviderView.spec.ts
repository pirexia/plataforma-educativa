import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { i18n, setLocale } from '@/i18n'
import { ApiError } from '@/api/client'
import type { IdentityProviderDetail } from '../types'

// REQ-AUTH-004 (1.4b), funcional.md §F.9/§F.11, api.md §F.3-§F.5. Issue
// #147: `AdminSsoProviderView.vue` (529 líneas, la pantalla más grande
// del paso) no tenía ningún test propio. Cubre en particular el bug real
// corregido en `9a61dde` ("corrige detección de alta vs edición"): antes
// del arreglo, el componente inferÍa el modo desde `route.params.publicId`
// en vez del NOMBRE de la ruta, y como la ruta de alta
// (`sso-administration-new`, `/administracion/sso/nuevo`) no tiene
// segmento `:publicId`, el alta se trataba como edición de un recurso
// inexistente.
const getIdentityProviderDetail = vi.fn()
const createIdentityProvider = vi.fn()
const updateIdentityProvider = vi.fn()
const createIdentityProviderSecret = vi.fn()
const deleteIdentityProviderSecret = vi.fn()
const refreshIdentityProviderDiscovery = vi.fn()

vi.mock('../api', () => ({
  getIdentityProviderDetail: (...args: unknown[]) => getIdentityProviderDetail(...args),
  createIdentityProvider: (...args: unknown[]) => createIdentityProvider(...args),
  updateIdentityProvider: (...args: unknown[]) => updateIdentityProvider(...args),
  createIdentityProviderSecret: (...args: unknown[]) => createIdentityProviderSecret(...args),
  deleteIdentityProviderSecret: (...args: unknown[]) => deleteIdentityProviderSecret(...args),
  refreshIdentityProviderDiscovery: (...args: unknown[]) =>
    refreshIdentityProviderDiscovery(...args),
}))

const { default: AdminSsoProviderView } = await import('./AdminSsoProviderView.vue')

function detail(overrides: Partial<IdentityProviderDetail> = {}): IdentityProviderDetail {
  return {
    public_id: '01J-EXISTING',
    display_name: 'Entra ID del centro',
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
    discovery_url:
      'https://login.microsoftonline.com/tenant-x/v2.0/.well-known/openid-configuration',
    authorization_endpoint: 'https://login.microsoftonline.com/tenant-x/oauth2/v2.0/authorize',
    token_endpoint: 'https://login.microsoftonline.com/tenant-x/oauth2/v2.0/token',
    userinfo_endpoint: null,
    integration: {
      redirect_uri: 'https://sucentro.example.com/auth/oauth/oidc/callback',
      scopes: ['openid', 'email', 'profile'],
      subject_claim: 'sub',
      email_claim: 'email',
    },
    secrets: [],
    ...overrides,
  }
}

function buildRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/entrar', name: 'login', component: { template: '<div/>' } },
      {
        path: '/administracion/sso',
        name: 'sso-administration',
        component: { template: '<div/>' },
      },
      {
        path: '/administracion/sso/nuevo',
        name: 'sso-administration-new',
        component: AdminSsoProviderView,
      },
      {
        path: '/administracion/sso/:publicId',
        name: 'sso-administration-edit',
        component: AdminSsoProviderView,
      },
    ],
  })
}

async function mountNew() {
  const router = buildRouter()
  await router.push('/administracion/sso/nuevo')
  await router.isReady()
  const wrapper = mount(AdminSsoProviderView, { global: { plugins: [i18n, router] } })
  await flushPromises()
  return { wrapper, router }
}

async function mountEdit(publicId = '01J-EXISTING') {
  const router = buildRouter()
  await router.push(`/administracion/sso/${publicId}`)
  await router.isReady()
  const wrapper = mount(AdminSsoProviderView, { global: { plugins: [i18n, router] } })
  await flushPromises()
  return { wrapper, router }
}

async function fillMinimalForm(wrapper: ReturnType<typeof mount>) {
  await wrapper.get('#sso-display-name').setValue('Entra ID del centro')
  await wrapper
    .get('#sso-discovery-url')
    .setValue('https://login.microsoftonline.com/tenant-x/v2.0/.well-known/openid-configuration')
  await wrapper.get('#sso-client-id').setValue('client-abc')
}

describe('AdminSsoProviderView — alta vs edición (regresión 9a61dde)', () => {
  beforeEach(() => {
    getIdentityProviderDetail.mockReset()
    createIdentityProvider.mockReset()
    updateIdentityProvider.mockReset()
    createIdentityProviderSecret.mockReset()
    deleteIdentityProviderSecret.mockReset()
    refreshIdentityProviderDiscovery.mockReset()
    setLocale('es')
    vi.spyOn(window, 'confirm').mockReturnValue(true)
  })

  it('en la ruta de alta (sin segmento :publicId), NO se consulta el detalle y el formulario se pinta vacío de inmediato', async () => {
    const { wrapper } = await mountNew()

    // El bug real: al inferir el modo desde route.params.publicId
    // (undefined en esta ruta), el componente se quedaba "Cargando…"
    // para siempre en vez de pintar el formulario de alta.
    expect(getIdentityProviderDetail).not.toHaveBeenCalled()
    expect(wrapper.text()).not.toContain('Cargando')
    expect(wrapper.text()).toContain('Nuevo proveedor de identidad')
    expect((wrapper.get('#sso-display-name').element as HTMLInputElement).value).toBe('')
  })

  it('en la ruta de edición, SÍ se consulta el detalle con el public_id de la URL y se rellena el formulario', async () => {
    getIdentityProviderDetail.mockResolvedValue(detail())
    const { wrapper } = await mountEdit('01J-EXISTING')

    expect(getIdentityProviderDetail).toHaveBeenCalledWith('01J-EXISTING')
    expect(wrapper.text()).toContain('Editar proveedor de identidad')
    expect((wrapper.get('#sso-display-name').element as HTMLInputElement).value).toBe(
      'Entra ID del centro',
    )
    expect((wrapper.get('#sso-client-id').element as HTMLInputElement).value).toBe('client-abc')
  })

  it('CA-AUTH-260: el interruptor "proveedor activo" no existe en el alta — un proveedor nace no activo y solo se activa al editar', async () => {
    const { wrapper: newWrapper } = await mountNew()
    expect(newWrapper.find('input[type="checkbox"]').exists()).toBe(false)

    getIdentityProviderDetail.mockResolvedValue(detail())
    const { wrapper: editWrapper } = await mountEdit()
    expect(editWrapper.find('input[type="checkbox"]').exists()).toBe(true)
  })
})

describe('AdminSsoProviderView — flujo de alta', () => {
  beforeEach(() => {
    getIdentityProviderDetail.mockReset()
    createIdentityProvider.mockReset()
    updateIdentityProvider.mockReset()
    setLocale('es')
  })

  it('CA-AUTH-260: el alta no envía is_enabled — el servidor decide que nace no activo, el cliente no lo negocia', async () => {
    createIdentityProvider.mockResolvedValue(detail({ public_id: '01J-NEW', is_enabled: false }))
    const { wrapper } = await mountNew()

    await fillMinimalForm(wrapper)
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(createIdentityProvider).toHaveBeenCalledTimes(1)
    const payload = createIdentityProvider.mock.calls[0]?.[0]
    expect(payload).not.toHaveProperty('is_enabled')
    expect(payload.display_name).toBe('Entra ID del centro')
    expect(payload.scopes).toEqual(['openid', 'email', 'profile'])
  })

  it('tras crear con éxito, navega a la edición del recurso recién creado y pinta el bloque de integración', async () => {
    createIdentityProvider.mockResolvedValue(detail({ public_id: '01J-NEW' }))
    const { wrapper, router } = await mountNew()

    await fillMinimalForm(wrapper)
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('sso-administration-edit')
    expect(router.currentRoute.value.params.publicId).toBe('01J-NEW')
    expect(wrapper.text()).toContain('https://sucentro.example.com/auth/oauth/oidc/callback')
  })

  it('funcional.md §F.4.2: una URL de descubrimiento rechazada muestra el mensaje de campo del servidor, no uno genérico', async () => {
    createIdentityProvider.mockRejectedValue(
      new ApiError('validation', 422, {
        type: 'validation',
        title: 'validation',
        status: 422,
        errors: {
          discovery_url: [
            {
              code: 'issuer_mismatch',
              message: 'El emisor declarado no coincide con el origen de la URL.',
            },
          ],
        },
      }),
    )
    const { wrapper } = await mountNew()

    await fillMinimalForm(wrapper)
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.text()).toContain('El emisor declarado no coincide con el origen de la URL.')
    expect(wrapper.text()).not.toContain('No se han podido guardar los cambios.')
  })

  it('CA-AUTH-261: un emisor ya catalogado en el centro se muestra como conflicto explícito, no como error genérico', async () => {
    createIdentityProvider.mockRejectedValue(
      new ApiError('conflict', 409, {
        type: 'conflict',
        title: 'conflict',
        status: 409,
        detail: 'Este centro ya tiene catalogado un proveedor con ese mismo emisor.',
      }),
    )
    const { wrapper } = await mountNew()

    await fillMinimalForm(wrapper)
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.text()).toContain(
      'Este centro ya tiene catalogado un proveedor con ese mismo emisor.',
    )
  })

  it('ante un error sin forma reconocida (ni de campo ni de conflicto), cae al mensaje genérico', async () => {
    createIdentityProvider.mockRejectedValue(new ApiError('server error', 500, null))
    const { wrapper } = await mountNew()

    await fillMinimalForm(wrapper)
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.text()).toContain('No se han podido guardar los cambios.')
  })
})

describe('AdminSsoProviderView — CA-AUTH-285: aviso explícito de dominios vacíos', () => {
  beforeEach(() => {
    getIdentityProviderDetail.mockReset()
    setLocale('es')
  })

  it('con allowed_email_domains vacío, la pantalla advierte explícitamente que no hay restricción', async () => {
    getIdentityProviderDetail.mockResolvedValue(detail({ allowed_email_domains: [] }))
    const { wrapper } = await mountEdit()

    expect(wrapper.text()).toContain('Vacío = sin restricción.')
  })

  it('el aviso también está presente cuando SÍ hay restricción, para que el administrador entienda la regla antes de vaciarlo', async () => {
    getIdentityProviderDetail.mockResolvedValue(detail({ allowed_email_domains: ['sucentro.es'] }))
    const { wrapper } = await mountEdit()

    expect(wrapper.text()).toContain('Vacío = sin restricción.')
  })
})

describe('AdminSsoProviderView — credenciales de cliente (RN-AUTH-112)', () => {
  beforeEach(() => {
    getIdentityProviderDetail.mockReset()
    createIdentityProviderSecret.mockReset()
    deleteIdentityProviderSecret.mockReset()
    setLocale('es')
    vi.spyOn(window, 'confirm').mockReturnValue(true)
  })

  it('al cargar una credencial con fecha de caducidad, se envía en ISO y se recarga el detalle', async () => {
    getIdentityProviderDetail.mockResolvedValueOnce(detail({ secrets: [] }))
    createIdentityProviderSecret.mockResolvedValue({
      public_id: '01J-SECRET',
      activated_at: '2026-09-01T00:00:00Z',
      expires_at: '2027-01-01T00:00:00Z',
      retired_at: null,
    })
    getIdentityProviderDetail.mockResolvedValueOnce(
      detail({
        secrets: [
          {
            public_id: '01J-SECRET',
            activated_at: '2026-09-01T00:00:00Z',
            expires_at: '2027-01-01T00:00:00Z',
            retired_at: null,
          },
        ],
      }),
    )
    const { wrapper } = await mountEdit()

    await wrapper.get('#sso-new-secret').setValue('un-secreto-muy-largo')
    await wrapper.get('#sso-new-secret-expires').setValue('2027-01-01')
    // Dos <form> en la pantalla (datos del proveedor y alta de
    // credencial): el segundo es el de la credencial.
    await wrapper.findAll('form')[1]?.trigger('submit.prevent')
    await flushPromises()

    expect(createIdentityProviderSecret).toHaveBeenCalledWith('01J-EXISTING', {
      client_secret: 'un-secreto-muy-largo',
      expires_at: new Date('2027-01-01').toISOString(),
    })
    expect(getIdentityProviderDetail).toHaveBeenCalledTimes(2)
  })

  it('al cargar una credencial SIN fecha de caducidad, no se envía la clave expires_at (ni siquiera vacía)', async () => {
    getIdentityProviderDetail.mockResolvedValue(detail({ secrets: [] }))
    createIdentityProviderSecret.mockResolvedValue({
      public_id: '01J-SECRET',
      activated_at: '2026-09-01T00:00:00Z',
      expires_at: null,
      retired_at: null,
    })
    const { wrapper } = await mountEdit()

    await wrapper.get('#sso-new-secret').setValue('un-secreto-muy-largo')
    await wrapper.findAll('form')[1]?.trigger('submit.prevent')
    await flushPromises()

    const payload = createIdentityProviderSecret.mock.calls[0]?.[1]
    expect(payload).not.toHaveProperty('expires_at')
  })

  it('si se cancela la confirmación de retirada, no se llama a la API', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(false)
    getIdentityProviderDetail.mockResolvedValue(
      detail({
        secrets: [
          {
            public_id: '01J-SECRET',
            activated_at: '2026-09-01T00:00:00Z',
            expires_at: null,
            retired_at: null,
          },
        ],
      }),
    )
    const { wrapper } = await mountEdit()

    const retireButton = wrapper.findAll('button').find((b) => b.text() === 'Retirar')
    await retireButton?.trigger('click')
    await flushPromises()

    expect(deleteIdentityProviderSecret).not.toHaveBeenCalled()
  })

  it('RN-AUTH-112: retirar la última credencial vigente de un proveedor activo se rechaza con un mensaje específico, no genérico', async () => {
    getIdentityProviderDetail.mockResolvedValue(
      detail({
        secrets: [
          {
            public_id: '01J-SECRET',
            activated_at: '2026-09-01T00:00:00Z',
            expires_at: null,
            retired_at: null,
          },
        ],
      }),
    )
    deleteIdentityProviderSecret.mockRejectedValue(
      new ApiError('conflict', 409, { type: 'conflict', title: 'conflict', status: 409 }),
    )
    const { wrapper } = await mountEdit()

    const retireButton = wrapper.findAll('button').find((b) => b.text() === 'Retirar')
    await retireButton?.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain(
      'No puedes retirar la última credencial vigente de un proveedor activo: desactívalo antes.',
    )
  })
})

describe('AdminSsoProviderView — refresco de descubrimiento', () => {
  beforeEach(() => {
    getIdentityProviderDetail.mockReset()
    refreshIdentityProviderDiscovery.mockReset()
    setLocale('es')
  })

  it('si el refresco falla, se conservan los valores anteriores en pantalla, no se borran', async () => {
    getIdentityProviderDetail.mockResolvedValue(
      detail({
        integration: {
          ...detail().integration,
          redirect_uri: 'https://valor-antiguo.example.com/callback',
        },
      }),
    )
    refreshIdentityProviderDiscovery.mockRejectedValue(new Error('discovery unreachable'))
    const { wrapper } = await mountEdit()

    const refreshButton = wrapper
      .findAll('button')
      .find((b) => b.text() === 'Forzar refresco del documento de descubrimiento')
    await refreshButton?.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain(
      'No se ha podido refrescar el documento; se conservan los valores anteriores.',
    )
    expect(wrapper.text()).toContain('https://valor-antiguo.example.com/callback')
  })

  it('si el refresco tiene éxito, se reemplazan los valores mostrados por los nuevos', async () => {
    getIdentityProviderDetail.mockResolvedValue(
      detail({
        integration: {
          ...detail().integration,
          redirect_uri: 'https://valor-antiguo.example.com/callback',
        },
      }),
    )
    refreshIdentityProviderDiscovery.mockResolvedValue(
      detail({
        integration: {
          ...detail().integration,
          redirect_uri: 'https://valor-nuevo.example.com/callback',
        },
      }),
    )
    const { wrapper } = await mountEdit()

    const refreshButton = wrapper
      .findAll('button')
      .find((b) => b.text() === 'Forzar refresco del documento de descubrimiento')
    await refreshButton?.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('https://valor-nuevo.example.com/callback')
    expect(wrapper.text()).not.toContain('https://valor-antiguo.example.com/callback')
  })
})
