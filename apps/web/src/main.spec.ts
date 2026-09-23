/**
 * `docs/design-system.md` §8, §18 (`CA-DS-030`).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const calls: string[] = []
let resolveBootstrap: () => void = () => {}

vi.mock('./design-system/color-mode/useColorScheme', () => ({
  initColorScheme: () => {
    calls.push('initColorScheme')
  },
}))

vi.mock('./tenant/useTenantBranding', () => ({
  primeBrandingFromCache: () => {
    calls.push('primeBrandingFromCache')
  },
  bootstrapTenantBranding: () => {
    calls.push('bootstrapTenantBranding')
    return new Promise<void>((resolve) => {
      resolveBootstrap = () => {
        calls.push('bootstrapTenantBranding:resolved')
        resolve()
      }
    })
  },
}))

interface Chainable {
  use: () => Chainable
  mount: typeof mount
}

const mount = vi.fn()
const chainable: Chainable = { use: () => chainable, mount }
const use = vi.fn(() => chainable)
const createApp = vi.fn(() => ({ use }))

vi.mock('vue', async (importOriginal) => {
  const actual = await importOriginal<typeof import('vue')>()
  return { ...actual, createApp }
})

vi.mock('./App.vue', () => ({ default: {} }))
vi.mock('./router', () => ({ default: {} }))
vi.mock('./i18n', () => ({
  i18n: { global: { locale: { value: 'es' } } },
}))

beforeEach(() => {
  calls.length = 0
  mount.mockClear()
  use.mockClear()
  createApp.mockClear()
  vi.resetModules()
})

describe('main.ts — CA-DS-030', () => {
  it('llama initColorScheme, primeBrandingFromCache y bootstrapTenantBranding en ese orden, y monta solo tras resolver el bootstrap', async () => {
    await import('./main')

    expect(calls).toEqual(['initColorScheme', 'primeBrandingFromCache', 'bootstrapTenantBranding'])
    expect(mount).not.toHaveBeenCalled()

    resolveBootstrap()
    await Promise.resolve()
    await Promise.resolve()

    expect(calls).toEqual([
      'initColorScheme',
      'primeBrandingFromCache',
      'bootstrapTenantBranding',
      'bootstrapTenantBranding:resolved',
    ])
    expect(createApp).toHaveBeenCalledTimes(1)
    expect(mount).toHaveBeenCalledWith('#app')
  })
})
