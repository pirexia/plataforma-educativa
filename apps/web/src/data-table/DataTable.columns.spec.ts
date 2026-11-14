/**
 * `docs/modulos/REQ-CORE/funcional.md §13.8`, `§13.18`: columnas
 * configurables (`CA-CORE-173` a `-175`, `RN-CORE-43`) y filas solo en
 * memoria (`CA-CORE-195`, `RN-CORE-50`).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { Component } from 'vue'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { i18n, setLocale } from '@/i18n'
import DataTable from './components/DataTable.vue'
import { readHiddenColumns } from './columnPreferences'
import type { DataTableColumn, DataTableFetcher } from './types'

interface Person {
  public_id: string
  name: string
  email: string
  phone: string
}

const STORAGE_KEY = 'plataforma.table.fixture.people'

const columns: DataTableColumn<Person>[] = [
  { id: 'name', headerKey: 'fixture.name', rowHeader: true, card: 'title' },
  { id: 'email', headerKey: 'fixture.email', card: 'field' },
  { id: 'phone', headerKey: 'fixture.phone', card: 'field', defaultHidden: true },
  { id: 'actions', headerKey: 'fixture.actions', hideable: false, card: 'actions' },
]

const rows: Person[] = [
  { public_id: 'r1', name: 'Fila Distinguible Uno', email: 'uno@example.com', phone: '600000001' },
  { public_id: 'r2', name: 'Fila Distinguible Dos', email: 'dos@example.com', phone: '600000002' },
]

const fetcher: DataTableFetcher<Person> = async () => ({
  data: rows,
  meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 },
})

const wrappers: VueWrapper[] = []

function mountTable(props: Record<string, unknown> = {}) {
  const wrapper = mount(DataTable as unknown as Component, {
    props: {
      tableId: 'fixture.people',
      caption: 'Personas',
      columns,
      mode: 'page',
      fetcher,
      emptyTitle: 'Vacío',
      ...props,
    },
    global: { plugins: [i18n] },
    attachTo: document.body,
  }) as VueWrapper

  wrappers.push(wrapper)

  return wrapper
}

async function click(el: Element): Promise<void> {
  el.dispatchEvent(new MouseEvent('click', { bubbles: true }))
  await flushPromises()
}

function button(label: string): HTMLButtonElement {
  const found = [...document.body.querySelectorAll('button')].find(
    (candidate) => candidate.textContent?.trim() === label,
  )

  if (!found) {
    throw new Error(`No hay botón «${label}»`)
  }

  return found as HTMLButtonElement
}

function menuItem(text: string): HTMLElement {
  const found = [
    ...document.body.querySelectorAll('[role="menuitemcheckbox"], [role="menuitem"]'),
  ].find((el) => el.textContent?.includes(text))

  if (!found) {
    throw new Error(`No hay elemento de menú «${text}»`)
  }

  return found as HTMLElement
}

function headerTexts(wrapper: VueWrapper): string[] {
  return wrapper.findAll('thead th').map((th) => th.text())
}

beforeEach(() => {
  setLocale('es')
  for (const locale of ['es', 'en', 'de', 'fr'] as const) {
    i18n.global.mergeLocaleMessage(locale, {
      fixture: {
        name: 'Nombre',
        email: 'Correo',
        phone: 'Teléfono',
        actions: 'Acciones',
      },
    })
  }
  window.localStorage.clear()
})

afterEach(() => {
  while (wrappers.length > 0) {
    wrappers.pop()!.unmount()
  }
  document.body.innerHTML = ''
  vi.restoreAllMocks()
  vi.useRealTimers()
})

describe('CA-CORE-173 (RN-CORE-43): ocultar una columna', () => {
  it('desaparece de la tabla y la clave pasa a ser exactamente {"v":1,"hidden":[…]}', async () => {
    const wrapper = mountTable()
    await flushPromises()

    expect(headerTexts(wrapper)).toContain('Correo')

    await click(button('Columnas'))
    await click(menuItem('Correo'))

    expect(headerTexts(wrapper)).not.toContain('Correo')
    // `phone` es defaultHidden: sigue oculta, y la lista incluye las dos.
    expect(window.localStorage.getItem(STORAGE_KEY)).toBe('{"v":1,"hidden":["email","phone"]}')
  })

  it('la columna rowHeader y la de acciones no aparecen en el menú como ocultables', async () => {
    mountTable()
    await flushPromises()
    await click(button('Columnas'))

    const labels = [...document.body.querySelectorAll('[role="menuitemcheckbox"]')].map((el) =>
      el.textContent?.trim(),
    )

    expect(labels).toEqual(['Correo', 'Teléfono'])
  })

  it('también desaparece de la vista de tarjetas', async () => {
    Object.defineProperty(window, 'matchMedia', {
      configurable: true,
      writable: true,
      value: () => ({
        matches: true,
        addEventListener: () => undefined,
        removeEventListener: () => undefined,
      }),
    })
    const wrapper = mountTable()
    await flushPromises()
    expect(wrapper.find('dt').text()).toBe('Correo')

    await click(button('Columnas'))
    await click(menuItem('Correo'))

    expect(wrapper.findAll('dt').map((dt) => dt.text())).not.toContain('Correo')

    Reflect.deleteProperty(window, 'matchMedia')
  })
})

describe('CA-CORE-174 (RN-CORE-43): configuración guardada inutilizable', () => {
  it('un valor ilegible se descarta: configuración por defecto y se borra la clave', async () => {
    window.localStorage.setItem(STORAGE_KEY, '{no es json')
    const wrapper = mountTable()
    await flushPromises()

    expect(headerTexts(wrapper)).toEqual(['Nombre', 'Correo', 'Acciones'])
    expect(window.localStorage.getItem(STORAGE_KEY)).toBeNull()
  })

  it('con v distinto de 1 se descarta y se borra la clave', async () => {
    window.localStorage.setItem(STORAGE_KEY, '{"v":2,"hidden":["email"]}')
    const wrapper = mountTable()
    await flushPromises()

    expect(headerTexts(wrapper)).toContain('Correo')
    expect(window.localStorage.getItem(STORAGE_KEY)).toBeNull()
  })

  it('con un id inexistente junto a uno válido, oculta solo la columna válida', async () => {
    window.localStorage.setItem(STORAGE_KEY, '{"v":1,"hidden":["ya_no_existe","email"]}')
    const wrapper = mountTable()
    await flushPromises()

    // La lista guardada es la configuración completa: `email` oculta; `phone` (oculta por defecto) ya no.
    expect(headerTexts(wrapper)).toEqual(['Nombre', 'Teléfono', 'Acciones'])
  })

  it('con localStorage que lanza en toda operación, la tabla se pinta con la configuración por defecto', async () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('bloqueado')
    })
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('bloqueado')
    })
    vi.spyOn(Storage.prototype, 'removeItem').mockImplementation(() => {
      throw new Error('bloqueado')
    })

    const wrapper = mountTable()
    await flushPromises()
    expect(headerTexts(wrapper)).toEqual(['Nombre', 'Correo', 'Acciones'])

    // Ocultar y restablecer tampoco lanzan.
    await click(button('Columnas'))
    await click(menuItem('Correo'))
    expect(headerTexts(wrapper)).not.toContain('Correo')
    await click(menuItem('Restablecer columnas'))
    expect(headerTexts(wrapper)).toContain('Correo')
  })

  it('readHiddenColumns devuelve null sin clave y la lista si el valor es válido', () => {
    expect(readHiddenColumns('fixture.people')).toBeNull()
    window.localStorage.setItem(STORAGE_KEY, '{"v":1,"hidden":["email"]}')
    expect(readHiddenColumns('fixture.people')).toEqual(['email'])
  })
})

describe('CA-CORE-175: restablecer columnas', () => {
  it('vuelve la configuración por defecto y elimina la clave', async () => {
    window.localStorage.setItem(STORAGE_KEY, '{"v":1,"hidden":["email","phone"]}')
    const wrapper = mountTable()
    await flushPromises()
    expect(headerTexts(wrapper)).toEqual(['Nombre', 'Acciones'])

    await click(button('Columnas'))
    await click(menuItem('Restablecer columnas'))

    expect(headerTexts(wrapper)).toEqual(['Nombre', 'Correo', 'Acciones'])
    expect(window.localStorage.getItem(STORAGE_KEY)).toBeNull()
  })
})

describe('CA-CORE-195 (RN-CORE-50): las filas solo viven en memoria', () => {
  it('cargar, paginar, filtrar y desmontar solo escribe la clave de columnas, sin datos de fila ni texto de búsqueda', async () => {
    vi.useFakeTimers()
    const setItem = vi.spyOn(Storage.prototype, 'setItem')
    const indexedDbOpen = vi.fn()
    Object.defineProperty(window, 'indexedDB', {
      configurable: true,
      value: { open: indexedDbOpen },
    })

    const wrapper = mountTable({ searchable: true })
    await flushPromises()

    await wrapper.get('input[type="search"]').setValue('López Buscado')
    await vi.advanceTimersByTimeAsync(400)
    await flushPromises()

    vi.useRealTimers()
    await click(button('Columnas'))
    await click(menuItem('Correo'))

    wrapper.unmount()
    wrappers.pop()

    expect(setItem.mock.calls.map((call) => call[0])).toEqual([STORAGE_KEY])
    expect(indexedDbOpen).not.toHaveBeenCalled()

    const everything = [
      ...Object.values(window.localStorage),
      ...Object.values(window.sessionStorage),
    ].join('|')

    expect(everything).not.toContain('Fila Distinguible')
    expect(everything).not.toContain('uno@example.com')
    expect(everything).not.toContain('López Buscado')
    expect(window.sessionStorage.length).toBe(0)

    Reflect.deleteProperty(window, 'indexedDB')
  })
})
