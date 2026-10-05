/**
 * `docs/modulos/REQ-PERM/funcional.md §20.17.7`: modo `local` de `src/data-table`
 * (`RN-PERM-44` E1-E10, paso 1.5b) — `CA-PERM-123` (una petición, paginación en
 * cliente), `CA-PERM-124` (orden con `Intl.Collator`, vacíos al final, `compare`),
 * `CA-PERM-125` (filtros `enum`/`boolean` por `rowValue`; `dateRange`/`entity`
 * ignorados con aviso), `CA-PERM-126` (búsqueda con espera de 300 ms y anuncio en
 * los cuatro idiomas) y `CA-PERM-127` (sin exportación). «Tabla de prueba» = una
 * tabla declarada solo aquí, con función de petición simulada.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { Component } from 'vue'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { ApiError } from '@/api/client'
import { i18n, setLocale } from '@/i18n'
import DataTable from './components/DataTable.vue'
import { SEARCH_DEBOUNCE_MS } from './constants'
import { filterLocalRows, normalizeSearchText, pageLocalRows, sortLocalRows } from './localModel'
import type {
  DataTableColumn,
  DataTableExportConfig,
  DataTableFilter,
  DataTableLocalFetcher,
} from './types'

interface Item {
  public_id: string
  name: string
  kind: string[]
  flag: boolean
}

const MESSAGES = {
  name: 'Nombre',
  kind: 'Tipo',
  flag: 'Marcado',
  range: 'Rango',
  empty: 'No hay filas',
}

const wrappers: VueWrapper[] = []

function items(count: number): Item[] {
  return Array.from({ length: count }, (_, index) => ({
    public_id: `i-${String(index).padStart(3, '0')}`,
    name: `Fila ${index}`,
    kind: index % 3 === 0 ? ['a', 'b'] : ['c'],
    flag: index % 2 === 0,
  }))
}

function columnsOf(extra: Partial<DataTableColumn<Item>> = {}): DataTableColumn<Item>[] {
  return [
    {
      id: 'name',
      headerKey: 'fixture.name',
      rowHeader: true,
      sortable: true,
      hideable: false,
      card: 'title',
      ...extra,
    },
  ]
}

function mountTable(fetcher: DataTableLocalFetcher<Item>, props: Record<string, unknown> = {}) {
  const wrapper = mount(DataTable as unknown as Component, {
    props: {
      tableId: 'fixture.local',
      caption: 'Filas',
      columns: columnsOf(),
      mode: 'local',
      fetcher,
      emptyTitle: MESSAGES.empty,
      ...props,
    },
    global: { plugins: [i18n] },
    attachTo: document.body,
  }) as VueWrapper

  wrappers.push(wrapper)

  return wrapper
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

function rowNames(wrapper: VueWrapper): string[] {
  return wrapper.findAll('tbody th').map((th) => th.text())
}

function announcer(wrapper: VueWrapper): string {
  return wrapper.get('[data-slot="data-table-announcer"]').text()
}

beforeEach(() => {
  setLocale('es')
  for (const locale of ['es', 'en', 'de', 'fr'] as const) {
    i18n.global.mergeLocaleMessage(locale, { fixture: MESSAGES })
  }
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
  window.localStorage.clear()
})

afterEach(() => {
  while (wrappers.length > 0) {
    wrappers.pop()!.unmount()
  }
  vi.restoreAllMocks()
  document.body.innerHTML = ''
})

describe('CA-PERM-123 (RN-PERM-44 E1/E2): una petición y paginación en cliente', () => {
  it('con 120 filas, la petición se llama una vez, se pintan 25 y «página 1 de 5»; 50 por página da «1 de 3»; nada de esto vuelve a pedir', async () => {
    const fetcher = vi.fn<DataTableLocalFetcher<Item>>().mockResolvedValue({ data: items(120) })
    const wrapper = mountTable(fetcher)
    await flushPromises()

    expect(fetcher).toHaveBeenCalledTimes(1)
    expect(fetcher.mock.calls[0]).toHaveLength(1)
    expect(fetcher.mock.calls[0]![0]).toEqual({ signal: expect.any(AbortSignal) })
    expect(wrapper.findAll('tbody tr')).toHaveLength(25)
    expect(wrapper.get('[data-slot="data-table-page-indicator"]').text()).toBe('Página 1 de 5')

    await click(buttonByLabel('Página siguiente'))

    expect(wrapper.get('[data-slot="data-table-page-indicator"]').text()).toBe('Página 2 de 5')
    expect(rowNames(wrapper)[0]).toBe('Fila 25')

    await click(buttonByLabel('25 por página'))
    const fifty = [...document.body.querySelectorAll('[role="menuitemradio"]')].find((el) =>
      el.textContent?.includes('50'),
    )!
    await click(fifty)

    expect(wrapper.get('[data-slot="data-table-page-indicator"]').text()).toBe('Página 1 de 3')
    expect(wrapper.findAll('tbody tr')).toHaveLength(50)

    await click(wrapper.get('thead button').element)

    expect(fetcher).toHaveBeenCalledTimes(1)
  })

  it('refresh() la llama exactamente una vez más', async () => {
    const fetcher = vi.fn<DataTableLocalFetcher<Item>>().mockResolvedValue({ data: items(30) })
    const wrapper = mountTable(fetcher)
    await flushPromises()

    await (wrapper.vm as unknown as { refresh: () => Promise<void> }).refresh()

    expect(fetcher).toHaveBeenCalledTimes(2)
  })

  it('hideSinglePageFooter oculta el pie con una sola página y con 26 filas lo muestra', async () => {
    const fetcher = vi.fn<DataTableLocalFetcher<Item>>().mockResolvedValue({ data: items(10) })
    const wrapper = mountTable(fetcher, { hideSinglePageFooter: true })
    await flushPromises()

    expect(wrapper.find('[data-slot="data-table-pagination"]').exists()).toBe(false)

    fetcher.mockResolvedValue({ data: items(26) })
    await (wrapper.vm as unknown as { refresh: () => Promise<void> }).refresh()
    await flushPromises()

    expect(wrapper.find('[data-slot="data-table-pagination"]').exists()).toBe(true)
  })

  it('RN-PERM-44 E2: si tras recargar la página actual queda fuera de rango, pasa a la última sin otra petición', async () => {
    const fetcher = vi.fn<DataTableLocalFetcher<Item>>().mockResolvedValue({ data: items(60) })
    const wrapper = mountTable(fetcher)
    await flushPromises()

    await click(buttonByLabel('Última página'))

    expect(wrapper.get('[data-slot="data-table-page-indicator"]').text()).toBe('Página 3 de 3')

    fetcher.mockResolvedValue({ data: items(30) })
    await (wrapper.vm as unknown as { refresh: () => Promise<void> }).refresh()
    await flushPromises()

    expect(wrapper.get('[data-slot="data-table-page-indicator"]').text()).toBe('Página 2 de 2')
    expect(fetcher).toHaveBeenCalledTimes(2)
  })

  it('RN-PERM-44 E9: un fallo pinta el estado de error y «Reintentar» repite la carga', async () => {
    const fetcher = vi
      .fn<DataTableLocalFetcher<Item>>()
      .mockRejectedValueOnce(new ApiError('HTTP 500', 500, {}))
      .mockResolvedValue({ data: items(3) })
    const wrapper = mountTable(fetcher)
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)

    await click(buttonByLabel('Reintentar'))

    expect(fetcher).toHaveBeenCalledTimes(2)
    expect(wrapper.findAll('tbody tr')).toHaveLength(3)
  })

  it('RN-PERM-44 E9: sin filas, el estado vacío del consumidor; con filtros activos y sin resultados, el filtrado', async () => {
    const fetcher = vi.fn<DataTableLocalFetcher<Item>>().mockResolvedValue({ data: [] })
    const wrapper = mountTable(fetcher)
    await flushPromises()

    expect(wrapper.text()).toContain('No hay filas')

    fetcher.mockResolvedValue({ data: items(4) })
    const filtered = mountTable(fetcher, {
      searchable: true,
      searchText: (row: Item) => row.name,
    })
    await flushPromises()
    vi.useFakeTimers()
    await filtered.get('input[type="search"]').setValue('zzz')
    vi.advanceTimersByTime(SEARCH_DEBOUNCE_MS + 10)
    vi.useRealTimers()
    await flushPromises()

    expect(filtered.text()).toContain('Sin resultados')
  })
})

describe('CA-PERM-124 (RN-PERM-44 E3): orden con Intl.Collator', () => {
  const collatorRows = [
    { public_id: 'a', name: 'Álvaro' },
    { public_id: 'b', name: 'alba' },
    { public_id: 'c', name: 'Zoe' },
    { public_id: 'd', name: '' },
    { public_id: 'e', name: '10' },
    { public_id: 'f', name: '9' },
  ]
  const nameColumn: DataTableColumn<{ public_id: string; name: string }> = {
    id: 'name',
    headerKey: 'x',
    sortable: true,
  }
  const key = (row: { public_id: string }): string => row.public_id
  const names = (rows: { name: string }[]): string[] => rows.map((row) => row.name)

  it('ascendente: «9» antes que «10», «alba» y «Álvaro» juntos, el vacío último', () => {
    const sorted = sortLocalRows(collatorRows, 'name', [nameColumn], key, 'es')

    expect(names(sorted)).toEqual(['9', '10', 'alba', 'Álvaro', 'Zoe', ''])
  })

  it('descendente: el vacío sigue último en los dos sentidos', () => {
    const sorted = sortLocalRows(collatorRows, '-name', [nameColumn], key, 'es')

    expect(names(sorted)).toEqual(['Zoe', 'Álvaro', 'alba', '10', '9', ''])
  })

  it('desempata de forma estable por rowKey', () => {
    const rows = [
      { public_id: 'z', name: 'igual' },
      { public_id: 'a', name: 'igual' },
    ]

    expect(sortLocalRows(rows, 'name', [nameColumn], key, 'es').map(key)).toEqual(['a', 'z'])
    expect(sortLocalRows(rows, '-name', [nameColumn], key, 'es').map(key)).toEqual(['a', 'z'])
  })

  it('con un compare declarado se usa ese, invertido en el descendente', () => {
    const order = ['crear', 'leer', 'actualizar']
    const rows = [
      { public_id: '1', name: 'leer' },
      { public_id: '2', name: 'actualizar' },
      { public_id: '3', name: 'crear' },
    ]
    const column = {
      ...nameColumn,
      compare: (a: { name: string }, b: { name: string }) =>
        order.indexOf(a.name) - order.indexOf(b.name),
    }

    expect(names(sortLocalRows(rows, 'name', [column], key, 'es'))).toEqual([
      'crear',
      'leer',
      'actualizar',
    ])
    expect(names(sortLocalRows(rows, '-name', [column], key, 'es'))).toEqual([
      'actualizar',
      'leer',
      'crear',
    ])
  })

  it('en el componente, la cabecera cicla ascendente → descendente → sin orden sin pedir nada', async () => {
    const fetcher = vi.fn<DataTableLocalFetcher<Item>>().mockResolvedValue({
      data: [
        { public_id: 'a', name: 'Zoe', kind: [], flag: true },
        { public_id: 'b', name: 'alba', kind: [], flag: true },
      ],
    })
    const wrapper = mountTable(fetcher)
    await flushPromises()

    expect(rowNames(wrapper)).toEqual(['Zoe', 'alba'])

    await click(buttonByLabel('Nombre, ordenar de forma ascendente'))
    expect(rowNames(wrapper)).toEqual(['alba', 'Zoe'])
    expect(wrapper.get('thead th').attributes('aria-sort')).toBe('ascending')

    await click(buttonByLabel('Nombre, ordenar de forma descendente'))
    expect(rowNames(wrapper)).toEqual(['Zoe', 'alba'])

    await click(buttonByLabel('Nombre, quitar la ordenación'))
    expect(wrapper.findAll('[aria-sort]')).toHaveLength(0)
    expect(fetcher).toHaveBeenCalledTimes(1)
  })
})

describe('CA-PERM-125 (RN-PERM-44 E4): filtros en cliente', () => {
  const filters: DataTableFilter<Item>[] = [
    {
      type: 'enum',
      id: 'kind',
      labelKey: 'fixture.kind',
      options: [
        { value: 'a', label: 'A' },
        { value: 'c', label: 'C' },
      ],
      rowValue: (row) => row.kind,
    },
    {
      type: 'boolean',
      id: 'flag',
      labelKey: 'fixture.flag',
      twoState: true,
      rowValue: (r) => r.flag,
    },
  ]

  it('un enum con rowValue múltiple filtra por alguno de los valores de la fila', () => {
    const rows = items(6)
    const out = filterLocalRows(rows, {
      filters,
      filterValues: { kind: 'a' },
      query: '',
      locale: 'es',
    })

    expect(out.map((row) => row.name)).toEqual(['Fila 0', 'Fila 3'])
  })

  it('un boolean de dos estados filtra por rowValue; desmarcado no filtra', () => {
    const rows = items(4)

    expect(
      filterLocalRows(rows, {
        filters,
        filterValues: { flag: 'true' },
        query: '',
        locale: 'es',
      }).map((row) => row.name),
    ).toEqual(['Fila 0', 'Fila 2'])
    expect(
      filterLocalRows(rows, { filters, filterValues: {}, query: '', locale: 'es' }),
    ).toHaveLength(4)
  })

  it('un boolean de inclusión desmarcado deja pasar solo rowValue true; marcado, todas las filas (CA-PERM-121)', async () => {
    const inclusion: DataTableFilter<Item>[] = [
      {
        type: 'boolean',
        id: 'include_empty',
        labelKey: 'fixture.flag',
        twoState: true,
        inclusion: true,
        rowValue: (row) => row.flag,
      },
    ]
    const rows = items(4)

    expect(
      filterLocalRows(rows, { filters: inclusion, filterValues: {}, query: '', locale: 'es' }).map(
        (row) => row.name,
      ),
    ).toEqual(['Fila 0', 'Fila 2'])
    expect(
      filterLocalRows(rows, {
        filters: inclusion,
        filterValues: { include_empty: 'true' },
        query: '',
        locale: 'es',
      }),
    ).toHaveLength(4)

    const fetcher = vi.fn<DataTableLocalFetcher<Item>>().mockResolvedValue({ data: rows })
    const wrapper = mountTable(fetcher, { filters: inclusion })
    await flushPromises()

    expect(rowNames(wrapper)).toEqual(['Fila 0', 'Fila 2'])
    expect(announcer(wrapper)).toBe('2 de 4 resultados')

    await wrapper.get<HTMLInputElement>('input[type="checkbox"]').setValue(true)
    await flushPromises()

    expect(rowNames(wrapper)).toHaveLength(4)
    expect(announcer(wrapper)).toBe('4 de 4 resultados')
    expect(fetcher).toHaveBeenCalledTimes(1)
  })

  it('en el componente: marcar la casilla filtra sin otra petición', async () => {
    const fetcher = vi.fn<DataTableLocalFetcher<Item>>().mockResolvedValue({ data: items(4) })
    const wrapper = mountTable(fetcher, { filters })
    await flushPromises()

    await wrapper.get<HTMLInputElement>('input[type="checkbox"]').setValue(true)
    await flushPromises()

    expect(rowNames(wrapper)).toEqual(['Fila 0', 'Fila 2'])
    expect(fetcher).toHaveBeenCalledTimes(1)
  })

  it('un dateRange o un entity declarado en una tabla local se ignora y se avisa por consola, sin error', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined)
    const fetcher = vi.fn<DataTableLocalFetcher<Item>>().mockResolvedValue({ data: items(3) })
    const wrapper = mountTable(fetcher, {
      filters: [
        { type: 'dateRange', id: 'created', labelKey: 'fixture.range' },
        {
          type: 'entity',
          id: 'actor',
          labelKey: 'fixture.name',
          search: () => Promise.resolve([]),
          resolve: () => Promise.resolve(null),
        },
      ],
    })
    await flushPromises()

    expect(warn).toHaveBeenCalledTimes(2)
    expect(wrapper.find('fieldset').exists()).toBe(false)
    expect(wrapper.findAll('tbody tr')).toHaveLength(3)
  })
})

describe('CA-PERM-126 (RN-PERM-44 E5/E6, RN-CORE-40): búsqueda y anuncio', () => {
  it('la normalización no distingue mayúsculas ni tildes', () => {
    expect(normalizeSearchText('AUDITORÍA', 'es')).toBe('auditoria')
    expect(
      filterLocalRows(items(1), {
        filters: [],
        filterValues: {},
        searchText: () => 'Auditoría de usuarios',
        query: 'audito USUA',
        locale: 'es',
      }),
    ).toHaveLength(1)
  })

  it.each([
    ['es', '12 de 40 resultados'],
    ['en', '12 of 40 results'],
    ['de', '12 von 40 Ergebnissen'],
    ['fr', '12 sur 40 résultats'],
  ] as const)(
    'cinco pulsaciones con 50 ms entre ellas filtran y anuncian una sola vez, 300 ms después de la última (%s)',
    async (locale, expected) => {
      setLocale(locale)
      vi.useFakeTimers()

      try {
        const data = items(40).map((item, index) => ({
          ...item,
          name: index < 12 ? `Alfa ${index}` : `Beta ${index}`,
        }))
        const fetcher = vi.fn<DataTableLocalFetcher<Item>>().mockResolvedValue({ data })
        const wrapper = mountTable(fetcher, {
          searchable: true,
          searchText: (row: Item) => row.name,
        })
        await vi.advanceTimersByTimeAsync(0)

        const input = wrapper.get('input[type="search"]')

        for (const text of ['a', 'al', 'alf', 'alfa', 'ALFA']) {
          await input.setValue(text)
          await vi.advanceTimersByTimeAsync(50)
        }

        expect(announcer(wrapper)).toContain('40 ')

        await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS - 50 + 10)

        expect(announcer(wrapper)).toBe(expected)
        expect(wrapper.findAll('tbody tr')).toHaveLength(12)
        expect(fetcher).toHaveBeenCalledTimes(1)
      } finally {
        vi.useRealTimers()
      }
    },
  )

  it('el anuncio de carga dice el recuento sobre el total («40 de 40 resultados»)', async () => {
    const fetcher = vi.fn<DataTableLocalFetcher<Item>>().mockResolvedValue({ data: items(40) })
    const wrapper = mountTable(fetcher)
    await flushPromises()

    expect(announcer(wrapper)).toBe('40 de 40 resultados')
  })

  it('`q` nunca va a la URL', async () => {
    const fetcher = vi.fn<DataTableLocalFetcher<Item>>().mockResolvedValue({ data: items(3) })
    mountTable(fetcher, { searchable: true, searchText: (row: Item) => row.name })
    await flushPromises()

    expect(window.location.search).not.toContain('q=')
  })
})

describe('CA-PERM-127 (RN-PERM-44 E8, RN-CORE-46): sin exportación', () => {
  it('con exportConfig en modo local no hay control de exportación y se avisa por consola', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined)
    const exportConfig: DataTableExportConfig = {
      canExport: true,
      request: vi.fn(),
      status: vi.fn(),
    }
    const fetcher = vi.fn<DataTableLocalFetcher<Item>>().mockResolvedValue({ data: items(3) })
    const wrapper = mountTable(fetcher, { exportConfig })
    await flushPromises()

    expect(wrapper.text()).not.toContain('Exportar')
    expect(warn).toHaveBeenCalledTimes(1)
    expect(exportConfig.request).not.toHaveBeenCalled()
  })
})

describe('RN-PERM-44 E10: slots por columna también en la vista de tarjetas', () => {
  it('el slot de una columna que no es de acciones se pinta en el dd del campo', async () => {
    Object.defineProperty(window, 'matchMedia', {
      configurable: true,
      writable: true,
      value: (query: string) => ({
        matches: true,
        media: query,
        addEventListener: () => undefined,
        removeEventListener: () => undefined,
      }),
    })

    const fetcher = vi.fn<DataTableLocalFetcher<Item>>().mockResolvedValue({ data: items(2) })
    const wrapper = mount(DataTable as unknown as Component, {
      props: {
        tableId: 'fixture.local_cards',
        caption: 'Filas',
        columns: [
          ...columnsOf(),
          { id: 'kind', headerKey: 'fixture.kind', card: 'field' } satisfies DataTableColumn<Item>,
        ],
        mode: 'local',
        fetcher,
        emptyTitle: MESSAGES.empty,
      },
      slots: {
        'cell-kind':
          '<template #cell-kind="{ row }"><a href="#x" data-test="kind-link">{{ row.public_id }}</a></template>',
      },
      global: { plugins: [i18n] },
      attachTo: document.body,
    }) as VueWrapper

    wrappers.push(wrapper)
    await flushPromises()

    expect(wrapper.find('[data-slot="data-table-cards"]').exists()).toBe(true)
    expect(wrapper.findAll('dd [data-test="kind-link"]')).toHaveLength(2)
  })
})

describe('RN-PERM-44 E2: pageLocalRows', () => {
  it('una página fuera de rango pasa a la última y la meta cuenta el total filtrado', () => {
    const out = pageLocalRows(items(60), 9, 25)

    expect(out.meta).toEqual({ current_page: 3, per_page: 25, total: 60, last_page: 3 })
    expect(out.rows).toHaveLength(10)
  })

  it('sin filas hay una página', () => {
    expect(pageLocalRows([], 1, 25).meta.last_page).toBe(1)
  })
})
