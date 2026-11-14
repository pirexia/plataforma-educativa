/**
 * `docs/design-system.md` §13.2, §18 (`CA-DS-046`).
 */
import { mount } from '@vue/test-utils'
import { defineComponent, shallowRef } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { applyLocale, setLocale } from '@/i18n'

vi.mock('@/i18n', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/i18n')>()
  return { ...actual, applyLocale: vi.fn(), setLocale: vi.fn() }
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
  window.localStorage.clear()
  brandingRef.value = null
  statusRef.value = 'loading'
  vi.mocked(applyLocale).mockClear()
  vi.mocked(setLocale).mockClear()
  getTenantBranding.mockClear()
})

afterEach(() => {
  if (originalLanguages) {
    Object.defineProperty(window.navigator, 'languages', originalLanguages)
  }
})

describe('usePublicAuthScreen — CA-DS-046', () => {
  it('con el contexto en loading que pasa a ready, llama a applyLocale una sola vez con el idioma resuelto', async () => {
    Object.defineProperty(window.navigator, 'languages', {
      value: ['de-DE'],
      configurable: true,
    })

    mountHost()

    expect(applyLocale).not.toHaveBeenCalled()

    brandingRef.value = {
      default_locale: 'fr',
      active_locales: ['es-ES', 'fr'],
    }
    statusRef.value = 'ready'
    await Promise.resolve()

    expect(applyLocale).toHaveBeenCalledTimes(1)
    expect(applyLocale).toHaveBeenCalledWith('fr')

    // Una segunda transición (p. ej. tras un refresh()) no debe repetir la
    // aplicación: la vigilancia se desactivó tras la primera (§13.2).
    statusRef.value = 'unavailable'
    statusRef.value = 'ready'
    await Promise.resolve()
    expect(applyLocale).toHaveBeenCalledTimes(1)
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

describe('usePublicAuthScreen — RN-UI-15 (amplía CA-AUTH-063), CA-UI-014 (reescritura de CA-UI-022)', () => {
  function setNavigatorLanguages(languages: string[]): void {
    Object.defineProperty(window.navigator, 'languages', { value: languages, configurable: true })
  }

  function readyWith(activeLocales: string[], defaultLocale = 'es-ES'): void {
    brandingRef.value = { default_locale: defaultLocale, active_locales: activeLocales }
    statusRef.value = 'ready'
  }

  it('la elección guardada, si es un idioma activo, prevalece sobre el navegador', () => {
    window.localStorage.setItem('plataforma.locale', 'fr')
    setNavigatorLanguages(['de-DE'])
    readyWith(['es-ES', 'de', 'fr'])

    mountHost()

    expect(applyLocale).toHaveBeenCalledWith('fr')
  })

  it('la elección guardada no activa (de) cae a navegador ∩ activos (CA-AUTH-063)', () => {
    window.localStorage.setItem('plataforma.locale', 'de')
    setNavigatorLanguages(['en-GB'])
    readyWith(['es-ES', 'en'])

    mountHost()

    expect(applyLocale).toHaveBeenCalledWith('en')
  })

  it('la elección guardada no activa y sin coincidencia del navegador cae al idioma por defecto', () => {
    window.localStorage.setItem('plataforma.locale', 'de')
    setNavigatorLanguages(['it-IT'])
    readyWith(['es-ES', 'fr'], 'fr')

    mountHost()

    expect(applyLocale).toHaveBeenCalledWith('fr')
  })

  it('sin elección guardada el resultado es el de siempre: navegador ∩ activos', () => {
    setNavigatorLanguages(['fr-FR', 'es-ES'])
    readyWith(['es-ES', 'fr'])

    mountHost()

    expect(applyLocale).toHaveBeenCalledWith('fr')
  })

  it('un valor guardado no soportado se ignora', () => {
    window.localStorage.setItem('plataforma.locale', 'xx')
    setNavigatorLanguages(['en-US'])
    readyWith(['es-ES', 'en'])

    mountHost()

    expect(applyLocale).toHaveBeenCalledWith('en')
  })

  it('la resolución automática solo aplica el idioma: no llama a setLocale ni escribe plataforma.locale (#674)', () => {
    setNavigatorLanguages(['fr-FR'])
    readyWith(['es-ES', 'fr'])

    mountHost()

    expect(applyLocale).toHaveBeenCalledWith('fr')
    expect(setLocale).not.toHaveBeenCalled()
    expect(window.localStorage.getItem('plataforma.locale')).toBeNull()
  })
})
