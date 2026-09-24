/**
 * `docs/modulos/REQ-CORE/funcional.md §12.4`, `§12.11`
 * (`CA-CORE-110`, `CA-CORE-111`, `CA-CORE-112`, `CA-CORE-114`).
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import { ShieldCheck } from '@lucide/vue'
import { i18n, setLocale } from '@/i18n'
import HomeView from './HomeView.vue'

const reportAssetError = vi.fn()
const sessionUser = ref<Record<string, unknown> | null>(null)
const brandingRef = ref<Record<string, unknown> | null>(null)
let shortcutsFixture: unknown[] = []

vi.mock('@/session/useSession', () => ({
  useSession: () => ({ user: sessionUser }),
}))

vi.mock('@/tenant/useTenantBranding', () => ({
  useTenantBranding: () => ({ branding: brandingRef, reportAssetError }),
}))

vi.mock('@/navigation/registry', () => ({
  visibleShortcuts: () => shortcutsFixture,
}))

vi.mock('@/router', () => ({ default: {} }))

function makeUser(overrides: Record<string, unknown> = {}) {
  return {
    person: { given_name: 'Ana' },
    permissions: [],
    mfa: undefined,
    ...overrides,
  }
}

beforeEach(() => {
  setLocale('es')
  reportAssetError.mockReset()
  sessionUser.value = makeUser()
  brandingRef.value = { name: 'Centro de ejemplo', logo_url: null }
  shortcutsFixture = []
})

describe('HomeView — bienvenida (CA-CORE-110)', () => {
  it('el saludo contiene el nombre, aparece el nombre del centro y el logo con alt', async () => {
    brandingRef.value = { name: 'Centro de ejemplo', logo_url: 'https://cdn.example.com/logo.png' }

    const wrapper = mount(HomeView, {
      global: { plugins: [i18n], stubs: { RouterLink: { template: '<a><slot /></a>' } } },
    })

    expect(wrapper.text()).toContain('Ana')
    expect(wrapper.text()).toContain('Centro de ejemplo')

    const img = wrapper.find('img')
    expect(img.exists()).toBe(true)
    expect(img.attributes('alt')).toBe('Centro de ejemplo')
  })

  it('sin logo_url, no se pinta ningún <img>', () => {
    brandingRef.value = { name: 'Centro de ejemplo', logo_url: null }

    const wrapper = mount(HomeView, {
      global: { plugins: [i18n], stubs: { RouterLink: { template: '<a><slot /></a>' } } },
    })

    expect(wrapper.find('img').exists()).toBe(false)
  })

  it('cuando el logotipo falla al cargar, se llama a reportAssetError con su URL', async () => {
    brandingRef.value = { name: 'Centro de ejemplo', logo_url: 'https://cdn.example.com/logo.png' }

    const wrapper = mount(HomeView, {
      global: { plugins: [i18n], stubs: { RouterLink: { template: '<a><slot /></a>' } } },
    })

    await wrapper.find('img').trigger('error')

    expect(reportAssetError).toHaveBeenCalledWith('https://cdn.example.com/logo.png')
  })
})

describe('HomeView — estado de la cuenta (CA-CORE-111)', () => {
  it('con obligated true y enrolled false, aparece el aviso con los días y un enlace a la seguridad', () => {
    sessionUser.value = makeUser({
      mfa: {
        obligated: true,
        enrolled: false,
        days_remaining: 3,
        enforced: true,
        grace_deadline_at: null,
      },
    })

    const wrapper = mount(HomeView, {
      global: { plugins: [i18n], stubs: { RouterLink: { template: '<a><slot /></a>' } } },
    })

    expect(wrapper.text()).toContain('3')
  })

  it.each([
    { obligated: false, enrolled: false },
    { obligated: true, enrolled: true },
  ])('con %j, el bloque no existe en el documento', (mfa) => {
    sessionUser.value = makeUser({
      mfa: { ...mfa, days_remaining: 3, enforced: false, grace_deadline_at: null },
    })

    const wrapper = mount(HomeView, {
      global: { plugins: [i18n], stubs: { RouterLink: { template: '<a><slot /></a>' } } },
    })

    expect(wrapper.find('#home-mfa-title').exists()).toBe(false)
  })

  it('sin bloque mfa en /me, el aviso no existe', () => {
    sessionUser.value = makeUser({ mfa: undefined })

    const wrapper = mount(HomeView, {
      global: { plugins: [i18n], stubs: { RouterLink: { template: '<a><slot /></a>' } } },
    })

    expect(wrapper.find('#home-mfa-title').exists()).toBe(false)
  })
})

describe('HomeView — accesos directos (CA-CORE-112)', () => {
  it('sin ningún acceso directo permitido, se pinta el estado vacío traducido', () => {
    shortcutsFixture = []

    const wrapper = mount(HomeView, {
      global: { plugins: [i18n], stubs: { RouterLink: { template: '<a><slot /></a>' } } },
    })

    expect(wrapper.text()).toContain('Sin accesos directos')
  })

  it('con accesos directos permitidos, se listan con su icono y su etiqueta', () => {
    shortcutsFixture = [
      {
        id: 'auth.mfaSecurity',
        route: 'mfa-security',
        labelKey: 'auth.mfa.security.title',
        icon: ShieldCheck,
        section: 'cuenta',
      },
    ]

    const wrapper = mount(HomeView, {
      global: { plugins: [i18n], stubs: { RouterLink: { template: '<a><slot /></a>' } } },
    })

    expect(wrapper.text()).toContain('Seguridad de la cuenta')
  })
})

describe('HomeView — issue #86 (CA-CORE-114)', () => {
  it('ya no pide /health ni existe ninguna referencia a ese endpoint', () => {
    const source = readFileSync(resolve(process.cwd(), 'src/views/HomeView.vue'), 'utf-8')
    expect(source).not.toContain('/health')
  })
})
