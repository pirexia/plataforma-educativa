/**
 * `docs/modulos/REQ-CORE/funcional.md §13.14`, `§13.18`: exportación
 * asíncrona (`CA-CORE-188` a `-191`, `-202`, `-205`; `RN-CORE-46`/`-49`/`-51`/`-57`,
 * `ADR-054 §7`). El componente solo dispara la solicitud del módulo y
 * consulta el estado; nunca genera el fichero.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { Component } from 'vue'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { i18n, setLocale } from '@/i18n'
import { ApiError } from '@/api/client'
import DataTable from './components/DataTable.vue'
import {
  EXPORT_POLL_INITIAL_MS,
  EXPORT_POLL_MAX_MS,
  EXPORT_POLL_TIMEOUT_MS,
  SEARCH_DEBOUNCE_MS,
} from './constants'
import type {
  DataTableColumn,
  DataTableExportConfig,
  DataTableExportStatus,
  DataTableFetcher,
  DataTableFilter,
} from './types'

interface Person {
  public_id: string
  name: string
}

const columns: DataTableColumn<Person>[] = [
  { id: 'name', headerKey: 'fixture.name', rowHeader: true, sortable: true, card: 'title' },
]

const filters: DataTableFilter[] = [
  {
    type: 'enum',
    id: 'status',
    labelKey: 'fixture.status',
    options: [{ value: 'activo' }, { value: 'inactivo' }],
  },
]

const fetcher: DataTableFetcher<Person> = async () => ({
  data: [{ public_id: 'a', name: 'Ana' }],
  meta: { current_page: 3, per_page: 25, total: 60, last_page: 3 },
})

const wrappers: VueWrapper[] = []

function status(
  overrides: Partial<DataTableExportStatus> & Pick<DataTableExportStatus, 'status'>,
): DataTableExportStatus {
  return {
    public_id: 'exp-1',
    download_url: null,
    expires_at: null,
    error_code: null,
    ...overrides,
  }
}

function mountTable(exportConfig: DataTableExportConfig | undefined, extra = {}) {
  const wrapper = mount(DataTable as unknown as Component, {
    props: {
      tableId: 'fixture.export',
      caption: 'Personas',
      columns,
      mode: 'page',
      fetcher,
      filters,
      searchable: true,
      emptyTitle: 'Vacío',
      exportConfig,
      ...extra,
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

async function filterByActivo(): Promise<void> {
  await click(button('Estado'))
  const item = [...document.body.querySelectorAll('[role="menuitemcheckbox"]')].find((el) =>
    el.textContent?.includes('activo'),
  )
  await click(item!)
  document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
  await flushPromises()
}

beforeEach(() => {
  setLocale('es')
  for (const locale of ['es', 'en', 'de', 'fr'] as const) {
    i18n.global.mergeLocaleMessage(locale, { fixture: { name: 'Nombre', status: 'Estado' } })
  }
  window.localStorage.clear()
})

afterEach(() => {
  while (wrappers.length > 0) {
    wrappers.pop()!.unmount()
  }
  document.body.innerHTML = ''
  vi.useRealTimers()
  vi.restoreAllMocks()
})

describe('CA-CORE-188 (RN-CORE-46, RN-CORE-51, ADR-054 §7.3/§8.1): solicitud de exportación', () => {
  it('con canExport = false no hay control de exportación', async () => {
    const request = vi.fn()
    mountTable({ canExport: false, request, status: vi.fn() })
    await flushPromises()

    expect(() => button('Exportar')).toThrow()
    expect(document.body.textContent).not.toContain('Exportar')
  })

  it('sin configuración de exportación tampoco hay control', async () => {
    mountTable(undefined)
    await flushPromises()

    expect(() => button('Exportar')).toThrow()
  })

  it('envía solo los filtros estructurados: nunca sort, page, per_page, cursor ni q; y se deshabilita en vuelo', async () => {
    let resolveRequest!: (value: { public_id: string }) => void
    const request = vi.fn().mockImplementation(
      () =>
        new Promise((resolve) => {
          resolveRequest = resolve
        }),
    )
    const statusFn = vi.fn().mockReturnValue(new Promise(() => undefined))
    const wrapper = mountTable({ canExport: true, request, status: statusFn })
    await flushPromises()

    await filterByActivo()
    // Orden descendente en pantalla y página 3 (la respuesta simulada dice current_page = 3).
    await click(document.querySelector('button[aria-label="Nombre, ordenar de forma ascendente"]')!)
    await click(
      document.querySelector('button[aria-label="Nombre, ordenar de forma descendente"]')!,
    )
    expect(wrapper.find('th[aria-sort="descending"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Página 3 de 3')

    await click(button('Exportar'))

    expect(request).toHaveBeenCalledTimes(1)
    expect(request.mock.calls[0]![0]).toEqual({ status: 'activo' })
    for (const forbidden of ['sort', 'page', 'per_page', 'cursor', 'q']) {
      expect(request.mock.calls[0]![0]).not.toHaveProperty(forbidden)
    }

    // Mientras la solicitud está en vuelo, el control queda deshabilitado.
    expect(button('Exportar').disabled).toBe(true)
    await click(button('Exportar'))
    expect(request).toHaveBeenCalledTimes(1)

    resolveRequest({ public_id: 'exp-1' })
    await flushPromises()
    expect(button('Exportar').disabled).toBe(false)
  })
})

describe('CA-CORE-205 (RN-CORE-57, ADR-054 §7.4): búsqueda activa', () => {
  it('deshabilita el control (no solo con estilo), explica por qué y, al borrar, solicita con los filtros y sin q', async () => {
    vi.useFakeTimers()
    const request = vi.fn().mockResolvedValue({ public_id: 'exp-1' })
    const wrapper = mountTable({
      canExport: true,
      request,
      status: vi.fn().mockReturnValue(new Promise(() => undefined)),
    })
    await flushPromises()

    vi.useRealTimers()
    await filterByActivo()
    vi.useFakeTimers()

    const input = wrapper.get('input[type="search"]')
    await input.setValue('López')
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS + 10)
    await flushPromises()

    const exportButton = button('Exportar')
    expect(exportButton.disabled).toBe(true)
    await click(exportButton)
    expect(request).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain(
      'Borra la búsqueda para exportar; los demás filtros sí se aplican.',
    )
    expect(exportButton.getAttribute('aria-describedby')).toBeTruthy()

    await input.setValue('')
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS + 10)
    await flushPromises()

    expect(button('Exportar').disabled).toBe(false)
    await click(button('Exportar'))

    expect(request).toHaveBeenCalledTimes(1)
    expect(request.mock.calls[0]![0]).toEqual({ status: 'activo' })
  })
})

describe('CA-CORE-189 (RN-CORE-49): consulta del estado', () => {
  it('una sola consulta en vuelo, intervalos crecientes, role=status y enlace de descarga al completar', async () => {
    vi.useFakeTimers()
    let inFlight = 0
    let maxInFlight = 0
    const timestamps: number[] = []
    const responses = [
      status({ status: 'pendiente' }),
      status({ status: 'generando' }),
      status({
        status: 'completada',
        download_url: 'https://files.example.com/signed?x=1',
        expires_at: '2026-10-07T09:00:00Z',
      }),
    ]
    const statusFn = vi.fn().mockImplementation(async () => {
      inFlight += 1
      maxInFlight = Math.max(maxInFlight, inFlight)
      timestamps.push(Date.now())
      await Promise.resolve()
      inFlight -= 1

      return responses.shift()
    })
    const request = vi.fn().mockResolvedValue({ public_id: 'exp-1' })
    const wrapper = mountTable({ canExport: true, request, status: statusFn })
    await flushPromises()

    await click(button('Exportar'))
    const requestedAt = Date.now()

    expect(wrapper.find('[role="status"]').text()).toContain('Preparando exportación…')
    expect(statusFn).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(EXPORT_POLL_INITIAL_MS)
    await vi.advanceTimersByTimeAsync(EXPORT_POLL_INITIAL_MS * 2)
    await vi.advanceTimersByTimeAsync(EXPORT_POLL_INITIAL_MS * 4)
    await flushPromises()

    expect(statusFn).toHaveBeenCalledTimes(3)
    expect(maxInFlight).toBe(1)
    const gaps = timestamps.map((time, index) => time - (timestamps[index - 1] ?? requestedAt))
    expect(gaps[1]!).toBeGreaterThan(gaps[0]!)
    expect(gaps[2]!).toBeGreaterThan(gaps[1]!)

    const link = wrapper.get('a[href="https://files.example.com/signed?x=1"]')
    expect(link.text()).toBe('Descargar exportación')
    expect(wrapper.text()).toContain('Caduca el')
    // No se genera ningún fichero en el cliente (RN-CORE-46).
    expect(wrapper.find('a[download]').exists()).toBe(false)
  })

  it('la espera se duplica hasta el máximo de 30 s', async () => {
    vi.useFakeTimers()
    const timestamps: number[] = []
    const statusFn = vi.fn().mockImplementation(async () => {
      timestamps.push(Date.now())

      return status({ status: 'pendiente' })
    })
    mountTable({
      canExport: true,
      request: vi.fn().mockResolvedValue({ public_id: 'exp-1' }),
      status: statusFn,
    })
    await flushPromises()
    await click(button('Exportar'))
    const start = Date.now()

    await vi.advanceTimersByTimeAsync(EXPORT_POLL_MAX_MS * 4)

    const gaps = timestamps.map((time, index) => time - (timestamps[index - 1] ?? start))
    expect(gaps.slice(0, 5)).toEqual([2000, 4000, 8000, 16000, 30000])
    expect(Math.max(...gaps)).toBe(EXPORT_POLL_MAX_MS)
  })
})

describe('CA-CORE-190: errores de la exportación', () => {
  it('fallida con error_code muestra su traducción con role=alert', async () => {
    vi.useFakeTimers()
    const wrapper = mountTable({
      canExport: true,
      request: vi.fn().mockResolvedValue({ public_id: 'exp-1' }),
      status: vi
        .fn()
        .mockResolvedValue(
          status({ status: 'fallida', error_code: 'core.export.generation_failed' }),
        ),
    })
    await flushPromises()
    await click(button('Exportar'))
    await vi.advanceTimersByTimeAsync(EXPORT_POLL_INITIAL_MS)
    await flushPromises()

    const alert = wrapper.get('[data-slot="data-table-export-status"] [role="alert"]')
    expect(alert.text()).toBe('No se ha podido generar la exportación. Inténtalo de nuevo.')
  })

  it('409 se trata como «aún no está lista» y se sigue esperando, sin error', async () => {
    vi.useFakeTimers()
    const statusFn = vi
      .fn()
      .mockRejectedValueOnce(new ApiError('409', 409, { type: 'urn:pge:error:conflict' }))
      .mockResolvedValue(status({ status: 'pendiente' }))
    const wrapper = mountTable({
      canExport: true,
      request: vi.fn().mockResolvedValue({ public_id: 'exp-1' }),
      status: statusFn,
    })
    await flushPromises()
    await click(button('Exportar'))
    await vi.advanceTimersByTimeAsync(EXPORT_POLL_INITIAL_MS)
    await flushPromises()

    expect(wrapper.find('[data-slot="data-table-export-status"] [role="alert"]').exists()).toBe(
      false,
    )
    await vi.advanceTimersByTimeAsync(EXPORT_POLL_INITIAL_MS * 2)
    expect(statusFn).toHaveBeenCalledTimes(2)
  })

  it('410 muestra el mensaje de caducada con la acción de volver a solicitar', async () => {
    vi.useFakeTimers()
    const request = vi.fn().mockResolvedValue({ public_id: 'exp-1' })
    const wrapper = mountTable({
      canExport: true,
      request,
      status: vi.fn().mockRejectedValue(new ApiError('410', 410, {})),
    })
    await flushPromises()
    await click(button('Exportar'))
    await vi.advanceTimersByTimeAsync(EXPORT_POLL_INITIAL_MS)
    await flushPromises()

    expect(wrapper.text()).toContain('La exportación ha caducado; vuelve a solicitarla.')
    await click(button('Volver a solicitar'))
    expect(request).toHaveBeenCalledTimes(2)
  })

  it('422 en la solicitud muestra el message del servidor', async () => {
    const wrapper = mountTable({
      canExport: true,
      request: vi.fn().mockRejectedValue(
        new ApiError('422', 422, {
          type: 'urn:pge:error:validation',
          errors: {
            from: [{ code: 'too_many_rows', message: 'Demasiadas filas: acota el rango.' }],
          },
        }),
      ),
      status: vi.fn(),
    })
    await flushPromises()
    await click(button('Exportar'))

    const alert = wrapper.get('[data-slot="data-table-export-status"] [role="alert"]')
    expect(alert.text()).toBe('Demasiadas filas: acota el rango.')
  })
})

describe('CA-CORE-191 (RN-CORE-49, issue #128): sin worker de colas', () => {
  it('a los 10 min deja de consultar y ofrece «Comprobar de nuevo», que reinicia el ciclo', async () => {
    vi.useFakeTimers()
    const statusFn = vi.fn().mockResolvedValue(status({ status: 'pendiente' }))
    const wrapper = mountTable({
      canExport: true,
      request: vi.fn().mockResolvedValue({ public_id: 'exp-1' }),
      status: statusFn,
    })
    await flushPromises()
    await click(button('Exportar'))

    await vi.advanceTimersByTimeAsync(EXPORT_POLL_TIMEOUT_MS + 60_000)
    await flushPromises()

    const callsAtStop = statusFn.mock.calls.length
    expect(callsAtStop).toBeGreaterThan(0)
    expect(wrapper.text()).toContain('La exportación tarda más de lo esperado.')

    await vi.advanceTimersByTimeAsync(EXPORT_POLL_TIMEOUT_MS)
    expect(statusFn).toHaveBeenCalledTimes(callsAtStop)

    await click(button('Comprobar de nuevo'))
    expect(statusFn).toHaveBeenCalledTimes(callsAtStop + 1)
    expect(wrapper.text()).toContain('Preparando exportación…')
  })

  it('al desmontar la vista con una consulta programada, no sale ninguna petición más', async () => {
    vi.useFakeTimers()
    const statusFn = vi.fn().mockResolvedValue(status({ status: 'pendiente' }))
    const wrapper = mountTable({
      canExport: true,
      request: vi.fn().mockResolvedValue({ public_id: 'exp-1' }),
      status: statusFn,
    })
    await flushPromises()
    await click(button('Exportar'))

    wrapper.unmount()
    wrappers.pop()
    await vi.advanceTimersByTimeAsync(EXPORT_POLL_TIMEOUT_MS)

    expect(statusFn).not.toHaveBeenCalled()
  })
})

describe('CA-CORE-202 (OPEN-CORE-25, ADR-054 §7.7): salir de la vista', () => {
  it('mientras está pendiente o generando muestra el aviso permanente; completada, fallida o sin exportación, no', async () => {
    vi.useFakeTimers()
    const responses = [
      status({ status: 'generando' }),
      status({
        status: 'completada',
        download_url: 'https://files.example.com/x',
        expires_at: null,
      }),
    ]
    const wrapper = mountTable({
      canExport: true,
      request: vi.fn().mockResolvedValue({ public_id: 'exp-1' }),
      status: vi.fn().mockImplementation(async () => responses.shift()),
    })
    await flushPromises()

    const warning = () => wrapper.find('[data-slot="data-table-export-leave-warning"]')
    expect(warning().exists()).toBe(false)

    await click(button('Exportar'))
    expect(warning().exists()).toBe(true)
    expect(warning().text()).toContain('si sales de esta vista no podrás descargarla desde aquí')

    await vi.advanceTimersByTimeAsync(EXPORT_POLL_INITIAL_MS)
    await flushPromises()
    expect(warning().exists()).toBe(true)

    await vi.advanceTimersByTimeAsync(EXPORT_POLL_INITIAL_MS * 2)
    await flushPromises()
    expect(wrapper.find('a[href="https://files.example.com/x"]').exists()).toBe(true)
    expect(warning().exists()).toBe(false)
  })

  it('la navegación a otra ruta se produce sin retención y no hay ningún manejador de beforeunload', async () => {
    const addListener = vi.spyOn(window, 'addEventListener')
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', component: { template: '<div/>' } },
        { path: '/otra', component: { template: '<div/>' } },
      ],
    })
    await router.push('/')
    await router.isReady()

    const wrapper = mount(DataTable as unknown as Component, {
      props: {
        tableId: 'fixture.export',
        caption: 'Personas',
        columns,
        mode: 'page',
        fetcher,
        emptyTitle: 'Vacío',
        exportConfig: {
          canExport: true,
          request: vi.fn().mockResolvedValue({ public_id: 'exp-1' }),
          status: vi.fn().mockReturnValue(new Promise(() => undefined)),
        },
      },
      global: { plugins: [i18n, router] },
      attachTo: document.body,
    }) as VueWrapper
    wrappers.push(wrapper)
    await flushPromises()
    await click(button('Exportar'))
    expect(wrapper.find('[data-slot="data-table-export-leave-warning"]').exists()).toBe(true)

    const failure = await router.push('/otra')

    expect(failure).toBeUndefined()
    expect(router.currentRoute.value.path).toBe('/otra')
    expect(window.onbeforeunload).toBeNull()
    expect(addListener.mock.calls.filter((call) => call[0] === 'beforeunload')).toEqual([])
  })
})
