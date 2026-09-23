/**
 * `docs/design-system.md` §7, §18 (`CA-DS-023` a `CA-DS-028`; `CA-DS-029`
 * es una regla de arquitectura, cubierta en `architecture.spec.ts`).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/api/client'

const getTenantBranding = vi.fn()
vi.mock('@/modules/core/api', () => ({
  getTenantBranding: (...args: unknown[]) => getTenantBranding(...args),
}))

const applyBrandPalette = vi.fn()
vi.mock('@/design-system/theme/brandPalette', () => ({
  applyBrandPalette: (...args: unknown[]) => applyBrandPalette(...args),
}))

const applyFavicon = vi.fn()
vi.mock('./favicon', () => ({
  applyFavicon: (...args: unknown[]) => applyFavicon(...args),
}))

async function freshModule() {
  vi.resetModules()

  return import('./useTenantBranding')
}

function makeBranding(overrides: Record<string, unknown> = {}) {
  return {
    name: 'Centro de ejemplo',
    color_primary: '#1D4ED8',
    color_secondary: '#FFFFFF',
    logo_url: null,
    favicon_url: null,
    login_background_url: null,
    default_locale: 'es-ES',
    active_locales: ['es-ES'],
    ...overrides,
  }
}

beforeEach(() => {
  localStorage.clear()
  getTenantBranding.mockReset()
  applyBrandPalette.mockReset()
  applyFavicon.mockReset()
})

afterEach(() => {
  vi.useRealTimers()
})

describe('bootstrapTenantBranding — CA-DS-023', () => {
  it('resuelve a los 1000ms con status loading si la API no responde; se actualiza cuando lo hace', async () => {
    vi.useFakeTimers()

    let resolveFetch: (value: unknown) => void = () => {}
    getTenantBranding.mockImplementation(
      () =>
        new Promise((resolve) => {
          resolveFetch = resolve
        }),
    )

    const { bootstrapTenantBranding, useTenantBranding } = await freshModule()

    let settled = false
    void bootstrapTenantBranding().then(() => {
      settled = true
    })

    await vi.advanceTimersByTimeAsync(999)
    expect(settled).toBe(false)

    await vi.advanceTimersByTimeAsync(1)
    expect(settled).toBe(true)

    const { status, branding } = useTenantBranding()
    expect(status.value).toBe('loading')
    expect(branding.value).toBeNull()

    const data = makeBranding()
    resolveFetch(data)
    await vi.advanceTimersByTimeAsync(0)

    expect(status.value).toBe('ready')
    expect(branding.value).toEqual(data)
    expect(applyBrandPalette).toHaveBeenCalledWith({
      primary: '#1D4ED8',
      primaryForeground: '#FFFFFF',
    })
    expect(getTenantBranding).toHaveBeenCalledTimes(1)
  })

  it('respeta options.timeoutMs', async () => {
    getTenantBranding.mockImplementation(() => new Promise(() => {}))

    const { bootstrapTenantBranding } = await freshModule()

    const start = Date.now()
    await bootstrapTenantBranding({ timeoutMs: 5 })
    expect(Date.now() - start).toBeLessThan(500)
  })
})

describe('useTenantBranding — CA-DS-024: tabla de §7.2', () => {
  it('200 con los dos colores: ready, paleta aplicada, caché escrita, favicon de la respuesta', async () => {
    const data = makeBranding({ favicon_url: 'https://cdn.example.com/favicon.png' })
    getTenantBranding.mockResolvedValue(data)

    const { bootstrapTenantBranding, useTenantBranding } = await freshModule()
    await bootstrapTenantBranding()

    const { status, branding } = useTenantBranding()
    expect(status.value).toBe('ready')
    expect(branding.value).toEqual(data)
    expect(applyBrandPalette).toHaveBeenCalledWith({
      primary: '#1D4ED8',
      primaryForeground: '#FFFFFF',
    })
    expect(applyFavicon).toHaveBeenCalledWith('https://cdn.example.com/favicon.png')

    const cached = JSON.parse(localStorage.getItem('plataforma.brand') as string)
    expect(cached).toEqual({ v: 1, primary: '#1D4ED8', primaryForeground: '#FFFFFF' })
  })

  it('200 con un color nulo: ready, paleta null (neutros), caché borrada', async () => {
    localStorage.setItem(
      'plataforma.brand',
      JSON.stringify({ v: 1, primary: '#1D4ED8', primaryForeground: '#FFFFFF' }),
    )
    const data = makeBranding({ color_secondary: null })
    getTenantBranding.mockResolvedValue(data)

    const { bootstrapTenantBranding, useTenantBranding } = await freshModule()
    await bootstrapTenantBranding()

    expect(useTenantBranding().status.value).toBe('ready')
    expect(applyBrandPalette).toHaveBeenCalledWith(null)
    expect(localStorage.getItem('plataforma.brand')).toBeNull()
  })

  it('404: branding null, status not-found, paleta null, caché borrada, favicon por defecto', async () => {
    localStorage.setItem(
      'plataforma.brand',
      JSON.stringify({ v: 1, primary: '#1D4ED8', primaryForeground: '#FFFFFF' }),
    )
    getTenantBranding.mockRejectedValue(new ApiError('not found', 404, null))

    const { bootstrapTenantBranding, useTenantBranding } = await freshModule()
    await bootstrapTenantBranding()

    const { status, branding } = useTenantBranding()
    expect(status.value).toBe('not-found')
    expect(branding.value).toBeNull()
    expect(applyBrandPalette).toHaveBeenCalledWith(null)
    expect(applyFavicon).toHaveBeenCalledWith(null)
    expect(localStorage.getItem('plataforma.brand')).toBeNull()
  })

  it.each([
    ['error de red', new ApiError('network', 0, null)],
    ['429', new ApiError('too many requests', 429, null)],
    ['5xx', new ApiError('server error', 503, null)],
  ])(
    '%s: status unavailable, branding y paleta conservados, caché intacta',
    async (_label, error) => {
      localStorage.setItem(
        'plataforma.brand',
        JSON.stringify({ v: 1, primary: '#1D4ED8', primaryForeground: '#FFFFFF' }),
      )
      getTenantBranding.mockRejectedValue(error)

      const { bootstrapTenantBranding, useTenantBranding } = await freshModule()
      await bootstrapTenantBranding()

      const { status, branding } = useTenantBranding()
      expect(status.value).toBe('unavailable')
      expect(branding.value).toBeNull() // no había estado previo bueno en este módulo fresco
      expect(applyBrandPalette).not.toHaveBeenCalled()
      expect(applyFavicon).not.toHaveBeenCalled()
      expect(localStorage.getItem('plataforma.brand')).not.toBeNull()
    },
  )
})

describe('primeBrandingFromCache — CA-DS-025', () => {
  it('con un JSON válido, aplica la paleta de forma síncrona', async () => {
    localStorage.setItem(
      'plataforma.brand',
      JSON.stringify({ v: 1, primary: '#1D4ED8', primaryForeground: '#FFFFFF' }),
    )
    const { primeBrandingFromCache } = await freshModule()

    primeBrandingFromCache()

    expect(applyBrandPalette).toHaveBeenCalledWith({
      primary: '#1D4ED8',
      primaryForeground: '#FFFFFF',
    })
  })

  it.each([
    ['JSON malformado', '{not json'],
    ['v distinto de 1', JSON.stringify({ v: 2, primary: '#1D4ED8', primaryForeground: '#FFFFFF' })],
    ['color inválido', JSON.stringify({ v: 1, primary: 'azul', primaryForeground: '#FFFFFF' })],
  ])('con %s, borra la clave y no aplica nada', async (_label, raw) => {
    localStorage.setItem('plataforma.brand', raw)
    const { primeBrandingFromCache } = await freshModule()

    primeBrandingFromCache()

    expect(applyBrandPalette).not.toHaveBeenCalled()
    expect(localStorage.getItem('plataforma.brand')).toBeNull()
  })

  it('con un localStorage que lanza al leer, no lanza', async () => {
    const original = Object.getOwnPropertyDescriptor(Storage.prototype, 'getItem')
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('bloqueado')
    })

    const { primeBrandingFromCache } = await freshModule()

    expect(() => primeBrandingFromCache()).not.toThrow()
    expect(applyBrandPalette).not.toHaveBeenCalled()

    if (original) {
      Object.defineProperty(Storage.prototype, 'getItem', original)
    }
  })
})

describe('useTenantBranding().refresh — CA-DS-027', () => {
  it('llamadas concurrentes comparten una sola petición; si falla, no rechaza y conserva ready', async () => {
    getTenantBranding.mockResolvedValueOnce(makeBranding())

    const { bootstrapTenantBranding, useTenantBranding } = await freshModule()
    await bootstrapTenantBranding()

    const { refresh, status, branding } = useTenantBranding()
    expect(status.value).toBe('ready')

    let rejectSecond: (err: unknown) => void = () => {}
    getTenantBranding.mockImplementation(
      () =>
        new Promise((_resolve, reject) => {
          rejectSecond = reject
        }),
    )

    const brandingBefore = branding.value
    const p1 = refresh()
    const p2 = refresh()
    const p3 = refresh()

    expect(getTenantBranding).toHaveBeenCalledTimes(2) // 1 del boot + 1 de las 3 refresh dedupladas

    rejectSecond(new ApiError('network', 0, null))

    await expect(Promise.all([p1, p2, p3])).resolves.toBeDefined()
    expect(status.value).toBe('ready')
    expect(branding.value).toEqual(brandingBefore)
  })
})

describe('useTenantBranding().reportAssetError — CA-DS-028', () => {
  it('la misma URL dos veces lanza un solo refresh()', async () => {
    getTenantBranding.mockResolvedValue(makeBranding())

    const { bootstrapTenantBranding, useTenantBranding } = await freshModule()
    await bootstrapTenantBranding()

    getTenantBranding.mockClear()
    getTenantBranding.mockResolvedValue(makeBranding())

    const { reportAssetError } = useTenantBranding()
    reportAssetError('https://cdn.example.com/logo.png?sig=1')
    reportAssetError('https://cdn.example.com/logo.png?sig=1')

    await Promise.resolve()
    await Promise.resolve()

    expect(getTenantBranding).toHaveBeenCalledTimes(1)
  })
})
