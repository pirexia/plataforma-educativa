/**
 * `docs/modulos/REQ-CORE/funcional.md §13.5`, `RN-CORE-54`, `CA-CORE-198`,
 * `ADR-054 §6`: estado de la consulta en la URL, **sin `q` ni `cursor`**,
 * opcional por tabla y como máximo una por ruta.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { Component } from 'vue'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createRouter, createWebHistory, type Router } from 'vue-router'
import { i18n, setLocale } from '@/i18n'
import DataTable from './components/DataTable.vue'
import { SEARCH_DEBOUNCE_MS } from './constants'
import type { DataTableColumn, DataTableFetcher, DataTableFilter, DataTableQuery } from './types'

interface Person {
  public_id: string
  name: string
}

const columns: DataTableColumn<Person>[] = [
  { id: 'name', headerKey: 'fixture.name', rowHeader: true, sortable: true, card: 'title' },
  { id: 'created_at', headerKey: 'fixture.created', sortable: true, card: 'field' },
]

const filters: DataTableFilter[] = [
  {
    type: 'enum',
    id: 'status',
    labelKey: 'fixture.name',
    options: [{ value: 'activo' }, { value: 'inactivo' }],
  },
  { type: 'dateRange', id: 'occurred_at', labelKey: 'fixture.created' },
  { type: 'boolean', id: 'is_system', labelKey: 'fixture.name' },
]

const wrappers: VueWrapper[] = []

function pageFetcher() {
  return vi.fn<DataTableFetcher<Person>>().mockImplementation(async (query) => ({
    data: [{ public_id: 'a', name: 'Ana' }],
    meta: { current_page: query.page ?? 1, per_page: 25, total: 500, last_page: 20 },
  }))
}

async function makeRouter(initial: string): Promise<Router> {
  window.history.replaceState(null, '', initial)
  const router = createRouter({
    history: createWebHistory(),
    routes: [
      { path: '/', component: { template: '<div/>' } },
      { path: '/tabla', component: { template: '<div/>' } },
      { path: '/otra', component: { template: '<div/>' } },
    ],
  })
  await router.push(initial)
  await router.isReady()

  return router
}

function mountTable(router: Router, fetcher: DataTableFetcher<Person>, props = {}) {
  const wrapper = mount(DataTable as unknown as Component, {
    props: {
      tableId: 'fixture.url',
      caption: 'Personas',
      columns,
      mode: 'page',
      fetcher,
      filters,
      searchable: true,
      urlState: true,
      emptyTitle: 'Vacío',
      ...props,
    },
    global: { plugins: [i18n, router] },
    attachTo: document.body,
  }) as VueWrapper

  wrappers.push(wrapper)

  return wrapper
}

async function click(el: Element): Promise<void> {
  el.dispatchEvent(new MouseEvent('click', { bubbles: true }))
  await flushPromises()
}

function ariaButton(label: string): HTMLButtonElement {
  const found = document.body.querySelector(`button[aria-label="${label}"]`)

  if (!found) {
    throw new Error(`No hay botón «${label}»`)
  }

  return found as HTMLButtonElement
}

function queries(fetcher: ReturnType<typeof pageFetcher>): DataTableQuery[] {
  return fetcher.mock.calls.map((call) => call[0])
}

beforeEach(() => {
  setLocale('es')
  for (const locale of ['es', 'en', 'de', 'fr'] as const) {
    i18n.global.mergeLocaleMessage(locale, { fixture: { name: 'Nombre', created: 'Creado' } })
  }
  window.localStorage.clear()
})

afterEach(() => {
  while (wrappers.length > 0) {
    wrappers.pop()!.unmount()
  }
  document.body.innerHTML = ''
  vi.useRealTimers()
  window.history.replaceState(null, '', '/')
})

describe('CA-CORE-198 (RN-CORE-54, ADR-054 §6): estado en la URL', () => {
  it('restaura página, orden y filtros desde la URL al montar (recarga o enlace compartido)', async () => {
    const router = await makeRouter('/tabla?page=3&sort=-created_at&status=activo&is_system=true')
    const fetcher = pageFetcher()
    mountTable(router, fetcher)
    await flushPromises()

    expect(queries(fetcher)[0]).toMatchObject({
      page: 3,
      sort: '-created_at',
      filters: { status: 'activo', is_system: 'true' },
    })
  })

  it('refleja en la URL página, orden y filtros, y conserva las claves ajenas a la tabla', async () => {
    const router = await makeRouter('/tabla?redirect=%2Finicio')
    const fetcher = pageFetcher()
    mountTable(router, fetcher)
    await flushPromises()

    await click(ariaButton('Página siguiente'))
    await click(ariaButton('Nombre, ordenar de forma ascendente'))
    await flushPromises()

    const { query } = router.currentRoute.value
    expect(query.sort).toBe('name')
    expect(query.page).toBeUndefined() // el orden vuelve a la página 1: valor por defecto, no se escribe
    expect(query.redirect).toBe('/inicio')
  })

  it('el texto de búsqueda q no aparece nunca en la URL ni en history.state', async () => {
    vi.useFakeTimers()
    const router = await makeRouter('/tabla')
    const fetcher = pageFetcher()
    const wrapper = mountTable(router, fetcher)
    await flushPromises()

    await wrapper.get('input[type="search"]').setValue('López')
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS + 10)
    await flushPromises()

    expect(queries(fetcher).at(-1)!.q).toBe('López')
    expect(window.location.href).not.toContain('L%C3%B3pez')
    expect(window.location.href).not.toContain('López')
    expect(window.location.search).not.toMatch(/(^|[?&])q=/)
    expect(JSON.stringify(window.history.state)).not.toContain('López')
    expect(JSON.stringify(router.currentRoute.value.query)).not.toContain('López')
  })

  it('en modo cursor, tras «cargar más» la URL no contiene cursor y, al recargar, la primera petición no lleva cursor', async () => {
    const router = await makeRouter('/tabla')
    const fetcher = vi.fn<DataTableFetcher<Person>>().mockImplementation(async (query) => ({
      data: [{ public_id: query.cursor ?? 'first', name: 'Ana' }],
      meta: { next_cursor: 'c-1', has_more: true },
    }))
    const wrapper = mountTable(router, fetcher, { mode: 'cursor' })
    await flushPromises()

    const more = [...document.body.querySelectorAll('button')].find(
      (button) => button.textContent?.trim() === 'Cargar más',
    )!
    await click(more)
    await click(ariaButton('Nombre, ordenar de forma ascendente'))

    expect(fetcher.mock.calls.some((call) => call[0].cursor === 'c-1')).toBe(true)
    expect(window.location.search).not.toContain('cursor')
    expect(JSON.stringify(window.history.state)).not.toContain('c-1')

    // «Recarga»: nuevo montaje sobre la misma URL.
    wrapper.unmount()
    wrappers.pop()
    const reloaded = await makeRouter(`${window.location.pathname}${window.location.search}`)
    const fetcherAfter = vi.fn<DataTableFetcher<Person>>().mockResolvedValue({
      data: [],
      meta: { next_cursor: null, has_more: false },
    })
    mountTable(reloaded, fetcherAfter, { mode: 'cursor' })
    await flushPromises()

    expect(fetcherAfter.mock.calls[0]![0].cursor).toBeUndefined()
  })

  it('una tabla que no declara el estado en URL no modifica la URL al paginar, ordenar ni filtrar', async () => {
    const router = await makeRouter('/tabla')
    const fetcher = pageFetcher()
    mountTable(router, fetcher, { urlState: false })
    await flushPromises()
    const before = router.currentRoute.value.fullPath

    await click(ariaButton('Página siguiente'))
    await click(ariaButton('Nombre, ordenar de forma ascendente'))
    const trigger = [...document.body.querySelectorAll('button')].find(
      (button) =>
        button.textContent?.includes('Nombre') && button.getAttribute('aria-haspopup') === 'menu',
    )!
    await click(trigger)
    const item = document.body.querySelector('[role="menuitemcheckbox"]')!
    await click(item)

    expect(queries(fetcher).length).toBeGreaterThan(3)
    expect(router.currentRoute.value.fullPath).toBe(before)
    expect(window.location.search).toBe('')
  })

  it('volver atrás restaura la consulta anterior y la recarga', async () => {
    const router = await makeRouter('/tabla')
    const fetcher = pageFetcher()
    mountTable(router, fetcher)
    await flushPromises()

    await click(ariaButton('Nombre, ordenar de forma ascendente'))
    expect(queries(fetcher).at(-1)!.sort).toBe('name')

    router.back()
    await flushPromises()
    await new Promise((resolve) => setTimeout(resolve, 20))
    await flushPromises()

    expect(queries(fetcher).at(-1)!.sort).toBeUndefined()
  })

  it('como máximo una tabla por ruta refleja su estado: la segunda guarda su consulta en memoria', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined)
    const router = await makeRouter('/tabla')
    const first = pageFetcher()
    const second = pageFetcher()
    mountTable(router, first)
    mountTable(router, second, { tableId: 'fixture.url_two' })
    await flushPromises()

    await click(
      document.querySelectorAll('button[aria-label="Nombre, ordenar de forma ascendente"]')[1]!,
    )

    expect(warn).toHaveBeenCalled()
    expect(queries(second).at(-1)!.sort).toBe('name')
    expect(router.currentRoute.value.query.sort).toBeUndefined()
    warn.mockRestore()
  })
})
