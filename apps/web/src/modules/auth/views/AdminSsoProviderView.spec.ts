import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { i18n, setLocale } from '@/i18n'
import { ApiError } from '@/api/client'
import type { IdentityProviderOidcDetail, IdentityProviderSamlDetail } from '../types'

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
// REQ-AUTH-004 (1.4c), funcional.md §G.9, api.md §G.2-§G.5.
const getIdentityProviderSpMetadata = vi.fn()
const downloadIdentityProviderSpMetadataXml = vi.fn()
const refreshIdentityProviderMetadata = vi.fn()
const createIdentityProviderCertificate = vi.fn()
const deleteIdentityProviderCertificate = vi.fn()

vi.mock('../api', () => ({
  getIdentityProviderDetail: (...args: unknown[]) => getIdentityProviderDetail(...args),
  createIdentityProvider: (...args: unknown[]) => createIdentityProvider(...args),
  updateIdentityProvider: (...args: unknown[]) => updateIdentityProvider(...args),
  createIdentityProviderSecret: (...args: unknown[]) => createIdentityProviderSecret(...args),
  deleteIdentityProviderSecret: (...args: unknown[]) => deleteIdentityProviderSecret(...args),
  refreshIdentityProviderDiscovery: (...args: unknown[]) =>
    refreshIdentityProviderDiscovery(...args),
  getIdentityProviderSpMetadata: (...args: unknown[]) => getIdentityProviderSpMetadata(...args),
  downloadIdentityProviderSpMetadataXml: (...args: unknown[]) =>
    downloadIdentityProviderSpMetadataXml(...args),
  refreshIdentityProviderMetadata: (...args: unknown[]) => refreshIdentityProviderMetadata(...args),
  createIdentityProviderCertificate: (...args: unknown[]) =>
    createIdentityProviderCertificate(...args),
  deleteIdentityProviderCertificate: (...args: unknown[]) =>
    deleteIdentityProviderCertificate(...args),
}))

const { default: AdminSsoProviderView } = await import('./AdminSsoProviderView.vue')

