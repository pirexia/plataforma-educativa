/**
 * `docs/modulos/REQ-CORE/funcional.md §14.18`, `CA-CORE-269` (parte de 1.9b):
 * ampliación aditiva `twoState` del filtro `boolean` (`OPEN-CORE-40` = A,
 * `RN-CORE-68`). Desmarcado no envía el parámetro; marcado envía `<id>=true`;
 * sin opción «todos». El filtro `boolean` de tres estados de 1.9 no cambia.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { Component } from 'vue'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { i18n, setLocale } from '@/i18n'
import DataTable from './components/DataTable.vue'
import type { DataTableColumn, DataTableFetcher, DataTableFilter, DataTableQuery } from './types'

interface Row {
  public_id: string
  name: string
}

const columns: DataTableColumn<Row>[] = [
  { id: 'name', headerKey: 'fixture.name', rowHeader: true, hideable: false, card: 'title' },
]

const wrappers: VueWrapper[] = []

function mountTable(fetcher: DataTableFetcher<Row>, filters: DataTableFilter[]) {
  const wrapper = mount(DataTable as unknown as Component, {
    props: {
      tableId: 'fixture.two_state',
      caption: 'Filas',
      columns,
      mode: 'page',
      fetcher,
      filters,
      emptyTitle: 'Vacío',
    },
    global: { plugins: [i18n] },
    attachTo: document.body,
  }) as VueWrapper

  wrappers.push(wrapper)

  return wrapper
}

function page(): Awaited<ReturnType<DataTableFetcher<Row>>> {
  return {
    data: [{ public_id: 'r1', name: 'Fila uno' }],
    meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 },
  }
}

function lastQuery(fetcher: ReturnType<typeof vi.fn>): DataTableQuery {
  return fetcher.mock.calls.at(-1)![0] as DataTableQuery
}

beforeEach(() => {
  setLocale('es')
  for (const locale of ['es', 'en', 'de', 'fr'] as const) {
    i18n.global.mergeLocaleMessage(locale, {
      fixture: { name: 'Nombre', deleted: 'Incluir dados de baja', flag: 'Marcado' },
    })
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
  document.body.innerHTML = ''
})

describe('CA-CORE-269 (RN-CORE-68, OPEN-CORE-40 = A): filtro booleano de dos estados', () => {
  it('desmarcado no envía el parámetro; marcado envía <id>=true; desmarcar de nuevo lo quita; sin opción «todos»', async () => {
    const fetcher = vi.fn<DataTableFetcher<Row>>().mockResolvedValue(page())
    const wrapper = mountTable(fetcher, [
      { type: 'boolean', id: 'include_deleted', labelKey: 'fixture.deleted', twoState: true },
    ])
    await flushPromises()

    expect(lastQuery(fetcher).filters).toEqual({})

    const checkbox = wrapper.get<HTMLInputElement>('input[type="checkbox"]')

    expect(checkbox.element.checked).toBe(false)
    expect(wrapper.get('[data-slot="data-table-two-state-filter"]').text()).toBe(
      'Incluir dados de baja',
    )
    expect(document.body.textContent).not.toContain('Todos')

    await checkbox.setValue(true)
    await flushPromises()

    expect(lastQuery(fetcher).filters).toEqual({ include_deleted: 'true' })

    await checkbox.setValue(false)
    await flushPromises()

    expect(lastQuery(fetcher).filters).toEqual({})
  })

  it('el filtro boolean de tres estados de 1.9 sigue siendo un menú con «todos», sin casilla', async () => {
    const fetcher = vi.fn<DataTableFetcher<Row>>().mockResolvedValue(page())
    const wrapper = mountTable(fetcher, [{ type: 'boolean', id: 'flag', labelKey: 'fixture.flag' }])
    await flushPromises()

    expect(wrapper.find('[data-slot="data-table-two-state-filter"]').exists()).toBe(false)
    expect(wrapper.find('input[type="checkbox"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Marcado')
  })
})
