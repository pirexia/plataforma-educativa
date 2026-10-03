/**
 * `docs/modulos/REQ-CORE/funcional.md §14.10`, `§14.18` (1.9e): activos de marca —
 * `CA-CORE-248` (sin escritura sin `configuracion.actualizar`), `CA-CORE-253`
 * (límite de tamaño, mensaje del servidor, recarga y `refresh()`, sin
 * `URL.createObjectURL`), `CA-CORE-254` (URL firmada caducada: una sola recarga)
 * y `RN-CORE-64` (confirmación al eliminar).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { i18n, setLocale } from '@/i18n'
import { ApiError } from '@/api/client'

const getTenantSettings = vi.fn()
const putTenantSettingsAsset = vi.fn()
const deleteTenantSettingsAsset = vi.fn()
const refresh = vi.fn()

vi.mock('../api', () => ({
  getTenantSettings: (...args: unknown[]) => getTenantSettings(...args),
  putTenantSettingsAsset: (...args: unknown[]) => putTenantSettingsAsset(...args),
  deleteTenantSettingsAsset: (...args: unknown[]) => deleteTenantSettingsAsset(...args),
}))

vi.mock('@/session/useSession', async () => {
  const { shallowRef } = await import('vue')
  const user = shallowRef<{ public_id: string; permissions: string[] } | null>(null)

  return {
    useSession: () => ({ user }),
    __setPermissions: (permissions: string[]) => {
      user.value = { public_id: 'ME', permissions }
    },
  }
})

vi.mock('@/tenant/useTenantBranding', async () => {
  const { shallowRef } = await import('vue')

  return {
    useTenantBranding: () => ({
      branding: shallowRef({ name: 'Colegio Ficticio' }),
      refresh: () => refresh(),
    }),
  }
})

const session = (await import('@/session/useSession')) as unknown as {
  __setPermissions: (permissions: string[]) => void
}
const { default: BrandingAssetsView } = await import('./BrandingAssetsView.vue')

function settings(branding: Record<string, string | null> = {}) {
  return {
    public_id: 'T1',
    regional: {
      default_locale: 'es-ES',
      active_locales: ['es-ES'],
      timezone: 'Europe/Madrid',
      currency: 'EUR',
      autonomous_community: null,
    },
    fiscal: {},
    branding: {
      color_primary: '#1D4ED8',
      color_secondary: '#FFFFFF',
      logo_url: 'https://files.example.com/logo?sig=1',
      favicon_url: null,
      login_background_url: 'https://files.example.com/bg?sig=1',
      ...branding,
    },
    updated_at: '2026-08-19T09:00:00Z',
  }
}

function problem(status: number, body: Record<string, unknown> = {}): ApiError {
  return new ApiError(`HTTP ${status}`, status, { status, ...body })
}

const wrappers: VueWrapper[] = []

async function mountView(): Promise<VueWrapper> {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/administracion/centro/marca',
        name: 'core-branding-assets',
        component: BrandingAssetsView,
      },
    ],
  })

  await router.push('/administracion/centro/marca')
  await router.isReady()

  const wrapper = mount(BrandingAssetsView, {
    global: { plugins: [i18n, router] },
    attachTo: document.body,
  }) as VueWrapper

  wrappers.push(wrapper)
  await flushPromises()

  return wrapper
}

function fileInput(kind: string): HTMLInputElement {
  return document.querySelector(`[data-testid="branding-file-${kind}"]`) as HTMLInputElement
}

async function choose(kind: string, file: File): Promise<void> {
  const input = fileInput(kind)

  Object.defineProperty(input, 'files', { configurable: true, value: [file] })
  input.dispatchEvent(new Event('change', { bubbles: true }))
  await flushPromises()
}

function fileOfSize(name: string, type: string, bytes: number): File {
  return new File([new Uint8Array(bytes)], name, { type })
}

beforeEach(() => {
  setLocale('es')
  getTenantSettings.mockReset().mockResolvedValue(settings())
  putTenantSettingsAsset.mockReset().mockResolvedValue({ kind: 'logo', url: 'https://x/y' })
  deleteTenantSettingsAsset.mockReset().mockResolvedValue(undefined)
  refresh.mockReset().mockResolvedValue(undefined)
  session.__setPermissions(['configuracion.leer', 'configuracion.actualizar'])
})

afterEach(() => {
  while (wrappers.length > 0) {
    wrappers.pop()!.unmount()
  }
  document.body.innerHTML = ''
})

describe('RN-CORE-83: los tres bloques', () => {
  it('muestra logo, favicon y fondo con sus límites; el logo lleva alt traducido y el fondo es decorativo', async () => {
    const wrapper = await mountView()
    const text = wrapper.text()

    expect(text).toContain('Formatos admitidos: SVG, PNG o WebP. Tamaño máximo: 1 MB.')
    expect(text).toContain('Formatos admitidos: PNG, ICO o SVG. Tamaño máximo: 256 KB.')
    expect(text).toContain('Formatos admitidos: JPEG, PNG o WebP (no SVG). Tamaño máximo: 3 MB.')

    const images = wrapper.findAll('img')

    expect(images).toHaveLength(2)
    expect(images[0]!.attributes('alt')).toBe('Logotipo actual de Colegio Ficticio')
    expect(images[1]!.attributes('alt')).toBe('')
    expect(text).toContain('No hay favicon.')
  })

  it('solo pide GET /tenant/settings al abrir', async () => {
    await mountView()

    expect(getTenantSettings).toHaveBeenCalledTimes(1)
  })
})

describe('CA-CORE-248 (RN-CORE-79): sin configuracion.actualizar', () => {
  it('muestra las imágenes sin Sustituir ni Eliminar y no sale ninguna escritura', async () => {
    session.__setPermissions(['configuracion.leer'])

    const wrapper = await mountView()

    expect(wrapper.findAll('img')).toHaveLength(2)
    expect(wrapper.find('button').exists()).toBe(false)
    expect(wrapper.find('input[type="file"]').exists()).toBe(false)
    expect(putTenantSettingsAsset).not.toHaveBeenCalled()
    expect(deleteTenantSettingsAsset).not.toHaveBeenCalled()
  })
})

describe('CA-CORE-253 (RN-CORE-83, RSEC-OWASP-012): sustituir', () => {
  it('un fichero de 2 MB avisa del límite de 1 MB sin enviar nada', async () => {
    const wrapper = await mountView()

    await choose('logo', fileOfSize('logo.png', 'image/png', 2 * 1024 * 1024))

    expect(wrapper.text()).toContain('El logotipo supera el límite de 1 MB.')
    expect(putTenantSettingsAsset).not.toHaveBeenCalled()
  })

  it('un tipo no admitido por el bloque avisa sin enviar nada (comodidad; decide el servidor)', async () => {
    const wrapper = await mountView()

    await choose('login-background', fileOfSize('fondo.svg', 'image/svg+xml', 100))

    expect(wrapper.text()).toContain('El fondo de acceso debe ser JPEG, PNG o WebP.')
    expect(putTenantSettingsAsset).not.toHaveBeenCalled()
  })

  it('un fichero válido cuya subida responde 422 muestra el mensaje del servidor', async () => {
    putTenantSettingsAsset.mockRejectedValueOnce(
      problem(422, {
        errors: { file: [{ code: 'mimes', message: 'El contenido no es una imagen PNG.' }] },
      }),
    )

    const wrapper = await mountView()

    await choose('logo', fileOfSize('logo.png', 'image/png', 1000))

    expect(putTenantSettingsAsset).toHaveBeenCalledTimes(1)
    expect(wrapper.get('[role="alert"]').text()).toBe('El contenido no es una imagen PNG.')
    expect(getTenantSettings).toHaveBeenCalledTimes(1)
    expect(refresh).not.toHaveBeenCalled()
  })

  it('413 y 415 sin cuerpo JSON caen a un mensaje traducido', async () => {
    putTenantSettingsAsset.mockRejectedValueOnce(problem(413, { detail: undefined }))

    const wrapper = await mountView()

    await choose('logo', fileOfSize('logo.png', 'image/png', 1000))

    expect(wrapper.get('[role="alert"]').text()).toBe('El fichero es demasiado grande.')

    putTenantSettingsAsset.mockRejectedValueOnce(problem(415))
    await choose('logo', fileOfSize('logo.png', 'image/png', 1000))

    expect(wrapper.get('[role="alert"]').text()).toBe('El tipo de fichero no está admitido.')
  })

  it('con 200 se vuelve a pedir GET /tenant/settings y se llama a refresh() una vez', async () => {
    getTenantSettings
      .mockResolvedValueOnce(settings())
      .mockResolvedValueOnce(settings({ logo_url: 'https://files.example.com/logo?sig=2' }))

    const wrapper = await mountView()

    await choose('logo', fileOfSize('logo.png', 'image/png', 1000))

    expect(putTenantSettingsAsset).toHaveBeenCalledTimes(1)
    expect(putTenantSettingsAsset.mock.calls[0]![0]).toBe('logo')
    expect((putTenantSettingsAsset.mock.calls[0]![1] as File).name).toBe('logo.png')
    expect(getTenantSettings).toHaveBeenCalledTimes(2)
    expect(refresh).toHaveBeenCalledTimes(1)
    expect(wrapper.get('[role="status"]').text()).toBe('Imagen guardada.')
    expect(wrapper.findAll('img')[0]!.attributes('src')).toBe(
      'https://files.example.com/logo?sig=2',
    )
  })

  it('no usa URL.createObjectURL: no hay vista previa local del fichero elegido', async () => {
    const original = URL.createObjectURL
    const spy = vi.fn()

    URL.createObjectURL = spy as unknown as typeof URL.createObjectURL

    try {
      await mountView()
      await choose('logo', fileOfSize('logo.png', 'image/png', 1000))
    } finally {
      URL.createObjectURL = original
    }

    expect(spy).not.toHaveBeenCalled()
  })
})

describe('RN-CORE-64: eliminar un activo pide confirmación', () => {
  function dialogButton(label: string): HTMLButtonElement {
    return [...document.body.querySelectorAll('[role="alertdialog"] button')].find(
      (candidate) => candidate.textContent?.trim() === label,
    ) as HTMLButtonElement
  }

  async function openRemove(wrapper: VueWrapper): Promise<void> {
    await wrapper
      .findAll('button')
      .find((button) => button.text() === 'Eliminar logotipo')!
      .trigger('click')
    await flushPromises()
  }

  it('no sale ninguna petición hasta confirmar; cancelar no borra', async () => {
    const wrapper = await mountView()

    await openRemove(wrapper)

    expect(document.body.querySelector('[role="alertdialog"]')).not.toBeNull()
    expect(deleteTenantSettingsAsset).not.toHaveBeenCalled()

    dialogButton('Cancelar').click()
    await flushPromises()

    expect(document.body.querySelector('[role="alertdialog"]')).toBeNull()
    expect(deleteTenantSettingsAsset).not.toHaveBeenCalled()
  })

  it('el botón de confirmar nombra el activo y el centro; confirmar borra, recarga y llama a refresh()', async () => {
    getTenantSettings
      .mockResolvedValueOnce(settings())
      .mockResolvedValueOnce(settings({ logo_url: null }))

    const wrapper = await mountView()

    await openRemove(wrapper)
    dialogButton('Eliminar el logotipo de Colegio Ficticio').click()
    await flushPromises()

    expect(deleteTenantSettingsAsset).toHaveBeenCalledWith('logo')
    expect(getTenantSettings).toHaveBeenCalledTimes(2)
    expect(refresh).toHaveBeenCalledTimes(1)
    expect(wrapper.text()).toContain('No hay logotipo.')
    expect(wrapper.get('[role="status"]').text()).toBe('Imagen eliminada.')
  })

  it('«Eliminar» solo se ofrece si hay activo', async () => {
    const wrapper = await mountView()
    const labels = wrapper.findAll('button').map((button) => button.text())

    expect(labels).toContain('Eliminar logotipo')
    expect(labels).toContain('Eliminar fondo de acceso')
    expect(labels).not.toContain('Eliminar favicon')
  })
})

describe('CA-CORE-254 (RN-CORE-83): URL firmada caducada', () => {
  it('si la imagen falla al cargar se pide GET /tenant/settings exactamente una vez; si vuelve a fallar, estado de error y ninguna petición más', async () => {
    const wrapper = await mountView()

    expect(getTenantSettings).toHaveBeenCalledTimes(1)

    await wrapper.findAll('img')[0]!.trigger('error')
    await flushPromises()

    expect(getTenantSettings).toHaveBeenCalledTimes(2)

    await wrapper.findAll('img')[0]!.trigger('error')
    await flushPromises()

    expect(getTenantSettings).toHaveBeenCalledTimes(2)
    expect(wrapper.text()).toContain('No se ha podido cargar la imagen.')

    // Otro fallo de otro bloque tampoco vuelve a pedirla: sin bucle.
    await wrapper.findAll('img')[0]!.trigger('error')
    await flushPromises()

    expect(getTenantSettings).toHaveBeenCalledTimes(2)
  })
})
