/**
 * `docs/design-system.md` §13.1, §18 (`CA-DS-045`).
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { shallowRef } from 'vue'

const reportAssetError = vi.fn()
const brandingRef = shallowRef({
  name: 'Centro de ejemplo',
  color_primary: '#1D4ED8',
  color_secondary: '#FFFFFF',
  logo_url: 'https://cdn.example.com/logo.png',
  favicon_url: null,
  login_background_url: 'https://cdn.example.com/fondo.jpg',
  default_locale: 'es-ES',
  active_locales: ['es-ES'],
})

vi.mock('@/tenant/useTenantBranding', () => ({
  useTenantBranding: () => ({
    branding: brandingRef,
    status: shallowRef('ready'),
    refresh: vi.fn(),
    reportAssetError,
  }),
}))

const { default: PublicAuthShell } = await import('./PublicAuthShell.vue')

describe('PublicAuthShell — CA-DS-045', () => {
  it('no declara la prop "branding"', () => {
    const declaredProps = Object.keys(
      (PublicAuthShell as { props?: Record<string, unknown> }).props ?? {},
    )
    expect(declaredProps).not.toContain('branding')
  })

  it('pinta nombre, logo y fondo del contexto de la capa B, sin --primary/--primary-foreground en la tarjeta', () => {
    const wrapper = mount(PublicAuthShell, { slots: { default: 'contenido' } })

    expect(wrapper.text()).toContain('Centro de ejemplo')

    const img = wrapper.find('img')
    expect(img.attributes('src')).toBe('https://cdn.example.com/logo.png')

    const outer = wrapper.element as HTMLElement
    expect(outer.style.backgroundImage).toContain('https://cdn.example.com/fondo.jpg')

    // La tarjeta es el segundo div (la que hoy liga `:style="brandVars"` en la versión anterior).
    const card = wrapper.findAll('div')[1] as { attributes: (name: string) => string | undefined }
    expect(card.attributes('style') ?? '').not.toContain('--primary')
    expect(card.attributes('style') ?? '').not.toContain('--primary-foreground')
  })

  it('reporta el error del logo a la capa B (§7.4)', async () => {
    const wrapper = mount(PublicAuthShell, { slots: { default: 'contenido' } })
    await wrapper.find('img').trigger('error')

    expect(reportAssetError).toHaveBeenCalledWith('https://cdn.example.com/logo.png')
  })
})
