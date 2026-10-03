/**
 * `docs/modulos/REQ-CORE/funcional.md §14.10b`, `§14.18` (1.9e): módulos
 * contratados en solo lectura — `CA-CORE-267` (`RN-CORE-87`, `OPEN-CORE-45` = A) y
 * `CA-CORE-268` (`RN-CORE-63`: recarga al cambiar de idioma).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { i18n, setLocale } from '@/i18n'

const listModules = vi.fn()
const updateModuleSubscriptionSettings = vi.fn()

vi.mock('../api', () => ({
  listModules: (...args: unknown[]) => listModules(...args),
  updateModuleSubscriptionSettings: (...args: unknown[]) =>
    updateModuleSubscriptionSettings(...args),
}))

const { default: ModulesView } = await import('./ModulesView.vue')

function mod(code: string, name: string, overrides: Record<string, unknown> = {}) {
  return {
    public_id: `S-${code}`,
    module_code: code,
    name,
    phase: '1',
    enabled: true,
    enabled_at: '2026-08-19T09:00:00Z',
    disabled_at: null,
    settings: { secreto: 'no se muestra' },
    ...overrides,
  }
}

const wrappers: VueWrapper[] = []

async function mountView(): Promise<VueWrapper> {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/administracion/modulos', name: 'core-modules', component: ModulesView }],
  })

  await router.push('/administracion/modulos')
  await router.isReady()

  const wrapper = mount(ModulesView, {
    global: { plugins: [i18n, router] },
    attachTo: document.body,
  }) as VueWrapper

  wrappers.push(wrapper)
  await flushPromises()

  return wrapper
}

beforeEach(() => {
  setLocale('es')
  listModules.mockReset().mockResolvedValue({
    data: [
      mod('acad', 'Estructura académica'),
      mod('asis', 'Asistencia', { enabled_at: '2026-09-01T09:00:00Z' }),
      mod('fact', 'Facturación', { enabled: false, public_id: null, enabled_at: null }),
    ],
  })
  updateModuleSubscriptionSettings.mockReset()
  window.localStorage.clear()
  Object.defineProperty(window, 'matchMedia', {
    configurable: true,
    writable: true,
    value: (query: string) => ({
      matches: false,
      media: query,
      addEventListener: () => undefined,
      removeEventListener: () => undefined,
    }),
  })
})

afterEach(() => {
  while (wrappers.length > 0) {
    wrappers.pop()!.unmount()
  }
  document.body.innerHTML = ''
})

describe('CA-CORE-267 (RN-CORE-87, ADR-045, OPEN-CORE-45 = A): módulos contratados de solo lectura', () => {
  it('muestra nombre, estado y fecha de alta formateada; solo las filas con enabled: true', async () => {
    const wrapper = await mountView()

    expect(wrapper.find('caption').text()).toBe('Módulos contratados del centro')
    expect(wrapper.findAll('thead th').map((th) => th.text())).toEqual([
      'Módulo',
      'Estado',
      'Fecha de alta',
    ])

    const rows = wrapper
      .findAll('tbody tr')
      .map((tr) => tr.findAll('th, td').map((cell) => cell.text()))

    expect(rows).toHaveLength(2)
    expect(rows[0]![0]).toBe('Estructura académica')
    expect(rows[0]![1]).toBe('Contratado')
    expect(rows[0]![2]).toBe(
      new Intl.DateTimeFormat('es', { dateStyle: 'medium' }).format(
        new Date('2026-08-19T09:00:00Z'),
      ),
    )
    expect(rows[1]![0]).toBe('Asistencia')
    expect(document.body.textContent).not.toContain('Facturación')
    expect(wrapper.find('tbody th').attributes('scope')).toBe('row')
  })

  it('ni control de edición, ni settings, ni fase; nunca llama a PATCH /module-subscriptions aunque se tenga modulo.actualizar', async () => {
    const wrapper = await mountView()

    expect(wrapper.find('tbody button, tbody a, tbody input, form').exists()).toBe(false)
    expect(document.body.textContent).not.toContain('no se muestra')
    expect(document.body.textContent).not.toMatch(/Fase|phase/i)
    expect(updateModuleSubscriptionSettings).not.toHaveBeenCalled()
  })

  it('una respuesta sin meta se pinta como una sola página: «Página 1 de 1» y sin nada que paginar', async () => {
    const wrapper = await mountView()

    // El componente de 1.9 pinta siempre su pie en modo `page` (misma técnica que RN-CORE-74): con una
    // única página queda «Página 1 de 1» y los cuatro botones de navegación deshabilitados.
    expect(wrapper.get('[data-slot="data-table-page-indicator"]').text()).toBe('Página 1 de 1')
    expect(
      wrapper
        .findAll('nav button[aria-label]')
        .map((button) => button.attributes('disabled') !== undefined),
    ).toEqual([true, true, true, true])
    expect(wrapper.find('input[type="search"]').exists()).toBe(false)
    expect(wrapper.find('thead button').exists()).toBe(false)
  })

  it('sin ningún módulo contratado, el estado vacío', async () => {
    listModules.mockResolvedValue({ data: [mod('fact', 'Facturación', { enabled: false })] })

    const wrapper = await mountView()

    expect(wrapper.text()).toContain('No hay módulos contratados')
  })
})

describe('CA-CORE-268 (RN-CORE-63): recarga al cambiar de idioma', () => {
  it('en es → en se vuelve a pedir una vez y se muestran los nombres del servidor', async () => {
    const wrapper = await mountView()

    expect(listModules).toHaveBeenCalledTimes(1)

    listModules.mockClear()
    listModules.mockResolvedValue({ data: [mod('acad', 'Academic structure')] })

    setLocale('en')
    await flushPromises()

    expect(listModules).toHaveBeenCalledTimes(1)
    expect(wrapper.find('tbody').text()).toContain('Academic structure')
    expect(wrapper.find('caption').text()).toBe('Contracted modules of the school')
  })
})
