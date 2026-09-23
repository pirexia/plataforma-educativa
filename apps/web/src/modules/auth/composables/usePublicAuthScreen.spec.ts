/**
 * `docs/design-system.md` §13.2, §18 (`CA-DS-046`).
 */
import { mount } from '@vue/test-utils'
import { defineComponent, shallowRef } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { setLocale } from '@/i18n'

vi.mock('@/i18n', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/i18n')>()
  return { ...actual, setLocale: vi.fn() }
})

const getCsrfCookie = vi.fn().mockResolvedValue(undefined)
vi.mock('../api', () => ({
  getCsrfCookie: (...args: unknown[]) => getCsrfCookie(...args),
}))

const getTenantBranding = vi.fn()
vi.mock('@/modules/core/api', () => ({
  getTenantBranding: (...args: unknown[]) => getTenantBranding(...args),
}))

const brandingRef = shallowRef<unknown>(null)
const statusRef = shallowRef<'loading' | 'ready' | 'not-found' | 'unavailable'>('loading')

vi.mock('@/tenant/useTenantBranding', () => ({
  useTenantBranding: () => ({
    branding: brandingRef,
    status: statusRef,
    refresh: vi.fn(),
    reportAssetError: vi.fn(),
  }),
}))

const { usePublicAuthScreen } = await import('./usePublicAuthScreen')

const originalLanguages = Object.getOwnPropertyDescriptor(window.navigator, 'languages')

function mountHost() {
  const Host = defineComponent({
    setup() {
      return usePublicAuthScreen()
    },
    template: '<div />',
  })

  return mount(Host)
}

beforeEach(() => {
  brandingRef.value = null
  statusRef.value = 'loading'
  vi.mocked(setLocale).mockClear()
  getTenantBranding.mockClear()
})

afterEach(() => {
  if (originalLanguages) {
    Object.defineProperty(window.navigator, 'languages', originalLanguages)
  }
})

describe('usePublicAuthScreen — CA-DS-046', () => {
  it('con el contexto en loading que pasa a ready, llama a setLocale una sola vez con el idioma resuelto', async () => {
    Object.defineProperty(window.navigator, 'languages', {
      value: ['de-DE'],
      configurable: true,
    })

    mountHost()

    expect(setLocale).not.toHaveBeenCalled()

    brandingRef.value = {
      default_locale: 'fr',
      active_locales: ['es-ES', 'fr'],
    }
    statusRef.value = 'ready'
    await Promise.resolve()

    expect(setLocale).toHaveBeenCalledTimes(1)
    expect(setLocale).toHaveBeenCalledWith('fr')

    // Una segunda transición (p. ej. tras un refresh()) no debe repetir la
    // aplicación: la vigilancia se desactivó tras la primera (§13.2).
    statusRef.value = 'unavailable'
    statusRef.value = 'ready'
    await Promise.resolve()
    expect(setLocale).toHaveBeenCalledTimes(1)
  })

  it('getTenantBranding no se llama desde el composable (RN-DS-22)', () => {
    mountHost()
    expect(getTenantBranding).not.toHaveBeenCalled()
  })

  it.each(['not-found', 'unavailable'] as const)(
    'con status = %s, brandingFailed es true',
    (status) => {
      statusRef.value = status
      const wrapper = mountHost()

      expect((wrapper.vm as unknown as { brandingFailed: boolean }).brandingFailed).toBe(true)
    },
  )

  it('con status = ready, brandingFailed es false', () => {
    statusRef.value = 'ready'
    const wrapper = mountHost()

    expect((wrapper.vm as unknown as { brandingFailed: boolean }).brandingFailed).toBe(false)
  })
})
