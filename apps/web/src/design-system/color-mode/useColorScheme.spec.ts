/**
 * `docs/design-system.md` §9, §18 (`CA-DS-031` a `CA-DS-035`).
 *
 * El singleton de `useColorMode` vive en el ámbito del módulo: cada test
 * lo aísla con `vi.resetModules()` y una importación dinámica fresca,
 * tras fijar `matchMedia`/`localStorage` — el propio módulo no expone
 * ninguna función de *reset* (`docs/design-system.md` §7.1, mismo
 * criterio para todos los singletons del *design system*).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

interface MockMediaQueryList {
  matches: boolean
  media: string
  addEventListener: (type: string, listener: (event: { matches: boolean }) => void) => void
  removeEventListener: (type: string, listener: (event: { matches: boolean }) => void) => void
  dispatch: (matches: boolean) => void
}

function installMatchMedia(initialMatches: boolean): MockMediaQueryList {
  const listeners = new Set<(event: { matches: boolean }) => void>()

  const mql: MockMediaQueryList = {
    matches: initialMatches,
    media: '(prefers-color-scheme: dark)',
    addEventListener: (_type, listener) => listeners.add(listener),
    removeEventListener: (_type, listener) => listeners.delete(listener),
    dispatch(matches) {
      mql.matches = matches
      for (const listener of listeners) {
        listener({ matches })
      }
    },
  }

  vi.stubGlobal(
    'matchMedia',
    vi.fn().mockImplementation(() => mql),
  )

  return mql
}

async function freshModule() {
  vi.resetModules()

  return import('./useColorScheme')
}

beforeEach(() => {
  localStorage.clear()
  document.documentElement.classList.remove('light', 'dark')
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('useColorScheme — CA-DS-031', () => {
  it('con localStorage vacío y el sistema en oscuro, initColorScheme() aplica .dark, preference system, resolved dark', async () => {
    installMatchMedia(true)
    const { initColorScheme, useColorScheme } = await freshModule()

    initColorScheme()

    expect(document.documentElement.classList.contains('dark')).toBe(true)
    expect(document.documentElement.classList.contains('light')).toBe(false)

    const { preference, resolved } = useColorScheme()
    expect(preference.value).toBe('system')
    expect(resolved.value).toBe('dark')
  })

  it('con el sistema en claro, aplica .light y no .dark', async () => {
    installMatchMedia(false)
    const { initColorScheme, useColorScheme } = await freshModule()

    initColorScheme()

    expect(document.documentElement.classList.contains('light')).toBe(true)
    expect(document.documentElement.classList.contains('dark')).toBe(false)

    const { resolved } = useColorScheme()
    expect(resolved.value).toBe('light')
  })
})

describe('useColorScheme — CA-DS-032', () => {
  it('setPreference persiste y una reinicialización (módulo recargado) la conserva', async () => {
    installMatchMedia(false)
    const mod1 = await freshModule()

    mod1.initColorScheme()
    mod1.useColorScheme().setPreference('dark')
    await nextTick()

    expect(document.documentElement.classList.contains('dark')).toBe(true)
    expect(localStorage.getItem('plataforma.color-mode')).toBe('dark')

    // Nueva "carga" del módulo (nueva pestaña/recarga): misma preferencia.
    installMatchMedia(false)
    const mod2 = await freshModule()
    mod2.initColorScheme()

    expect(document.documentElement.classList.contains('dark')).toBe(true)
    expect(mod2.useColorScheme().preference.value).toBe('dark')
  })
})

describe('useColorScheme — CA-DS-033', () => {
  it('un valor desconocido en localStorage equivale a system', async () => {
    localStorage.setItem('plataforma.color-mode', 'sepia')
    installMatchMedia(false)
    const { initColorScheme, useColorScheme } = await freshModule()

    initColorScheme()

    expect(useColorScheme().preference.value).toBe('system')
  })
})

describe('useColorScheme — CA-DS-034', () => {
  it('con preference system, un cambio de prefers-color-scheme mueve <html> sin llamar a setPreference', async () => {
    const mql = installMatchMedia(false)
    const { initColorScheme, useColorScheme } = await freshModule()

    initColorScheme()
    expect(document.documentElement.classList.contains('light')).toBe(true)

    mql.dispatch(true)
    await nextTick()

    expect(document.documentElement.classList.contains('dark')).toBe(true)
    expect(document.documentElement.classList.contains('light')).toBe(false)
    expect(useColorScheme().preference.value).toBe('system')
  })
})

describe('useColorScheme — CA-DS-035', () => {
  it('cambiar de modo dos veces no añade <style> ni toca --brand-*', async () => {
    installMatchMedia(false)
    const { initColorScheme, useColorScheme } = await freshModule()
    initColorScheme()

    document.documentElement.style.setProperty('--brand-primary', '#1D4ED8')
    const stylesBefore = document.querySelectorAll('style').length

    const { setPreference } = useColorScheme()
    setPreference('dark')
    await nextTick()
    setPreference('light')
    await nextTick()

    expect(document.querySelectorAll('style').length).toBe(stylesBefore)
    expect(document.documentElement.style.getPropertyValue('--brand-primary')).toBe('#1D4ED8')

    document.documentElement.style.removeProperty('--brand-primary')
  })
})

describe('useColorScheme — CA-DS-040 (parcial): un único punto de entrada', () => {
  it('setPreference nunca expone el valor "auto" de VueUse', async () => {
    installMatchMedia(false)
    const { initColorScheme, useColorScheme } = await freshModule()
    initColorScheme()

    const { setPreference, preference } = useColorScheme()
    setPreference('system')

    expect(preference.value).toBe('system')
    expect(preference.value).not.toBe('auto')
  })
})