function detail(overrides: Partial<IdentityProviderOidcDetail> = {}): IdentityProviderOidcDetail {
  return {
    public_id: '01J-EXISTING',
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

/** Hermana SAML de `detail()` — REQ-AUTH-004 (1.4c), api.md §G.2. */
function samlDetail(
  overrides: Partial<IdentityProviderSamlDetail> = {},
): IdentityProviderSamlDetail {
  return {
    public_id: '01J-SAML',
    display_name: 'ADFS del centro',
    protocol: 'saml',
    issuer: 'https://adfs.sucentro.es/adfs/services/trust',
    is_enabled: true,
    provisioning_mode: 'emparejamiento',
    allowed_email_domains: ['sucentro.es'],
    certificate_status: { vigentes: 0, proximo_vencimiento: null },
    authorization_endpoint: 'https://adfs.sucentro.es/adfs/ls',
    metadata_source: 'url',
    metadata_url: 'https://adfs.sucentro.es/federationmetadata/2007-06/federationmetadata.xml',
    metadata_xml: null,
    name_id_format: 'emailAddress',
    email_attribute: null,
    sign_authn_requests: false,
    metadata_fetched_at: '2026-09-01T10:00:00Z',
    metadata_failed_at: null,
    certificates: [],
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

// REQ-AUTH-004 (1.4c), funcional.md §G.9, api.md §G.2-§G.5. Un proveedor
// SAML: campos propios en vez de los de OIDC, protocol inmutable en
// edición (RN-AUTH-114, CA-AUTH-316), el bloque «qué registrar en tu
// IdP» y la gestión de certificados en vez de credenciales.
describe('AdminSsoProviderView — proveedor SAML', () => {
  beforeEach(() => {
    getIdentityProviderDetail.mockReset()
    createIdentityProvider.mockReset()
    updateIdentityProvider.mockReset()
    getIdentityProviderSpMetadata.mockReset()
    downloadIdentityProviderSpMetadataXml.mockReset()
    refreshIdentityProviderMetadata.mockReset()
    createIdentityProviderCertificate.mockReset()
    deleteIdentityProviderCertificate.mockReset()
    getIdentityProviderSpMetadata.mockResolvedValue({
      entity_id: 'https://sucentro.example.com/saml/01J-SAML',
      assertion_consumer_service_url: 'https://sucentro.example.com/api/v1/auth/saml/01J-SAML/acs',
      name_id_format: 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
      certificate: null,
    })
    setLocale('es')
    vi.spyOn(window, 'confirm').mockReturnValue(true)
  })

  it('api.md §G.2: un proveedor SAML pinta los campos de metadatos, no los de OIDC, y el protocolo se muestra como texto', async () => {
    getIdentityProviderDetail.mockResolvedValue(samlDetail())
    const { wrapper } = await mountEdit('01J-SAML')

    expect(wrapper.find('#sso-metadata-url').exists()).toBe(true)
    expect(wrapper.find('#sso-discovery-url').exists()).toBe(false)
    expect(wrapper.find('#sso-client-id').exists()).toBe(false)
    // RN-AUTH-114/CA-AUTH-316: protocol es inmutable en edición, así que
    // no hay ningún control de formulario para él (ni <select> ni
    // <input>), solo el texto.
    expect(wrapper.find('#sso-protocol').element.tagName).toBe('P')
    expect(wrapper.text()).toContain('SAML 2.0')
  })

  it('RN-AUTH-114, CA-AUTH-316: guardar cambios en un proveedor SAML nunca envía protocol en el PATCH', async () => {
    getIdentityProviderDetail.mockResolvedValue(samlDetail())
    updateIdentityProvider.mockResolvedValue(samlDetail({ display_name: 'ADFS renombrado' }))
    const { wrapper } = await mountEdit('01J-SAML')

    await wrapper.get('#sso-display-name').setValue('ADFS renombrado')
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    expect(updateIdentityProvider).toHaveBeenCalledTimes(1)
    const [calledPublicId, payload] = updateIdentityProvider.mock.calls[0] ?? []
    expect(calledPublicId).toBe('01J-SAML')
    expect(payload).not.toHaveProperty('protocol')
    expect(payload.metadata_url).toBe(
      'https://adfs.sucentro.es/federationmetadata/2007-06/federationmetadata.xml',
    )
  })

  it('api.md §G.3: carga y muestra los metadatos del SP (entityID, ACS URL) para copiarlos', async () => {
    getIdentityProviderDetail.mockResolvedValue(samlDetail())
    const { wrapper } = await mountEdit('01J-SAML')

    expect(getIdentityProviderSpMetadata).toHaveBeenCalledWith('01J-SAML')
    expect(wrapper.text()).toContain('https://sucentro.example.com/saml/01J-SAML')
    expect(wrapper.text()).toContain('https://sucentro.example.com/api/v1/auth/saml/01J-SAML/acs')
  })

  it('api.md §G.3: el botón de descarga pide el documento XML de metadatos del SP, no el JSON', async () => {
    downloadIdentityProviderSpMetadataXml.mockResolvedValue('<EntityDescriptor/>')
    vi.stubGlobal('URL', {
      ...URL,
      createObjectURL: vi.fn(() => 'blob:mock'),
      revokeObjectURL: vi.fn(),
    })
    getIdentityProviderDetail.mockResolvedValue(samlDetail())
    const { wrapper } = await mountEdit('01J-SAML')

    const downloadButton = wrapper.findAll('button').find((b) => b.text() === 'Descargar metadatos')
    await downloadButton?.trigger('click')
    await flushPromises()

    expect(downloadIdentityProviderSpMetadataXml).toHaveBeenCalledWith('01J-SAML')
    vi.unstubAllGlobals()
  })

  it('api.md §G.5: cargar un certificado llama a la API con el PEM pegado y recarga el detalle', async () => {
    getIdentityProviderDetail.mockResolvedValueOnce(samlDetail()).mockResolvedValueOnce(
      samlDetail({
        certificates: [
          {
            public_id: '01J-CERT',
            fingerprint_sha256: 'aa:bb:cc',
            not_before: '2026-01-01T00:00:00Z',
            not_after: '2027-01-01T00:00:00Z',
            source: 'manual',
            retired_at: null,
          },
        ],
      }),
    )
    createIdentityProviderCertificate.mockResolvedValue({
      public_id: '01J-CERT',
      fingerprint_sha256: 'aa:bb:cc',
      not_before: '2026-01-01T00:00:00Z',
      not_after: '2027-01-01T00:00:00Z',
      source: 'manual',
      retired_at: null,
    })
    const { wrapper } = await mountEdit('01J-SAML')

    await wrapper
      .get('#sso-new-certificate')
      .setValue('-----BEGIN CERTIFICATE-----\nMIIB...\n-----END CERTIFICATE-----')
    await wrapper
      .findAll('form')
      .find((f) => f.find('#sso-new-certificate').exists())
      ?.trigger('submit.prevent')
    await flushPromises()

    expect(createIdentityProviderCertificate).toHaveBeenCalledWith('01J-SAML', {
      certificate: '-----BEGIN CERTIFICATE-----\nMIIB...\n-----END CERTIFICATE-----',
    })
    expect(wrapper.text()).toContain('aa:bb:cc')
  })

  it('api.md §G.5: retirar un certificado pide confirmación, la advertencia de que no revoca en el IdP está siempre visible, y llama a la API', async () => {
    getIdentityProviderDetail.mockResolvedValue(
      samlDetail({
        certificates: [
          {
            public_id: '01J-CERT',
            fingerprint_sha256: 'aa:bb:cc',
            not_before: '2026-01-01T00:00:00Z',
            not_after: '2027-01-01T00:00:00Z',
            source: 'manual',
            retired_at: null,
          },
        ],
      }),
    )
    deleteIdentityProviderCertificate.mockResolvedValue(undefined)
    const { wrapper } = await mountEdit('01J-SAML')

    // funcional.md §G.9: la advertencia es obligatoria y siempre visible,
    // no solo tras la acción.
    expect(wrapper.text()).toContain('no lo revoca en tu proveedor de identidad')

    const retireButton = wrapper.findAll('button').find((b) => b.text() === 'Retirar')
    await retireButton?.trigger('click')
    await flushPromises()

    expect(window.confirm).toHaveBeenCalled()
    expect(deleteIdentityProviderCertificate).toHaveBeenCalledWith('01J-SAML', '01J-CERT')
  })

  it('CA-AUTH-326: si el refresco de metadatos falla, se conserva lo anterior y se avisa sin borrar nada', async () => {
    getIdentityProviderDetail.mockResolvedValue(samlDetail())
    refreshIdentityProviderMetadata.mockRejectedValue(new Error('metadata unreachable'))
    const { wrapper } = await mountEdit('01J-SAML')

    const refreshButton = wrapper
      .findAll('button')
      .find((b) => b.text() === 'Forzar refresco de los metadatos')
    await refreshButton?.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain(
      'No se han podido refrescar los metadatos; se conservan los valores anteriores.',
    )
    expect(wrapper.find('#sso-metadata-url').exists()).toBe(true)
  })

  // El selector de protocolo es un `Select` de Reka UI (sin `<select>`
  // nativo, contenido portado fuera del árbol montado): ningún test de
  // este fichero conduce un `Select` por interacción de usuario — ni
  // siquiera los ya existentes de `email_claim`/`claims_source`/
  // `provisioning_mode` lo hacen, todos dejan el valor por defecto. Se
  // sigue el mismo criterio aquí en vez de introducir un patrón de test
  // frágil y sin precedente en la suite.
  it('el alta por defecto (sin tocar el selector de protocolo) sigue siendo OIDC, protocol incluido en el payload', async () => {
    createIdentityProvider.mockResolvedValue(detail({ public_id: '01J-NEW' }))
    const { wrapper } = await mountNew()

    await fillMinimalForm(wrapper)
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()

    const payload = createIdentityProvider.mock.calls[0]?.[0]
    expect(payload.protocol).toBe('oidc')
  })
})
