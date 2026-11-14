/**
 * `docs/modulos/REQ-CORE/capa-visual.md` §5.4-§5.7, §12.1 (`CA-UI-010`,
 * `-011`, `-013`, `-014`, `-021`). Los criterios que necesitan estilos
 * calculados (`CA-UI-009`, `-012`, `-015` a `-018`, `-020`) están en
 * `e2e/public-auth-shell.spec.ts`.
 */
import { mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { shallowRef } from 'vue'
import { i18n, setLocale } from '@/i18n'

const reportAssetError = vi.fn()
const baseBranding = {
  name: 'Centro de ejemplo',
  color_primary: '#1D4ED8',
  color_secondary: '#FFFFFF',
  logo_url: 'https://cdn.example.com/logo.png',
  favicon_url: null,
  login_background_url: 'https://cdn.example.com/fondo.jpg' as string | null,
  default_locale: 'es-ES',
  active_locales: ['es-ES'] as string[],
}
const brandingRef = shallowRef<typeof baseBranding | null>({ ...baseBranding })
const statusRef = shallowRef<'loading' | 'ready' | 'not-found' | 'unavailable'>('ready')

vi.mock('@/tenant/useTenantBranding', () => ({
  useTenantBranding: () => ({
    branding: brandingRef,
    status: statusRef,
    refresh: vi.fn(),
    reportAssetError,
  }),
}))

const { default: PublicAuthShell } = await import('./PublicAuthShell.vue')

beforeEach(() => {
  brandingRef.value = { ...baseBranding }
  statusRef.value = 'ready'
  reportAssetError.mockClear()
  window.localStorage.clear()
  setLocale('es')
})

function mountShell() {
  return mount(PublicAuthShell, {
    slots: { default: '<h1>Título</h1><form><input /></form>' },
    global: { plugins: [i18n] },
  })
}

describe('PublicAuthShell — CA-UI-021 (reescribe CA-DS-045; reescritura de CA-UI-022)', () => {
  it('no declara la prop "branding"', () => {
    const declaredProps = Object.keys(
      (PublicAuthShell as { props?: Record<string, unknown> }).props ?? {},
    )
    expect(declaredProps).not.toContain('branding')
  })

  it('pinta nombre, logo e imagen del contexto, sin --primary/--primary-foreground en style', () => {
    const wrapper = mountShell()

    expect(wrapper.text()).toContain('Centro de ejemplo')
    expect(wrapper.find('img').attributes('src')).toBe('https://cdn.example.com/logo.png')
    expect(
      (wrapper.find('[data-testid="auth-center-image"]').element as HTMLElement).style
        .backgroundImage,
    ).toContain('https://cdn.example.com/fondo.jpg')

    for (const el of wrapper.findAll('*')) {
      const style = el.attributes('style') ?? ''
      expect(style).not.toContain('--primary')
      expect(style).not.toContain('--primary-foreground')
    }
  })

  it('un fallo de carga del logo llama una vez a reportAssetError (§7.4)', async () => {
    const wrapper = mountShell()
    await wrapper.find('img').trigger('error')

    expect(reportAssetError).toHaveBeenCalledTimes(1)
    expect(reportAssetError).toHaveBeenCalledWith('https://cdn.example.com/logo.png')
  })
})

describe('PublicAuthShell — CA-UI-010: estructura (RN-DS-26, RN-DS-27)', () => {
  it('aside con nombre antes que el panel con main; logo y nombre, slot y pie, en ese orden', () => {
    const wrapper = mountShell()
    const root = wrapper.element as HTMLElement

    const aside = root.querySelector('aside')
    const main = root.querySelector('main')
    expect(aside?.getAttribute('aria-label')).toBeTruthy()
    expect(main).not.toBeNull()
    expect(aside!.compareDocumentPosition(main!) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()

    const header = main!.querySelector('header')!
    const h1 = main!.querySelector('h1')!
    const footer = main!.querySelector('footer')!
    expect(header.compareDocumentPosition(h1) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    expect(h1.compareDocumentPosition(footer) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
  })

  it('exactamente un h1 (el del slot); el nombre de la plataforma está en el pie y no en un form', () => {
    const wrapper = mountShell()
    const root = wrapper.element as HTMLElement

    expect(root.querySelectorAll('h1')).toHaveLength(1)
    expect(root.querySelector('aside h1, aside h2')).toBeNull()

    const footer = root.querySelector('footer')!
    expect(footer.textContent).toContain('Plataforma de Gestión Educativa')
    expect(root.querySelector('form')!.textContent).not.toContain('Plataforma de Gestión Educativa')
  })

  it('la región visual no contiene elementos enfocables', () => {
    const aside = (mountShell().element as HTMLElement).querySelector('aside')!

    expect(
      aside.querySelectorAll('a[href], button, input, select, textarea, [tabindex]'),
    ).toHaveLength(0)
  })
})

describe('PublicAuthShell — CA-UI-011: estados de la región visual (RN-UI-10)', () => {
  it('ready con imagen: imagen por style y la ilustración debajo', () => {
    const wrapper = mountShell()

    expect(wrapper.find('[data-testid="auth-illustration"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="auth-center-image"]').exists()).toBe(true)
  })

  it('ready sin imagen: solo la ilustración', () => {
    brandingRef.value = { ...baseBranding, login_background_url: null }
    const wrapper = mountShell()

    expect(wrapper.find('[data-testid="auth-illustration"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="auth-center-image"]').exists()).toBe(false)
  })

  it('loading: ni imagen ni ilustración, superficie neutra', () => {
    statusRef.value = 'loading'
    brandingRef.value = null
    const wrapper = mountShell()

    expect(wrapper.find('[data-testid="auth-illustration"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="auth-center-image"]').exists()).toBe(false)
    expect(wrapper.find('aside').classes()).toContain('bg-muted')
  })

  it.each(['unavailable', 'not-found'] as const)(
    '%s: ilustración y bienvenida genérica, sin nombre ni logo',
    (status) => {
      statusRef.value = status
      brandingRef.value = null
      const wrapper = mountShell()

      expect(wrapper.find('[data-testid="auth-illustration"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="auth-welcome-title"]').exists()).toBe(true)
      expect(wrapper.find('img').exists()).toBe(false)
      expect(wrapper.find('header').exists()).toBe(false)
    },
  )

  it('no crea elementos style', () => {
    expect(mountShell().find('style').exists()).toBe(false)
  })
})

describe('PublicAuthShell — CA-UI-013: bienvenida genérica', () => {
  it('título y línea salen de las claves auth.shell.*; la línea no se muestra por debajo de md', () => {
    const wrapper = mountShell()

    expect(wrapper.find('[data-testid="auth-welcome-title"]').text()).toBe('Te damos la bienvenida')
    const text = wrapper.find('[data-testid="auth-welcome-text"]')
    expect(text.text()).toBe('Accede con tu cuenta para consultar la información de tu centro.')
    expect(text.classes()).toContain('hidden')
    expect(text.classes()).toContain('md:block')
    // Sin antetítulo: el título y la línea son los únicos textos de la región.
    expect(wrapper.find('aside').findAll('p')).toHaveLength(2)
  })
})

describe('PublicAuthShell — CA-UI-014: selector de idioma', () => {
  it('con dos idiomas activos ofrece solo esos dos, con lang y nombre accesible', () => {
    brandingRef.value = { ...baseBranding, active_locales: ['es-ES', 'fr'] }
    const wrapper = mountShell()
    const buttons = wrapper.findAll('footer nav button')

    expect(buttons).toHaveLength(2)
    expect(buttons.map((b) => b.attributes('lang'))).toEqual(['es', 'fr'])
    expect(buttons.map((b) => b.attributes('aria-label'))).toEqual(['Español', 'Français'])
    expect(buttons[0]!.attributes('aria-current')).toBe('true')
    expect(buttons[1]!.attributes('aria-current')).toBeUndefined()
    // No se distingue solo por color (WCAG 1.4.1).
    expect(buttons[0]!.classes()).toEqual(expect.arrayContaining(['font-semibold', 'underline']))
  })

  it('al elegir fr cambian los textos, el lang del documento y plataforma.locale', async () => {
    brandingRef.value = { ...baseBranding, active_locales: ['es-ES', 'fr'] }
    const wrapper = mountShell()

    await wrapper.findAll('footer nav button')[1]!.trigger('click')

    expect(window.localStorage.getItem('plataforma.locale')).toBe('fr')
    expect(document.documentElement.lang).toBe('fr')
    expect(wrapper.find('[data-testid="auth-welcome-title"]').text()).toBe('Bienvenue')
    expect(wrapper.findAll('footer nav button')[1]!.attributes('aria-current')).toBe('true')
  })

  it('con un solo idioma activo no se pinta el selector', () => {
    expect(mountShell().find('footer nav').exists()).toBe(false)
  })
})

describe('Ilustración por defecto — CA-UI-019', () => {
  const svg = readFileSync(resolve(__dirname, '../../../../public/auth/illustration.svg'), 'utf8')

  it('es un SVG de ≤ 20 KB', () => {
    expect(svg.trimStart().startsWith('<svg')).toBe(true)
    expect(Buffer.byteLength(svg)).toBeLessThanOrEqual(20 * 1024)
  })

  it.each(['<image', '<text', '<script', '<foreignObject', 'href=', 'url(', 'http://', 'https://'])(
    'no contiene %s (salvo el espacio de nombres xmlns)',
    (forbidden) => {
      const withoutNamespace = svg.replace('xmlns="http://www.w3.org/2000/svg"', '')
      expect(withoutNamespace).not.toContain(forbidden)
    },
  )

  it('el registro de recursos gráficos recoge la ilustración con autoría y licencia', () => {
    const doc = readFileSync(resolve(__dirname, '../../../../../../docs/design-system.md'), 'utf8')
    const entry = doc.slice(doc.indexOf('Registro de recursos gráficos'))

    expect(entry).toContain('public/auth/illustration.svg')
    expect(entry).toMatch(/licencia/i)
    expect(entry).toMatch(/autor/i)
  })
})
