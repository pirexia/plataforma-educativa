/**
 * `docs/modulos/REQ-CORE/funcional.md §12.11`
 * (`CA-CORE-120` a `CA-CORE-124`).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { ref } from 'vue'
import { i18n, setLocale } from '@/i18n'
import { ApiError } from '@/api/client'
import AppUserMenu from './AppUserMenu.vue'

const sessionUser = ref<Record<string, unknown> | null>(null)
const updateSessionLocaleMock = vi.fn()
const clearSessionMock = vi.fn()

vi.mock('@/session/useSession', () => ({
  useSession: () => ({ user: sessionUser }),
  updateSessionLocale: (...args: unknown[]) => updateSessionLocaleMock(...args),
  clearSession: () => clearSessionMock(),
}))

const brandingRef = ref<Record<string, unknown> | null>(null)
vi.mock('@/tenant/useTenantBranding', () => ({
  useTenantBranding: () => ({ branding: brandingRef }),
}))

const colorModePreference = ref<'system' | 'light' | 'dark'>('system')
const setPreferenceMock = vi.fn((value: string) => {
  colorModePreference.value = value as 'system' | 'light' | 'dark'
})
vi.mock('@/design-system/color-mode/useColorScheme', () => ({
  useColorScheme: () => ({ preference: colorModePreference, setPreference: setPreferenceMock }),
}))

const logoutMock = vi.fn().mockResolvedValue(undefined)
vi.mock('@/modules/auth/api', () => ({
  logout: () => logoutMock(),
}))

function makeUser(overrides: Record<string, unknown> = {}) {
  return {
    person: { given_name: 'Ana', family_name_1: 'García', locale: 'es-ES' },
    permissions: [],
    ...overrides,
  }
}

function makeRouter(): Router {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: { template: '<div/>' } },
      { path: '/entrar', name: 'login', component: { template: '<div/>' } },
      { path: '/cuenta/contrasena', name: 'password-change', component: { template: '<div/>' } },
      { path: '/cuenta/sesiones', name: 'sessions', component: { template: '<div/>' } },
      { path: '/cuenta/seguridad', name: 'mfa-security', component: { template: '<div/>' } },
    ],
  })
}

async function mountMenu(minimal = false) {
  const router = makeRouter()
  await router.push('/')
  await router.isReady()

  const wrapper = mount(AppUserMenu, {
    props: { minimal },
    global: { plugins: [i18n, router] },
    attachTo: document.body,
  })
  await flushPromises()

  return { wrapper, router }
}

beforeEach(() => {
  setLocale('es')
  sessionUser.value = makeUser()
  brandingRef.value = { active_locales: ['es-ES', 'fr'], default_locale: 'es-ES' }
  colorModePreference.value = 'system'
  updateSessionLocaleMock.mockReset()
  clearSessionMock.mockReset()
  setPreferenceMock.mockClear()
  logoutMock.mockClear()
})

afterEach(() => {
  document.body.innerHTML = ''
})

async function openMenu(wrapper: Awaited<ReturnType<typeof mountMenu>>['wrapper']) {
  await wrapper.get('button').trigger('click')
  await flushPromises()
}

describe('AppUserMenu — CA-CORE-124', () => {
  it('muestra el nombre y enlaces a contraseña, sesiones, seguridad, y cerrar sesión', async () => {
    const { wrapper } = await mountMenu()
    await openMenu(wrapper)

    const text = document.body.textContent ?? ''
    expect(text).toContain('Ana García')
    expect(text).toContain('Contraseña')
    expect(text).toContain('Sesiones abiertas')
    expect(text).toContain('Seguridad de la cuenta')
    expect(text).toContain('Cerrar sesión')
  })

  it('en minimal (régimen bare), solo ofrece cerrar sesión', async () => {
    const { wrapper } = await mountMenu(true)
    await openMenu(wrapper)

    const text = document.body.textContent ?? ''
    expect(text).not.toContain('Contraseña')
    expect(text).not.toContain('Sesiones abiertas')
    expect(text).toContain('Cerrar sesión')
  })
})

describe('AppUserMenu — selector de idioma (CA-CORE-120/121)', () => {
  it('ofrece exactamente los active_locales del centro', async () => {
    const { wrapper } = await mountMenu()
    await openMenu(wrapper)

    const text = document.body.textContent ?? ''
    expect(text).toContain('Español')
    expect(text).toContain('Français')
    expect(text).not.toContain('English')
    expect(text).not.toContain('Deutsch')
  })

  it('al elegir un idioma, llama a updateSessionLocale con ese dominio', async () => {
    updateSessionLocaleMock.mockResolvedValue(
      makeUser({ person: { given_name: 'Ana', family_name_1: 'García', locale: 'fr' } }),
    )
    const { wrapper } = await mountMenu()
    await openMenu(wrapper)

    const frenchOption = [...document.querySelectorAll('[role="menuitemradio"]')].find((el) =>
      el.textContent?.includes('Français'),
    )
    expect(frenchOption).toBeTruthy()
    frenchOption?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()

    expect(updateSessionLocaleMock).toHaveBeenCalledWith('fr')
  })

  it('con 422 core.validation.locale_not_active, mantiene el idioma y muestra el mensaje del servidor', async () => {
    updateSessionLocaleMock.mockRejectedValue(
      new ApiError('422', 422, {
        type: 'urn:pge:error:validation',
        detail: 'El centro ha retirado el francés.',
      }),
    )
    const { wrapper } = await mountMenu()
    await openMenu(wrapper)

    const frenchOption = [...document.querySelectorAll('[role="menuitemradio"]')].find((el) =>
      el.textContent?.includes('Français'),
    )
    frenchOption?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()

    const alert = document.body.querySelector('[role="alert"]')
    expect(alert).toBeTruthy()
    expect(alert?.textContent).toContain('El centro ha retirado el francés.')
  })
})

describe('AppUserMenu — modo de color (CA-CORE-123)', () => {
  it('es un grupo de tres opciones con semántica de radio; elegir oscuro llama a setPreference', async () => {
    const { wrapper } = await mountMenu()
    await openMenu(wrapper)

    const radios = [...document.querySelectorAll('[role="menuitemradio"]')]
    expect(radios.length).toBeGreaterThanOrEqual(3)

    const darkOption = radios.find((el) => el.textContent?.includes('Oscuro'))
    darkOption?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()

    expect(setPreferenceMock).toHaveBeenCalledWith('dark')
  })
})

describe('AppUserMenu — cerrar sesión (RN-CORE-27)', () => {
  it('vacía la sesión y navega a /entrar aunque logout() falle', async () => {
    logoutMock.mockRejectedValue(new Error('network'))
    const { wrapper, router } = await mountMenu()
    await openMenu(wrapper)

    const logoutItem = [...document.querySelectorAll('[role="menuitem"]')].find((el) =>
      el.textContent?.includes('Cerrar sesión'),
    )
    logoutItem?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()

    expect(clearSessionMock).toHaveBeenCalledTimes(1)
    expect(router.currentRoute.value.name).toBe('login')
  })
})
