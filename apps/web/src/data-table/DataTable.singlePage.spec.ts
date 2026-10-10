/**
 * `docs/modulos/REQ-CORE/funcional.md §14.10b`, `CA-CORE-267` (issue #326): con
 * `hideSinglePageFooter` el pie de paginación no se pinta si solo hay una página.
 * También `formatDate`/`formatDateTime` con fechas inválidas (issue #276).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { Component } from 'vue'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { i18n, setLocale } from '@/i18n'
import DataTable from './components/DataTable.vue'
import { useDataTableFormatters } from './formatters'
import type { DataTableColumn, DataTableFetcher } from './types'

interface Row {
  public_id: string
  name: string
}

const columns: DataTableColumn<Row>[] = [
  { id: 'name', headerKey: 'fixture.name', rowHeader: true, hideable: false, card: 'title' },
]

const wrappers: VueWrapper[] = []

function mountTable(fetcher: DataTableFetcher<Row>, hideSinglePageFooter: boolean) {
  const wrapper = mount(DataTable as unknown as Component, {
    props: {
      tableId: 'fixture.single_page',
      caption: 'Filas',
      columns,
      mode: 'page',
      fetcher,
      emptyTitle: 'Vacío',
      hideSinglePageFooter,
    },
    global: { plugins: [i18n] },
    attachTo: document.body,
  }) as VueWrapper

  wrappers.push(wrapper)

  return wrapper
}

function pageOf(lastPage: number): Awaited<ReturnType<DataTableFetcher<Row>>> {
  return {
    data: [{ public_id: 'r1', name: 'Fila uno' }],
    meta: { current_page: 1, per_page: 25, total: lastPage * 25, last_page: lastPage },
  }
}

beforeEach(() => {
  setLocale('es')
  for (const locale of ['es', 'en', 'de', 'fr'] as const) {
    i18n.global.mergeLocaleMessage(locale, { fixture: { name: 'Nombre' } })
  }
})

afterEach(() => {
  wrappers.splice(0).forEach((w) => w.unmount())
})

describe('CA-CORE-267 (issue #326): pie de una sola página', () => {
  it('con hideSinglePageFooter y una página, no hay pie', async () => {
    const wrapper = mountTable(vi.fn().mockResolvedValue(pageOf(1)), true)
    await flushPromises()

    expect(wrapper.find('tbody tr').exists()).toBe(true)
    expect(wrapper.find('[data-slot="data-table-page-indicator"]').exists()).toBe(false)
  })

  it('con hideSinglePageFooter y varias páginas, el pie sigue', async () => {
    const wrapper = mountTable(vi.fn().mockResolvedValue(pageOf(3)), true)
    await flushPromises()

    expect(wrapper.get('[data-slot="data-table-page-indicator"]').text()).toBe('Página 1 de 3')
  })

  it('sin la propiedad, una página mantiene el pie (comportamiento previo)', async () => {
    const wrapper = mountTable(vi.fn().mockResolvedValue(pageOf(1)), false)
    await flushPromises()

    expect(wrapper.get('[data-slot="data-table-page-indicator"]').text()).toBe('Página 1 de 1')
  })
})

describe('issue #276: fechas inválidas', () => {
  it('formatDate y formatDateTime devuelven null y no lanzan RangeError', () => {
    const { formatDate, formatDateTime } = useDataTableFormatters()

    expect(formatDate('basura')).toBeNull()
    expect(formatDateTime('basura')).toBeNull()
    expect(formatDate('2026-10-03T10:15:00Z')).not.toBeNull()
  })
})
