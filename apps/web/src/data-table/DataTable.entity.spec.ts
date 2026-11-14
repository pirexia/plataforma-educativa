/**
 * `docs/modulos/REQ-CORE/funcional.md §14.7`, `CA-CORE-244`
 * (`OPEN-CORE-33` = C, `RN-CORE-76`): ampliación aditiva `entity` del
 * componente de tablas — selección única por búsqueda asíncrona, serializada
 * como `<id>=<ulid>`, con la etiqueta resuelta al restaurar desde la URL. El
 * componente no conoce el *endpoint*: la búsqueda y la resolución las aporta
 * el consumidor (`RN-CORE-38`).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { Component } from 'vue'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { i18n, setLocale } from '@/i18n'
import { SEARCH_DEBOUNCE_MS } from './constants'
import DataTable from './components/DataTable.vue'
import type {
  DataTableColumn,
  DataTableEntityFilter,
  DataTableFetcher,
  DataTableQuery,
} from './types'

interface Row {
  public_id: string
  name: string
}

const ANA = '01HZX0000000000000000000AA'
const LUIS = '01HZX0000000000000000000BB'

const columns: DataTableColumn<Row>[] = [
  { id: 'name', headerKey: 'fixture.name', rowHeader: true, hideable: false, card: 'title' },
]

const wrappers: VueWrapper[] = []

function page(): Awaited<ReturnType<DataTableFetcher<Row>>> {
  return {
    data: [{ public_id: 'r1', name: 'Fila uno' }],
    meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 },
  }
}

async function mountTable(
  fetcher: DataTableFetcher<Row>,
  filter: DataTableEntityFilter,
  url = '/tabla',
) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/tabla', component: { template: '<div/>' } }],
  })

  await router.push(url)
  await router.isReady()

  const wrapper = mount(DataTable as unknown as Component, {
    props: {
      tableId: 'fixture.entity',
      caption: 'Filas',
      columns,
      mode: 'page',
      fetcher,
      filters: [filter],
      urlState: true,
      emptyTitle: 'Vacío',
    },
    global: { plugins: [i18n, router] },
    attachTo: document.body,
  }) as VueWrapper

  wrappers.push(wrapper)
  await flushPromises()

  return { wrapper, router }
}

function lastQuery(fetcher: ReturnType<typeof vi.fn>): DataTableQuery {
  return fetcher.mock.calls.at(-1)![0] as DataTableQuery
}

function entityFilter(search = vi.fn(), resolve = vi.fn()): DataTableEntityFilter {
  return { type: 'entity', id: 'actor_id', labelKey: 'fixture.actor', search, resolve }
}

function searchInput(): HTMLInputElement {
  return document.body.querySelector<HTMLInputElement>(
    '[data-slot="data-table-entity-filter"] input[type="search"]',
  )!
}

async function type(text: string): Promise<void> {
  const input = searchInput()

  input.value = text
  input.dispatchEvent(new Event('input', { bubbles: true }))
  await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS + 10)
  await flushPromises()
}

beforeEach(() => {
  setLocale('es')
  for (const locale of ['es', 'en', 'de', 'fr'] as const) {
    i18n.global.mergeLocaleMessage(locale, { fixture: { name: 'Nombre', actor: 'Usuario' } })
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
  vi.useFakeTimers({ shouldAdvanceTime: true })
})

afterEach(() => {
  while (wrappers.length > 0) {
    wrappers.pop()!.unmount()
  }
  document.body.innerHTML = ''
  vi.useRealTimers()
})

describe('CA-CORE-244 (OPEN-CORE-33 = C, RN-CORE-76): filtro entity', () => {
  it('una sola petición de búsqueda tras el debounce, con el texto recortado; elegir un resultado envía <id>=<ulid> y muestra su nombre', async () => {
    const fetcher = vi.fn<DataTableFetcher<Row>>().mockResolvedValue(page())
    const search = vi.fn().mockResolvedValue([
      { value: ANA, label: 'Ana López' },
      { value: LUIS, label: 'Luis Gómez' },
    ])
    const { router } = await mountTable(fetcher, entityFilter(search))

    expect(lastQuery(fetcher).filters).toEqual({})

    await type('  an ')

    expect(search).toHaveBeenCalledTimes(1)
    expect(search.mock.calls[0]![0]).toBe('an')

    const option = [
      ...document.body.querySelectorAll('[data-slot="data-table-entity-results"] button'),
    ].find((button) => button.textContent?.trim() === 'Ana López') as HTMLButtonElement

    option.click()
    await flushPromises()

    expect(lastQuery(fetcher).filters).toEqual({ actor_id: ANA })
    expect(document.body.textContent).toContain('Filtrado por: Ana López')
    expect(router.currentRoute.value.query.actor_id).toBe(ANA)
    // La etiqueta ya se conoce: no se resuelve de nuevo.
    expect(searchInput()).toBeNull()
  })

  it('al restaurar desde la URL resuelve la etiqueta una sola vez y filtra con el valor', async () => {
    const fetcher = vi.fn<DataTableFetcher<Row>>().mockResolvedValue(page())
    const resolve = vi.fn().mockResolvedValue('Ana López')
    await mountTable(fetcher, entityFilter(vi.fn(), resolve), `/tabla?actor_id=${ANA}`)

    expect(lastQuery(fetcher).filters).toEqual({ actor_id: ANA })
    expect(resolve).toHaveBeenCalledTimes(1)
    expect(resolve).toHaveBeenCalledWith(ANA)
    expect(document.body.textContent).toContain('Filtrado por: Ana López')
  })

  it('si la etiqueta no se puede resolver, el filtro sigue aplicado y se muestra «Elemento no disponible»', async () => {
    const fetcher = vi.fn<DataTableFetcher<Row>>().mockResolvedValue(page())
    const resolve = vi.fn().mockResolvedValue(null)
    await mountTable(fetcher, entityFilter(vi.fn(), resolve), `/tabla?actor_id=${ANA}`)

    expect(lastQuery(fetcher).filters).toEqual({ actor_id: ANA })
    expect(document.body.textContent).toContain('Filtrado por: Elemento no disponible')
  })

  it('quitar el filtro vuelve a pedir sin él, limpia la URL y devuelve el foco al campo de búsqueda', async () => {
    const fetcher = vi.fn<DataTableFetcher<Row>>().mockResolvedValue(page())
    const { router } = await mountTable(
      fetcher,
      entityFilter(vi.fn(), vi.fn().mockResolvedValue('Ana López')),
      `/tabla?actor_id=${ANA}`,
    )

    const remove = document.body.querySelector<HTMLButtonElement>(
      '[aria-label="Quitar el filtro «Usuario»"]',
    )!

    remove.click()
    await flushPromises()

    expect(lastQuery(fetcher).filters).toEqual({})
    expect(router.currentRoute.value.query.actor_id).toBeUndefined()
    expect(document.activeElement).toBe(searchInput())
  })

  it('un valor de la URL que no es un identificador público se ignora y no llega al servidor', async () => {
    const fetcher = vi.fn<DataTableFetcher<Row>>().mockResolvedValue(page())
    const resolve = vi.fn()
    await mountTable(fetcher, entityFilter(vi.fn(), resolve), '/tabla?actor_id=no-es-un-ulid')

    expect(lastQuery(fetcher).filters).toEqual({})
    expect(resolve).not.toHaveBeenCalled()
  })

  it('un fallo de la búsqueda se anuncia y no rompe la tabla', async () => {
    const fetcher = vi.fn<DataTableFetcher<Row>>().mockResolvedValue(page())
    const search = vi.fn().mockRejectedValue(new Error('red'))
    await mountTable(fetcher, entityFilter(search))

    await type('ana')

    expect(document.body.textContent).toContain('No se ha podido buscar')
    expect(document.body.textContent).toContain('Fila uno')
  })

  it('el campo de búsqueda tiene etiqueta accesible y la lista se anuncia con una región aria-live', async () => {
    const fetcher = vi.fn<DataTableFetcher<Row>>().mockResolvedValue(page())
    const search = vi.fn().mockResolvedValue([{ value: ANA, label: 'Ana López' }])
    await mountTable(fetcher, entityFilter(search))

    const input = searchInput()

    expect(document.body.querySelector(`label[for="${input.id}"]`)?.textContent).toContain(
      'Buscar: Usuario',
    )

    await type('ana')

    const status = document.getElementById(input.getAttribute('aria-describedby')!)!

    expect(status.getAttribute('aria-live')).toBe('polite')
    expect(status.textContent).toContain('Un resultado')
  })
})
