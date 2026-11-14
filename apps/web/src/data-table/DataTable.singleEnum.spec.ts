/**
 * `docs/modulos/REQ-CORE/funcional.md §14.13.2`: `CA-CORE-288` a `-290`
 * (`RN-CORE-94`, `OPEN-CORE-54`/`-55`) y `CA-CORE-269` (parte `enum`):
 * ampliación aditiva del filtro `enum` con `multiple: false` e `initial`.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { Component } from 'vue'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createRouter, createWebHistory, type Router } from 'vue-router'
import { i18n, setLocale } from '@/i18n'
import DataTable from './components/DataTable.vue'
import { sanitizeSingleEnums } from './filterState'
import type { DataTableColumn, DataTableFetcher, DataTableFilter, DataTableQuery } from './types'

interface Row {
  public_id: string
  name: string
}

const columns: DataTableColumn<Row>[] = [
  { id: 'name', headerKey: 'fixture.name', rowHeader: true, hideable: false, card: 'title' },
]

const OPTIONS = [
  { value: 'a', label: 'Opción A' },
  { value: 'b', label: 'Opción B' },
  { value: 'c', label: 'Opción C' },
]

function single(extra: Record<string, unknown> = {}): DataTableFilter {
  return {
    type: 'enum',
    id: 'kind',
    labelKey: 'fixture.kind',
    multiple: false,
    initial: 'b',
    options: OPTIONS,
    ...extra,
  } as DataTableFilter
}

const wrappers: VueWrapper[] = []

function mountTable(
  fetcher: DataTableFetcher<Row>,
  filters: DataTableFilter[],
  props: Record<string, unknown> = {},
  plugins: unknown[] = [],
) {
  const wrapper = mount(DataTable as unknown as Component, {
    props: {
      tableId: 'fixture.single_enum',
      caption: 'Filas',
      columns,
      mode: 'page',
      fetcher,
      filters,
      emptyTitle: 'Texto de vacío del consumidor',
      ...props,
    },
    global: { plugins: [i18n, ...plugins] as never[] },
    attachTo: document.body,
  }) as VueWrapper

  wrappers.push(wrapper)

  return wrapper
}

function page(rows = 1): Awaited<ReturnType<DataTableFetcher<Row>>> {
  return {
    data: Array.from({ length: rows }, (_, i) => ({ public_id: `r${i}`, name: `Fila ${i}` })),
    meta: { current_page: 1, per_page: 25, total: rows, last_page: 1 },
  }
}

function lastQuery(fetcher: ReturnType<typeof vi.fn>): DataTableQuery {
  return fetcher.mock.calls.at(-1)![0] as DataTableQuery
}

function buttonByPrefix(prefix: string): HTMLButtonElement {
  const found = [...document.body.querySelectorAll('button')].find((button) =>
    (button.getAttribute('aria-label') ?? button.textContent ?? '').trim().startsWith(prefix),
  )

  if (!found) {
    throw new Error(`No hay botón «${prefix}»`)
  }

  return found as HTMLButtonElement
}

async function click(el: Element): Promise<void> {
  el.dispatchEvent(new MouseEvent('click', { bubbles: true }))
  await flushPromises()
}

function radios(): HTMLElement[] {
  return [...document.body.querySelectorAll<HTMLElement>('[role="menuitemradio"]')]
}

function radio(text: string): HTMLElement {
  const found = radios().find((el) => el.textContent?.trim() === text)

  if (!found) {
    throw new Error(`No hay opción «${text}»`)
  }

  return found
}

beforeEach(() => {
  setLocale('es')
  for (const locale of ['es', 'en', 'de', 'fr'] as const) {
    i18n.global.mergeLocaleMessage(locale, { fixture: { name: 'Nombre', kind: 'Tipo' } })
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
  vi.restoreAllMocks()
})

describe('CA-CORE-288 (RN-CORE-94): enum de selección única con valor inicial', () => {
  it('grupo de opciones exclusivas con «Todos» primero y la inicial marcada; elegir cambia la petición', async () => {
    const fetcher = vi.fn<DataTableFetcher<Row>>().mockResolvedValue(page())
    mountTable(fetcher, [single()])
    await flushPromises()

    expect(lastQuery(fetcher).filters).toEqual({ kind: 'b' })

    const trigger = buttonByPrefix('Tipo')

    expect(trigger.textContent?.trim()).toBe('Tipo: Opción B')

    await click(trigger)

    expect(radios().map((el) => el.textContent?.trim())).toEqual([
      'Todos',
      'Opción A',
      'Opción B',
      'Opción C',
    ])
    expect(radio('Opción B').getAttribute('aria-checked')).toBe('true')

    const calls = fetcher.mock.calls.length

    await click(radio('Opción C'))

    expect(fetcher.mock.calls.length).toBe(calls + 1)
    expect(lastQuery(fetcher).filters).toEqual({ kind: 'c' })
    expect(lastQuery(fetcher).page).toBe(1)

    await click(buttonByPrefix('Tipo'))

    expect(radio('Opción B').getAttribute('aria-checked')).toBe('false')
    expect(radio('Opción C').getAttribute('aria-checked')).toBe('true')

    await click(radio('Todos'))

    expect(lastQuery(fetcher).filters).not.toHaveProperty('kind')
  })

  it('initial no declarado, o initial sin multiple: false, arranca en «Todos» con aviso y sin error', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined)

    for (const filter of [
      single({ initial: 'z' }),
      single({ multiple: undefined }),
      single({ multiple: true }),
    ]) {
      const fetcher = vi.fn<DataTableFetcher<Row>>().mockResolvedValue(page())
      const wrapper = mountTable(fetcher, [filter])
      await flushPromises()

      expect(lastQuery(fetcher).filters).not.toHaveProperty('kind')
      expect(warn).toHaveBeenCalled()
      warn.mockClear()
      wrapper.unmount()
      wrappers.pop()
    }
  })

  it('un valor con coma o no declarado en el estado se descarta; un valor declarado se conserva', () => {
    const filters = [single()]

    expect(sanitizeSingleEnums({ kind: 'a,b' }, filters)).toEqual({})
    expect(sanitizeSingleEnums({ kind: 'z' }, filters)).toEqual({})
    expect(sanitizeSingleEnums({ kind: 'a', other: 'x,y' }, filters)).toEqual({
      kind: 'a',
      other: 'x,y',
    })
  })
})

describe('CA-CORE-289 (RN-CORE-94, OPEN-CORE-54 = A): el valor inicial es el estado de reposo', () => {
  it('sin «Limpiar filtros» al montar; con «Todos» aparece; al activarlo vuelve a la inicial', async () => {
    const fetcher = vi.fn<DataTableFetcher<Row>>().mockResolvedValue(page())
    mountTable(fetcher, [single()])
    await flushPromises()

    expect(document.body.textContent).not.toContain('Limpiar filtros')

    await click(buttonByPrefix('Tipo'))
    await click(radio('Todos'))

    expect(lastQuery(fetcher).filters).toEqual({})
    expect(document.body.textContent).toContain('Limpiar filtros')

    await click(buttonByPrefix('Limpiar filtros'))

    expect(lastQuery(fetcher).filters).toEqual({ kind: 'b' })
    expect(document.body.textContent).not.toContain('Limpiar filtros')
  })

  it('sin filas y con el filtro en la inicial se pinta el vacío del consumidor, no «sin resultados»', async () => {
    const fetcher = vi.fn<DataTableFetcher<Row>>().mockResolvedValue(page(0))
    mountTable(fetcher, [single()])
    await flushPromises()

    expect(document.body.textContent).toContain('Texto de vacío del consumidor')
    expect(document.body.textContent).not.toContain('Limpiar filtros')
  })

  it('un enum sin initial no cambia: activo desde el primer valor, «Limpiar» deja el mapa vacío', async () => {
    const fetcher = vi.fn<DataTableFetcher<Row>>().mockResolvedValue(page())
    mountTable(fetcher, [single({ initial: undefined })])
    await flushPromises()

    await click(buttonByPrefix('Tipo'))
    await click(radio('Opción A'))

    expect(document.body.textContent).toContain('Limpiar filtros')

    await click(buttonByPrefix('Limpiar filtros'))

    expect(lastQuery(fetcher).filters).toEqual({})
  })
})

describe('CA-CORE-290 (RN-CORE-94, OPEN-CORE-55 = A): initial con urlState se ignora', () => {
  async function makeRouter(initial: string): Promise<Router> {
    window.history.replaceState(null, '', initial)
    const router = createRouter({
      history: createWebHistory(),
      routes: [{ path: '/tabla', component: { template: '<div/>' } }],
    })
    await router.push(initial)
    await router.isReady()

    return router
  }

  it('la primera petición no lleva el parámetro, la URL no lo contiene y hay aviso', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined)
    const router = await makeRouter('/tabla')
    const fetcher = vi.fn<DataTableFetcher<Row>>().mockResolvedValue(page())
    mountTable(fetcher, [single()], { urlState: true, tableId: 'fixture.single_enum_url' }, [
      router,
    ])
    await flushPromises()

    expect(lastQuery(fetcher).filters).toEqual({})
    expect(router.currentRoute.value.query).not.toHaveProperty('kind')
    expect(warn).toHaveBeenCalled()
  })
})
