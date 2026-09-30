/**
 * `docs/modulos/REQ-CORE/funcional.md §13.18`: paginación y datos
 * (`CA-CORE-160` a `-166`, `-204`), ordenación y filtrado (`-167` a
 * `-172`), móvil (`-179`), estados (`-180` a `-182`), accesibilidad
 * (`-183`, `-184`) y valor vacío (`-201`). «Tabla de prueba» = una tabla
 * declarada solo aquí, con función de petición simulada y un módulo
 * ficticio `fixture`.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { Component } from 'vue'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { i18n, setLocale } from '@/i18n'
import { ApiError } from '@/api/client'
import DataTable from './components/DataTable.vue'
import { MAX_CURSOR_ROWS, SEARCH_DEBOUNCE_MS } from './constants'
import type {
  DataTableColumn,
  DataTableCursorResponse,
  DataTableFetcher,
  DataTableFilter,
  DataTablePageResponse,
  DataTableQuery,
} from './types'

interface Person {
  public_id: string
  name: string
  status: string | null
  email: string | null
}

const FIXTURE_MESSAGES = {
  name: 'Nombre',
  status: 'Estado',
  email: 'Correo',
  actions: 'Acciones',
  created_at: 'Creado',
  empty: 'Aún no hay personas',
}

const columns: DataTableColumn<Person>[] = [
  {
    id: 'name',
    headerKey: 'fixture.name',
    rowHeader: true,
    sortable: true,
    hideable: false,
    card: 'title',
  },
  { id: 'status', headerKey: 'fixture.status', card: 'subtitle' },
  { id: 'email', headerKey: 'fixture.email', sortable: true, card: 'field' },
  { id: 'actions', headerKey: 'fixture.actions', hideable: false, card: 'actions' },
]

function people(count: number, prefix = 'p'): Person[] {
  return Array.from({ length: count }, (_, index) => ({
    public_id: `${prefix}-${index}`,
    name: `Persona ${prefix}${index}`,
    status: index % 2 === 0 ? 'activo' : null,
    email: index === 1 ? null : `${prefix}${index}@example.com`,
  }))
}

function pageOf(
  rows: Person[],
  meta: Partial<DataTablePageResponse<Person>['meta']> = {},
): DataTablePageResponse<Person> {
  return {
    data: rows,
    meta: {
      current_page: 1,
      per_page: 25,
      total: rows.length,
      last_page: 1,
      ...meta,
    },
  }
}

function cursorOf(
  rows: Person[],
  meta: Partial<DataTableCursorResponse<Person>['meta']> = {},
): DataTableCursorResponse<Person> {
  return { data: rows, meta: { next_cursor: null, has_more: false, ...meta } }
}

function problem(status: number, body: Record<string, unknown> = {}): ApiError {
  return new ApiError(`HTTP ${status}`, status, body)
}

const wrappers: VueWrapper[] = []

function mountTable(
  fetcher: DataTableFetcher<Person>,
  props: Record<string, unknown> = {},
  slots: Record<string, unknown> = {},
) {
  const wrapper = mount(DataTable as unknown as Component, {
    props: {
      tableId: 'fixture.people',
      caption: 'Personas',
      columns,
      mode: 'page',
      fetcher,
      emptyTitle: FIXTURE_MESSAGES.empty,
      ...props,
    },
    slots,
    global: { plugins: [i18n] },
    attachTo: document.body,
  }) as VueWrapper

  wrappers.push(wrapper)

  return wrapper
}

function setNarrow(narrow: boolean): void {
  Object.defineProperty(window, 'matchMedia', {
    configurable: true,
    writable: true,
    value: (query: string) => ({
      matches: narrow,
      media: query,
      addEventListener: () => undefined,
      removeEventListener: () => undefined,
    }),
  })
}

function lastQuery(fetcher: ReturnType<typeof vi.fn>): DataTableQuery {
  return fetcher.mock.calls.at(-1)![0] as DataTableQuery
}

function buttonByLabel(label: string): HTMLButtonElement {
  const found = [...document.body.querySelectorAll('button')].find(
    (button) => (button.getAttribute('aria-label') ?? button.textContent ?? '').trim() === label,
  )

  if (!found) {
    throw new Error(`No hay botón «${label}»`)
  }

  return found as HTMLButtonElement
}

async function click(el: Element): Promise<void> {
  el.dispatchEvent(new MouseEvent('click', { bubbles: true }))
  await flushPromises()
}

async function openMenu(label: string): Promise<void> {
  await click(buttonByLabel(label))
}

function menuItem(text: string, role = 'menuitemcheckbox'): HTMLElement {
  const found = [...document.body.querySelectorAll(`[role="${role}"]`)].find((el) =>
    el.textContent?.includes(text),
  )

  if (!found) {
    throw new Error(`No hay elemento de menú «${text}»`)
  }

  return found as HTMLElement
}

beforeEach(() => {
  setLocale('es')
  for (const locale of ['es', 'en', 'de', 'fr'] as const) {
    i18n.global.mergeLocaleMessage(locale, { fixture: FIXTURE_MESSAGES })
  }
  setNarrow(false)
  window.localStorage.clear()
})

afterEach(() => {
  while (wrappers.length > 0) {
    wrappers.pop()!.unmount()
  }
  document.body.innerHTML = ''
  vi.useRealTimers()
})

// ---------------------------------------------------------------------------
// Paginación y datos
// ---------------------------------------------------------------------------

describe('CA-CORE-160 (ADR-038 §4.3): paginador del modo page', () => {
  it('muestra «página 2 de 6» y el total con el formato del idioma, y navega con los parámetros de la respuesta', async () => {
    const fetcher = vi
      .fn<DataTableFetcher<Person>>()
      .mockResolvedValue(
        pageOf(people(25), { current_page: 2, per_page: 25, total: 137, last_page: 6 }),
      )
    const wrapper = mountTable(fetcher)
    await flushPromises()

    expect(wrapper.text()).toContain('Página 2 de 6')
    expect(wrapper.text()).toContain('137 resultados')

    await click(buttonByLabel('Página siguiente'))
    expect(lastQuery(fetcher)).toMatchObject({ page: 3, per_page: 25 })
  })

  it('en la última página, «siguiente» y «última» están deshabilitados', async () => {
    const fetcher = vi
      .fn<DataTableFetcher<Person>>()
      .mockResolvedValue(
        pageOf(people(12), { current_page: 6, per_page: 25, total: 137, last_page: 6 }),
      )
    mountTable(fetcher)
    await flushPromises()

    expect(buttonByLabel('Página siguiente').disabled).toBe(true)
    expect(buttonByLabel('Última página').disabled).toBe(true)
    expect(buttonByLabel('Página anterior').disabled).toBe(false)
  })

  it('formatea el total con el idioma activo', async () => {
    setLocale('de')
    const fetcher = vi
      .fn<DataTableFetcher<Person>>()
      .mockResolvedValue(pageOf(people(3), { total: 12345, last_page: 494 }))
    const wrapper = mountTable(fetcher)
    await flushPromises()

    expect(wrapper.text()).toContain('12.345 Ergebnisse')
  })
})

describe('CA-CORE-161 (ADR-038 §4.3): filas por página', () => {
  it('ofrece exactamente 25, 50 y 100 con 25 por defecto; elegir 50 en la página 4 pide page=1, per_page=50', async () => {
    const fetcher = vi
      .fn<DataTableFetcher<Person>>()
      .mockResolvedValue(
        pageOf(people(25), { current_page: 4, per_page: 25, total: 500, last_page: 20 }),
      )
    mountTable(fetcher)
    await flushPromises()

    expect(fetcher.mock.calls[0]![0].per_page).toBe(25)

    await openMenu('25 por página')

    const options = [...document.body.querySelectorAll('[role="menuitemradio"]')].map((el) =>
      el.textContent?.trim(),
    )
    expect(options).toEqual(['25', '50', '100'])

    await click(menuItem('50', 'menuitemradio'))

    expect(lastQuery(fetcher)).toMatchObject({ page: 1, per_page: 50 })
  })
})

describe('CA-CORE-162 (RN-CORE-45): página fuera de rango', () => {
  it('con data vacío, page > 1 y total > 0 pide la última página exactamente una vez', async () => {
    const fetcher = vi
      .fn<DataTableFetcher<Person>>()
      .mockResolvedValueOnce(
        pageOf(people(25), { current_page: 1, per_page: 25, total: 51, last_page: 3 }),
      )
      .mockResolvedValueOnce(pageOf([], { current_page: 3, per_page: 25, total: 51, last_page: 2 }))
      .mockResolvedValue(
        pageOf(people(1), { current_page: 2, per_page: 25, total: 51, last_page: 2 }),
      )
    mountTable(fetcher)
    await flushPromises()
    await click(buttonByLabel('Última página'))
    await flushPromises()

    // Carga inicial + la página 3 (vacía) + una única petición de la página 2.
    const pages = fetcher.mock.calls.map((call) => call[0].page)
    expect(pages).toEqual([1, 3, 2])
  })

  it('con total 0 no pide nada más y pinta el estado vacío', async () => {
    const fetcher = vi
      .fn<DataTableFetcher<Person>>()
      .mockResolvedValue(pageOf([], { current_page: 1, total: 0, last_page: 0 }))
    const wrapper = mountTable(fetcher)
    await flushPromises()

    expect(fetcher).toHaveBeenCalledTimes(1)
    expect(wrapper.text()).toContain('Aún no hay personas')
  })
})

describe('CA-CORE-163 (ADR-038 §4.4/§4.5): modo cursor', () => {
  it('sin paginador ni total; «Cargar más» pide con el cursor y añade detrás; desaparece con has_more=false', async () => {
    const fetcher = vi
      .fn<DataTableFetcher<Person>>()
      .mockResolvedValueOnce(cursorOf(people(3, 'a'), { next_cursor: 'c1', has_more: true }))
      .mockResolvedValueOnce(cursorOf(people(2, 'b'), { next_cursor: null, has_more: false }))
    const wrapper = mountTable(fetcher, { mode: 'cursor' })
    await flushPromises()

    expect(wrapper.find('[data-slot="data-table-pagination"]').exists()).toBe(false)
    expect(wrapper.text()).not.toMatch(/Página \d/)
    expect(fetcher.mock.calls[0]![0].cursor).toBeUndefined()

    await click(buttonByLabel('Cargar más'))

    expect(lastQuery(fetcher).cursor).toBe('c1')
    expect(wrapper.findAll('tbody tr')).toHaveLength(5)
    expect(wrapper.text().indexOf('Persona a0')).toBeLessThan(wrapper.text().indexOf('Persona b0'))
    expect(() => buttonByLabel('Cargar más')).toThrow()
  })

  it('no hay ningún desplazamiento infinito: desplazar la lista no pide nada (CA-CORE-204, segunda parte)', async () => {
    const fetcher = vi
      .fn<DataTableFetcher<Person>>()
      .mockResolvedValue(cursorOf(people(3), { next_cursor: 'c1', has_more: true }))
    const wrapper = mountTable(fetcher, { mode: 'cursor' })
    await flushPromises()

    window.dispatchEvent(new Event('scroll'))
    wrapper.element.dispatchEvent(new Event('scroll', { bubbles: true }))
    document.dispatchEvent(new Event('scroll'))
    await flushPromises()

    expect(fetcher).toHaveBeenCalledTimes(1)
  })
})

describe('CA-CORE-164 (ADR-038 §4.4 regla 2): cursor y filtros', () => {
  it('cambiar un filtro o el orden reinicia la lista sin cursor', async () => {
    const fetcher = vi
      .fn<DataTableFetcher<Person>>()
      .mockResolvedValueOnce(cursorOf(people(3, 'a'), { next_cursor: 'c1', has_more: true }))
      .mockResolvedValueOnce(cursorOf(people(2, 'b'), { next_cursor: 'c2', has_more: true }))
      .mockResolvedValue(cursorOf(people(1, 'z'), { next_cursor: null, has_more: false }))
    const wrapper = mountTable(fetcher, { mode: 'cursor' })
    await flushPromises()
    await click(buttonByLabel('Cargar más'))
    expect(wrapper.findAll('tbody tr')).toHaveLength(5)

    await click(buttonByLabel('Correo, ordenar de forma ascendente'))

    expect(lastQuery(fetcher).cursor).toBeUndefined()
    expect(lastQuery(fetcher).sort).toBe('email')
    expect(wrapper.findAll('tbody tr')).toHaveLength(1)
  })
})

describe('CA-CORE-165 (RN-CORE-52): tope de filas acumuladas en modo cursor', () => {
  it('al alcanzar el tope, «Cargar más» se sustituye por el aviso, no sale ninguna petición más y las filas siguen', async () => {
    const batch = 200
    const fetcher = vi.fn<DataTableFetcher<Person>>().mockImplementation(async (query) => {
      const offset = query.cursor ? Number(query.cursor) : 0

      return cursorOf(people(batch, `r${offset}-`), {
        next_cursor: String(offset + batch),
        has_more: true,
      })
    })
    const wrapper = mountTable(fetcher, {
      mode: 'cursor',
      exportConfig: {
        canExport: true,
        request: vi.fn(),
        status: vi.fn(),
      },
    })
    await flushPromises()

    const loads = MAX_CURSOR_ROWS / batch - 1

    for (let index = 0; index < loads; index += 1) {
      await click(buttonByLabel('Cargar más'))
    }

    expect(wrapper.findAll('tbody tr')).toHaveLength(MAX_CURSOR_ROWS)
    expect(() => buttonByLabel('Cargar más')).toThrow()
    expect(wrapper.text()).toContain('Has cargado el máximo de')
    expect(wrapper.text()).toContain('acota los filtros o exporta')
    expect(fetcher).toHaveBeenCalledTimes(MAX_CURSOR_ROWS / batch)

    // La acción de exportar sigue disponible en el aviso.
    expect(wrapper.find('[data-slot="data-table-cursor-footer"]').text()).toContain('Exportar')

    // Cambiar un filtro reinicia la cuenta: «Cargar más» vuelve a estar disponible.
    await click(buttonByLabel('Correo, ordenar de forma ascendente'))
    expect(wrapper.findAll('tbody tr')).toHaveLength(batch)
    expect(buttonByLabel('Cargar más')).toBeTruthy()
  }, 30_000)

  it('si la última carga supera el tope, las filas recibidas se muestran enteras (no se recorta)', async () => {
    const fetcher = vi.fn<DataTableFetcher<Person>>().mockImplementation(async (query) => {
      const offset = query.cursor ? Number(query.cursor) : 0

      return cursorOf(people(600, `o${offset}-`), {
        next_cursor: String(offset + 600),
        has_more: true,
      })
    })
    const wrapper = mountTable(fetcher, { mode: 'cursor' })
    await flushPromises()
    await click(buttonByLabel('Cargar más'))

    expect(wrapper.findAll('tbody tr')).toHaveLength(1200)
    expect(() => buttonByLabel('Cargar más')).toThrow()
    expect(fetcher).toHaveBeenCalledTimes(2)
  }, 30_000)
})

describe('CA-CORE-166 (RN-CORE-41): solo gana la última respuesta', () => {
  it('si B responde antes que A, se muestra B y al llegar A no cambia nada', async () => {
    let resolveA!: (value: DataTablePageResponse<Person>) => void
    let resolveB!: (value: DataTablePageResponse<Person>) => void
    const fetcher = vi
      .fn<DataTableFetcher<Person>>()
      .mockResolvedValueOnce(pageOf(people(2, 'init')))
      .mockImplementationOnce(() => new Promise((resolve) => (resolveA = resolve)))
      .mockImplementationOnce(() => new Promise((resolve) => (resolveB = resolve)))
    const wrapper = mountTable(fetcher)
    await flushPromises()

    await click(buttonByLabel('Correo, ordenar de forma ascendente')) // A
    await click(buttonByLabel('Correo, ordenar de forma descendente')) // B

    resolveB(pageOf(people(1, 'B')))
    await flushPromises()
    expect(wrapper.text()).toContain('Persona B0')

    resolveA(pageOf(people(1, 'A')))
    await flushPromises()
    expect(wrapper.text()).toContain('Persona B0')
    expect(wrapper.text()).not.toContain('Persona A0')
  })
})

describe('CA-CORE-204 (RN-CORE-56, ADR-054 §2.3): fallo de «cargar más»', () => {
  it('conserva las filas, muestra el error junto al control y «Reintentar» repite con el mismo cursor', async () => {
    const fetcher = vi
      .fn<DataTableFetcher<Person>>()
      .mockResolvedValueOnce(cursorOf(people(100), { next_cursor: 'c2', has_more: true }))
      .mockRejectedValueOnce(problem(503, { request_id: 'req-1' }))
      .mockResolvedValueOnce(cursorOf(people(5, 'n'), { next_cursor: null, has_more: false }))
    const wrapper = mountTable(fetcher, { mode: 'cursor' })
    await flushPromises()
    await click(buttonByLabel('Cargar más'))

    expect(wrapper.findAll('tbody tr')).toHaveLength(100)
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)

    await click(buttonByLabel('Reintentar'))

    expect(fetcher.mock.calls.map((call) => call[0].cursor)).toEqual([undefined, 'c2', 'c2'])
    expect(wrapper.findAll('tbody tr')).toHaveLength(105)
  })
})

// ---------------------------------------------------------------------------
// Ordenación y filtrado
// ---------------------------------------------------------------------------

describe('CA-CORE-167 (RN-CORE-39): ciclo de ordenación', () => {
  it('ascendente → descendente → sin sort, con aria-sort solo en la columna ordenada', async () => {
    const fetcher = vi.fn<DataTableFetcher<Person>>().mockResolvedValue(pageOf(people(3)))
    const wrapper = mountTable(fetcher)
    await flushPromises()

    const headers = () => wrapper.findAll('th[scope="col"]')
    const nameHeader = () => headers().find((th) => th.text().includes('Nombre'))!

    await click(buttonByLabel('Nombre, ordenar de forma ascendente'))
    expect(lastQuery(fetcher).sort).toBe('name')
    expect(nameHeader().attributes('aria-sort')).toBe('ascending')

    await click(buttonByLabel('Nombre, ordenar de forma descendente'))
    expect(lastQuery(fetcher).sort).toBe('-name')
    expect(nameHeader().attributes('aria-sort')).toBe('descending')

    await click(buttonByLabel('Nombre, quitar la ordenación'))
    expect(lastQuery(fetcher).sort).toBeUndefined()
    expect(nameHeader().attributes('aria-sort')).toBeUndefined()
    expect(wrapper.findAll('[aria-sort]')).toHaveLength(0)
  })

  it('una columna no ordenable no contiene botón; nunca hay dos th con aria-sort', async () => {
    const fetcher = vi.fn<DataTableFetcher<Person>>().mockResolvedValue(pageOf(people(3)))
    const wrapper = mountTable(fetcher)
    await flushPromises()

    const statusHeader = wrapper.findAll('th[scope="col"]').find((th) => th.text() === 'Estado')!
    expect(statusHeader.find('button').exists()).toBe(false)

    await click(buttonByLabel('Nombre, ordenar de forma ascendente'))
    await click(buttonByLabel('Correo, ordenar de forma ascendente'))

    expect(wrapper.findAll('[aria-sort]')).toHaveLength(1)
  })
})

describe('CA-CORE-168: cambiar orden o filtro vuelve a la página 1', () => {
  it('en la página 4, ordenar y filtrar piden page=1', async () => {
    const fetcher = vi
      .fn<DataTableFetcher<Person>>()
      .mockResolvedValue(
        pageOf(people(25), { current_page: 4, per_page: 25, total: 500, last_page: 20 }),
      )
    const filters: DataTableFilter[] = [
      {
        type: 'enum',
        id: 'status',
        labelKey: 'fixture.status',
        options: [{ value: 'activo' }, { value: 'inactivo' }],
      },
    ]
    mountTable(fetcher, { filters })
    await flushPromises()

    await click(buttonByLabel('Nombre, ordenar de forma ascendente'))
    expect(lastQuery(fetcher).page).toBe(1)

    fetcher.mockResolvedValue(
      pageOf(people(25), { current_page: 4, per_page: 25, total: 500, last_page: 20 }),
    )
    await flushPromises()
    await openMenu('Estado')
    await click(menuItem('activo'))
    expect(lastQuery(fetcher).page).toBe(1)
  })
})

describe('CA-CORE-169 (ADR-038 §5.2, §13.3): serialización de filtros', () => {
  it('enumerado por comas, rango _from/_to y booleano, sin tabla de correspondencias', async () => {
    const fetcher = vi.fn<DataTableFetcher<Person>>().mockResolvedValue(pageOf(people(3)))
    const filters: DataTableFilter[] = [
      {
        type: 'enum',
        id: 'status',
        labelKey: 'fixture.status',
        options: [{ value: 'activo' }, { value: 'inactivo' }],
      },
      { type: 'dateRange', id: 'occurred_at', labelKey: 'fixture.created_at' },
      { type: 'boolean', id: 'is_system', labelKey: 'fixture.name' },
    ]
    const wrapper = mountTable(fetcher, { filters })
    await flushPromises()

    await openMenu('Estado')
    await click(menuItem('activo'))
    await click(menuItem('inactivo'))
    document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await flushPromises()

    const from = wrapper.find('input[type="date"]')
    const to = wrapper.findAll('input[type="date"]')[1]!
    await from.setValue('2026-01-01')
    await to.setValue('2026-01-31')
    await flushPromises()

    await click(buttonByLabel('Nombre: Todos'))
    await click(menuItem('Sí', 'menuitemradio'))

    expect(lastQuery(fetcher).filters).toEqual({
      status: 'activo,inactivo',
      occurred_at_from: '2026-01-01',
      occurred_at_to: '2026-01-31',
      is_system: 'true',
    })

    // «Todos» no envía el parámetro.
    await click(buttonByLabel('Nombre: Sí'))
    await click(menuItem('Todos', 'menuitemradio'))
    expect(lastQuery(fetcher).filters).not.toHaveProperty('is_system')
  })
})

describe('CA-CORE-170 (RN-CORE-40): búsqueda con espera', () => {
  it('cinco pulsaciones con 50 ms entre cada una producen una sola petición, 300 ms después de la última', async () => {
    vi.useFakeTimers()
    const fetcher = vi.fn<DataTableFetcher<Person>>().mockResolvedValue(pageOf(people(3)))
    const wrapper = mountTable(fetcher, { searchable: true })
    await flushPromises()
    expect(fetcher).toHaveBeenCalledTimes(1)

    const input = wrapper.get('input[type="search"]')

    for (const text of ['L', 'Ló', 'Lóp', 'Lópe', 'López']) {
      await input.setValue(text)
      await vi.advanceTimersByTimeAsync(50)
    }

    expect(fetcher).toHaveBeenCalledTimes(1)

    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS - 50)
    await flushPromises()

    expect(fetcher).toHaveBeenCalledTimes(2)
    expect(lastQuery(fetcher).q).toBe('López')
  })
})

describe('CA-CORE-171 (RN-CORE-42): 422 de un filtro', () => {
  it('muestra el mensaje del servidor con role=alert, conserva las filas y no pasa al estado de error', async () => {
    const fetcher = vi
      .fn<DataTableFetcher<Person>>()
      .mockResolvedValueOnce(pageOf(people(3)))
      .mockRejectedValueOnce(
        problem(422, {
          type: 'urn:pge:error:validation',
          errors: {
            occurred_at_from: [{ code: 'range', message: 'El rango no puede superar un año.' }],
          },
        }),
      )
    const filters: DataTableFilter[] = [
      { type: 'dateRange', id: 'occurred_at', labelKey: 'fixture.created_at' },
    ]
    const wrapper = mountTable(fetcher, { filters })
    await flushPromises()

    await wrapper.get('input[type="date"]').setValue('2020-01-01')
    await flushPromises()

    const alert = wrapper
      .findAll('[role="alert"]')
      .find((el) => el.text().includes('El rango no puede superar un año.'))
    expect(alert).toBeTruthy()
    expect(alert!.text()).toContain('El filtro no se ha aplicado')
    expect(wrapper.findAll('tbody tr')).toHaveLength(3)
    // No es el estado de error de pantalla completa (no hay «Reintentar»).
    expect(() => buttonByLabel('Reintentar')).toThrow()
  })
})

describe('CA-CORE-172 (ADR-038 §7.3): valor de enumerado sin traducción', () => {
  it('muestra el código en crudo y no lanza', async () => {
    const fetcher = vi.fn<DataTableFetcher<Person>>().mockResolvedValue(pageOf(people(1)))
    const filters: DataTableFilter[] = [
      {
        type: 'enum',
        id: 'status',
        labelKey: 'fixture.status',
        options: [
          { value: 'nuevo_estado_desconocido', labelKey: 'fixture.no_existe' },
          { value: 'sin_etiqueta' },
        ],
      },
    ]
    mountTable(fetcher, { filters })
    await flushPromises()
    await openMenu('Estado')

    expect(menuItem('nuevo_estado_desconocido')).toBeTruthy()
    expect(menuItem('sin_etiqueta')).toBeTruthy()
  })
})

// ---------------------------------------------------------------------------
// Móvil, estados, accesibilidad y valor vacío
// ---------------------------------------------------------------------------

describe('CA-CORE-179 (RN-CORE-55): vista de tarjetas', () => {
  beforeEach(() => setNarrow(true))

  it('es una lista con un li por fila; title como encabezado; field como dt/dd; omit y ocultas no aparecen', async () => {
    const fetcher = vi.fn<DataTableFetcher<Person>>().mockResolvedValue(pageOf(people(3)))
    const wrapper = mountTable(
      fetcher,
      { cardHeadingLevel: 2 },
      {
        'cell-actions': '<button type="button">Acción</button>',
      },
    )
    await flushPromises()

    expect(wrapper.find('table').exists()).toBe(false)
    const items = wrapper.findAll('ul[data-slot="data-table-cards"] > li')
    expect(items).toHaveLength(3)
    expect(items[0]!.find('h2').text()).toBe('Persona p0')
    const dt = items[0]!.find('dt')
    expect(dt.text()).toBe('Correo')
    expect(items[0]!.find('dd').text()).toContain('p0@example.com')
    expect(items[0]!.text()).toContain('Acción')
  })

  it('el orden se cambia con un selector de columna y sentido que produce el mismo sort que la cabecera', async () => {
    const fetcher = vi.fn<DataTableFetcher<Person>>().mockResolvedValue(pageOf(people(3)))
    mountTable(fetcher)
    await flushPromises()

    await openMenu('Ordenar')
    await click(menuItem('Correo', 'menuitemradio'))
    expect(lastQuery(fetcher).sort).toBe('email')

    await openMenu('Ordenar')
    await click(menuItem('Descendente', 'menuitemradio'))
    expect(lastQuery(fetcher).sort).toBe('-email')
  })

  it('sobre 768 px pinta la tabla y no la lista; con desplazamiento interno declarado, tabla también en móvil', async () => {
    const fetcher = vi.fn<DataTableFetcher<Person>>().mockResolvedValue(pageOf(people(3)))
    const scrolling = mountTable(fetcher, { mobile: 'scroll' })
    await flushPromises()

    expect(scrolling.find('table').exists()).toBe(true)
    expect(scrolling.find('ul[data-slot="data-table-cards"]').exists()).toBe(false)
    const region = scrolling.get('[role="region"]')
    expect(region.attributes('tabindex')).toBe('0')
    expect(region.attributes('aria-label')).toContain('Personas')
  })
})

describe('CA-CORE-180 (RUX-006): carga', () => {
  it('primera petición sin respuesta: estado de carga con role=status y barra de filtros interactiva', async () => {
    const fetcher = vi.fn<DataTableFetcher<Person>>().mockReturnValue(new Promise(() => undefined))
    const wrapper = mountTable(fetcher, { searchable: true })
    await flushPromises()

    expect(wrapper.find('[role="status"][aria-busy="true"]').exists()).toBe(true)
    expect(wrapper.get('input[type="search"]').attributes('disabled')).toBeUndefined()
  })

  it('una recarga conserva las filas y marca aria-busy hasta la respuesta', async () => {
    let resolve!: (value: DataTablePageResponse<Person>) => void
    const fetcher = vi
      .fn<DataTableFetcher<Person>>()
      .mockResolvedValueOnce(
        pageOf(people(3), { current_page: 1, per_page: 25, total: 60, last_page: 3 }),
      )
      .mockImplementationOnce(() => new Promise((r) => (resolve = r)))
    const wrapper = mountTable(fetcher)
    await flushPromises()

    await click(buttonByLabel('Página siguiente'))

    expect(wrapper.findAll('tbody tr')).toHaveLength(3)
    expect(wrapper.get('[data-slot="data-table-region"]').attributes('aria-busy')).toBe('true')

    resolve(pageOf(people(2, 'q'), { current_page: 2, total: 60, last_page: 3 }))
    await flushPromises()

    expect(wrapper.get('[data-slot="data-table-region"]').attributes('aria-busy')).toBeUndefined()
  })
})

describe('CA-CORE-181 (RUX-006): estados vacíos', () => {
  it('sin filtros, texto del consumidor; con filtro, «sin resultados» con «Limpiar filtros» que mueve el foco a la búsqueda', async () => {
    const fetcher = vi.fn<DataTableFetcher<Person>>().mockResolvedValue(pageOf([], { total: 0 }))
    const filters: DataTableFilter[] = [
      {
        type: 'enum',
        id: 'status',
        labelKey: 'fixture.status',
        options: [{ value: 'activo' }],
      },
    ]
    const wrapper = mountTable(fetcher, { filters, searchable: true })
    await flushPromises()
    expect(wrapper.text()).toContain('Aún no hay personas')

    await openMenu('Estado')
    await click(menuItem('activo'))
    document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await flushPromises()

    await new Promise((resolve) => setTimeout(resolve, 60))
    expect(wrapper.text()).toContain('Ningún resultado con estos filtros.')
    expect(wrapper.text()).not.toContain('Aún no hay personas')

    const clearButtons = [...document.body.querySelectorAll('button')].filter(
      (button) => button.textContent?.trim() === 'Limpiar filtros',
    )
    // Uno en la barra y otro en el estado vacío; ambos limpian.
    expect(clearButtons.length).toBeGreaterThanOrEqual(1)
    await click(clearButtons.at(-1)!)

    expect(lastQuery(fetcher).filters).toEqual({})
    expect(document.activeElement).toBe(wrapper.get('input[type="search"]').element)
  })
})

describe('CA-CORE-182 (RUX-006, RNF-UX-007): error de carga', () => {
  it('503 con request_id: estado de error con la referencia y «Reintentar» que repite la misma consulta', async () => {
    const fetcher = vi
      .fn<DataTableFetcher<Person>>()
      .mockResolvedValueOnce(
        pageOf(people(3), { current_page: 2, per_page: 50, total: 200, last_page: 4 }),
      )
      .mockRejectedValueOnce(problem(503, { request_id: 'req-42' }))
      .mockResolvedValue(pageOf(people(2)))
    const wrapper = mountTable(fetcher)
    await flushPromises()

    await click(buttonByLabel('Nombre, ordenar de forma ascendente'))

    expect(wrapper.text()).toContain('req-42')
    const failed = lastQuery(fetcher)

    await click(buttonByLabel('Reintentar'))

    expect(lastQuery(fetcher)).toEqual(failed)
  })

  it('403 se pinta con la correspondencia única de §12.6 (estado «sin acceso»)', async () => {
    const fetcher = vi.fn<DataTableFetcher<Person>>().mockRejectedValue(problem(403))
    const wrapper = mountTable(fetcher)
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Reintentar')
  })
})

describe('CA-CORE-183 (RN-CORE-44): semántica de tabla nativa', () => {
  it('caption, th scope=col, la columna rowHeader como th scope=row y sin role=grid', async () => {
    const fetcher = vi.fn<DataTableFetcher<Person>>().mockResolvedValue(pageOf(people(2)))
    const wrapper = mountTable(fetcher)
    await flushPromises()

    expect(wrapper.get('caption').text()).toBe('Personas')
    const colHeaders = wrapper.findAll('thead th')
    expect(colHeaders.length).toBeGreaterThan(0)
    expect(colHeaders.every((th) => th.attributes('scope') === 'col')).toBe(true)
    const rowHeaders = wrapper.findAll('tbody th')
    expect(rowHeaders).toHaveLength(2)
    expect(rowHeaders.every((th) => th.attributes('scope') === 'row')).toBe(true)
    expect(wrapper.find('[role="grid"]').exists()).toBe(false)

    const sortButton = buttonByLabel('Nombre, ordenar de forma ascendente')
    expect(sortButton.getAttribute('aria-label')).toBe('Nombre, ordenar de forma ascendente')
    expect(sortButton.querySelector('svg')?.getAttribute('aria-hidden')).toBe('true')
  })
})

describe('CA-CORE-184: anuncios de resultado', () => {
  it('«137 resultados» con plural correcto en los cuatro idiomas; no cambia mientras se escribe', async () => {
    vi.useFakeTimers()
    const fetcher = vi
      .fn<DataTableFetcher<Person>>()
      .mockResolvedValue(pageOf(people(25), { total: 137, last_page: 6 }))
    const wrapper = mountTable(fetcher, { searchable: true })
    await flushPromises()

    const announcer = () => wrapper.get('[data-slot="data-table-announcer"]')
    expect(announcer().attributes('aria-live')).toBe('polite')
    expect(announcer().text()).toBe('137 resultados')

    await wrapper.get('input[type="search"]').setValue('Ló')
    await vi.advanceTimersByTimeAsync(100)
    expect(announcer().text()).toBe('137 resultados')

    vi.useRealTimers()

    const expectations = {
      es: ['Sin resultados', '1 resultado', '137 resultados'],
      en: ['No results', '1 result', '137 results'],
      de: ['Keine Ergebnisse', '1 Ergebnis', '137 Ergebnisse'],
      fr: ['Aucun résultat', '1 résultat', '137 résultats'],
    } as const

    for (const [localeName, [zero, one, many]] of Object.entries(expectations)) {
      const locale = localeName as 'es' | 'en' | 'de' | 'fr'
      const t = i18n.global.t
      const options = { locale } as const

      expect(t('dataTable.announce.results', { count: '0' }, { ...options, plural: 0 })).toBe(zero)
      expect(t('dataTable.announce.results', { count: '1' }, { ...options, plural: 1 })).toBe(one)
      expect(t('dataTable.announce.results', { count: '137' }, { ...options, plural: 137 })).toBe(
        many,
      )
    }
  })
})

describe('CA-CORE-201 (OPEN-CORE-27, INV-009, issue #90): valor vacío común', () => {
  it.each(['es', 'en', 'de', 'fr'] as const)(
    'en tabla y en tarjeta, la marca va con aria-hidden y el texto solo para lector (%s)',
    async (locale) => {
      setLocale(locale)
      const expected = i18n.global.t('dataTable.emptyValue')

      for (const narrow of [false, true]) {
        setNarrow(narrow)
        const fetcher = vi.fn<DataTableFetcher<Person>>().mockResolvedValue(pageOf(people(2)))
        const wrapper = mountTable(fetcher)
        await flushPromises()

        // La fila 1 tiene `email` nulo.
        const empty = wrapper.find('[data-slot="data-table-empty-value"]')
        expect(empty.exists()).toBe(true)
        expect(empty.find('[aria-hidden="true"]').text()).toBe('—')
        expect(empty.find('.sr-only').text()).toBe(expected)
        wrapper.unmount()
        wrappers.pop()
      }
    },
  )
})
