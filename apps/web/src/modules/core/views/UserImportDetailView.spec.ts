/**
 * `docs/modulos/REQ-CORE/funcional.md §14.6.2`, `§14.18`: detalle de un lote de
 * importación (1.9c) — `CA-CORE-234` (seguimiento del estado con la política de
 * `RN-CORE-49`), `-235` (ejecución con confirmación e `Idempotency-Key` estable
 * por confirmación), `-236` (`Idempotency-Replayed` y `409`), `-237`
 * (incidencias con el componente de tablas, aviso de 50, informe y lote
 * `fallido` por cabecera), más descartar, renovación del enlace del informe y
 * `404`.
 *
 * Los temporizadores se simulan (`setTimeout`/`setInterval`/`Date`): el seguimiento
 * espera 2 s duplicando hasta 30 s, y se prueba con las constantes exportadas de
 * `src/data-table`, no con cifras (`RN-CORE-49`).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { i18n, setLocale } from '@/i18n'
import { ApiError } from '@/api/client'
import { EXPORT_POLL_INITIAL_MS, EXPORT_POLL_MAX_MS, EXPORT_POLL_TIMEOUT_MS } from '@/data-table'
import { USER_IMPORT_HEADER } from '../userImportHeader'

const getUserImport = vi.fn()
const executeUserImport = vi.fn()
const deleteUserImport = vi.fn()

vi.mock('../api', () => ({
  getUserImport: (...args: unknown[]) => getUserImport(...args),
  executeUserImport: (...args: unknown[]) => executeUserImport(...args),
  deleteUserImport: (...args: unknown[]) => deleteUserImport(...args),
}))

const { takeFlash } = await import('../composables/useFlash')
const { default: UserImportDetailView } = await import('./UserImportDetailView.vue')

const ULID = /^[0-7][0-9A-HJKMNP-TV-Z]{25}$/

function lot(overrides: Record<string, unknown> = {}) {
  return {
    public_id: 'IMP1',
    original_filename: 'personal.csv',
    status: 'validado',
    send_invitations: true,
    row_count: 5,
    error_count: 2,
    created_count: null,
    error_summary: [
      { line: 3, column: 'email', code: 'duplicado_en_fichero', message: 'Correo repetido.' },
      {
        line: 5,
        column: 'document_number',
        code: 'formato_invalido',
        message: 'Formato no válido.',
      },
    ],
    report_url: 'https://files.example.com/report.csv?sig=abc',
    created_at: '2026-09-01T10:00:00Z',
    validated_at: '2026-09-01T10:01:00Z',
    executed_at: null,
    ...overrides,
  }
}

function problem(status: number, body: Record<string, unknown> = {}, headers?: Headers): ApiError {
  return new ApiError(`HTTP ${status}`, status, { status, ...body }, headers)
}

const wrappers: VueWrapper[] = []

async function tick(ms = 0): Promise<void> {
  await vi.advanceTimersByTimeAsync(ms)
  await flushPromises()
}

async function mountView(publicId = 'IMP1'): Promise<{ wrapper: VueWrapper; router: Router }> {
  const stub = { template: '<div/>' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/administracion/importaciones', name: 'core-user-imports', component: stub },
      {
        path: '/administracion/importaciones/:publicId',
        name: 'core-user-import-detail',
        component: UserImportDetailView,
      },
    ],
  })

  await router.push(`/administracion/importaciones/${publicId}`)
  await router.isReady()

  const wrapper = mount(UserImportDetailView, {
    global: { plugins: [i18n, router] },
    attachTo: document.body,
  }) as VueWrapper

  wrappers.push(wrapper)
  await tick()

  return { wrapper, router }
}

function button(label: string): HTMLButtonElement | undefined {
  return [...document.body.querySelectorAll('button')].find(
    (candidate) => (candidate.textContent ?? '').trim() === label,
  )
}

function dialogButton(label: string): HTMLButtonElement | undefined {
  return [...document.body.querySelectorAll('[role="alertdialog"] button')].find(
    (candidate) => (candidate.textContent ?? '').trim() === label,
  ) as HTMLButtonElement | undefined
}

async function click(el: Element | undefined): Promise<void> {
  if (!el) {
    throw new Error('Control inexistente')
  }

  el.dispatchEvent(new MouseEvent('click', { bubbles: true }))
  await tick()
}

function statusText(): string {
  return document.querySelector('[data-testid="user-import-status"]')?.textContent?.trim() ?? ''
}

beforeEach(() => {
  vi.useFakeTimers({
    toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'Date'],
  })
  setLocale('es')
  getUserImport.mockReset().mockResolvedValue(lot())
  executeUserImport.mockReset().mockResolvedValue(lot({ status: 'ejecutando' }))
  deleteUserImport.mockReset().mockResolvedValue(undefined)
  takeFlash()
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
  vi.useRealTimers()
})

describe('CA-CORE-234 (RN-CORE-72, RN-CORE-49): seguimiento del estado', () => {
  it('subido → validando → validado: espera creciente, el estado se anuncia con role="status" y tras validado no sale ninguna consulta más', async () => {
    getUserImport
      .mockResolvedValueOnce(lot({ status: 'subido', error_summary: null, row_count: null }))
      .mockResolvedValueOnce(lot({ status: 'validando', error_summary: null, row_count: null }))
      .mockResolvedValue(lot())

    await mountView()

    expect(getUserImport).toHaveBeenCalledTimes(1)
    expect(statusText()).toContain('Subido')
    expect(document.querySelector('[data-testid="user-import-status"]')?.getAttribute('role')).toBe(
      'status',
    )

    // Primera espera: la inicial.
    await tick(EXPORT_POLL_INITIAL_MS - 1)
    expect(getUserImport).toHaveBeenCalledTimes(1)
    await tick(1)
    expect(getUserImport).toHaveBeenCalledTimes(2)
    expect(statusText()).toContain('Validando')

    // Segunda espera: el doble.
    await tick(EXPORT_POLL_INITIAL_MS * 2 - 1)
    expect(getUserImport).toHaveBeenCalledTimes(2)
    await tick(1)
    expect(getUserImport).toHaveBeenCalledTimes(3)
    expect(statusText()).toContain('Validado')

    // Validado: no sale ninguna consulta más.
    await tick(EXPORT_POLL_TIMEOUT_MS)
    expect(getUserImport).toHaveBeenCalledTimes(3)
  })

  it('la espera crece hasta el máximo y nunca hay dos consultas en vuelo', async () => {
    getUserImport.mockResolvedValue(lot({ status: 'validando', error_summary: null }))
    await mountView()

    // Consultas lentas: la respuesta no llega hasta que se libera.
    let release: (value: unknown) => void = () => undefined
    let inFlight = 0
    let maxInFlight = 0

    getUserImport.mockImplementation(() => {
      inFlight += 1
      maxInFlight = Math.max(maxInFlight, inFlight)

      return new Promise((resolve) => {
        release = (value) => {
          inFlight -= 1
          resolve(value)
        }
      })
    })

    getUserImport.mockClear()

    await tick(EXPORT_POLL_INITIAL_MS)
    expect(getUserImport).toHaveBeenCalledTimes(1)

    // Mientras la consulta está en vuelo no se lanza otra, pase lo que pase.
    await tick(EXPORT_POLL_MAX_MS * 3)
    expect(getUserImport).toHaveBeenCalledTimes(1)
    expect(maxInFlight).toBe(1)

    release(lot({ status: 'validando', error_summary: null }))
    await tick()

    // Segunda espera: el doble de la inicial.
    await tick(EXPORT_POLL_INITIAL_MS * 2 - 1)
    expect(getUserImport).toHaveBeenCalledTimes(1)
    await tick(1)
    expect(getUserImport).toHaveBeenCalledTimes(2)
    expect(maxInFlight).toBe(1)
  })

  it('con respuestas inmediatas las esperas se duplican y se quedan en el máximo', async () => {
    getUserImport.mockResolvedValue(lot({ status: 'subido', error_summary: null }))
    await mountView()
    getUserImport.mockClear()

    const delays: number[] = []
    let delay = EXPORT_POLL_INITIAL_MS

    for (let index = 0; index < 7; index++) {
      delays.push(delay)
      delay = Math.min(delay * 2, EXPORT_POLL_MAX_MS)
    }

    for (const [index, wait] of delays.entries()) {
      await tick(wait - 1)
      expect(getUserImport, `antes de la consulta ${index + 1}`).toHaveBeenCalledTimes(index)
      await tick(1)
      expect(getUserImport, `en la consulta ${index + 1}`).toHaveBeenCalledTimes(index + 1)
    }

    expect(delays.at(-1)).toBe(EXPORT_POLL_MAX_MS)
  })

  it('un lote que sigue en subido hasta agotar la duración máxima ofrece «Comprobar de nuevo» y deja de consultar', async () => {
    getUserImport.mockResolvedValue(
      lot({ status: 'subido', error_summary: null, report_url: null }),
    )
    await mountView()

    await tick(EXPORT_POLL_TIMEOUT_MS + EXPORT_POLL_MAX_MS)

    expect(button('Comprobar de nuevo')).toBeDefined()

    const calls = getUserImport.mock.calls.length

    await tick(EXPORT_POLL_TIMEOUT_MS)
    expect(getUserImport.mock.calls.length).toBe(calls)

    // «Comprobar de nuevo» reinicia el ciclo y consulta ya.
    await click(button('Comprobar de nuevo'))
    expect(getUserImport.mock.calls.length).toBe(calls + 1)
    expect(button('Comprobar de nuevo')).toBeUndefined()
  })

  it('se detiene al desmontar la vista', async () => {
    getUserImport.mockResolvedValue(lot({ status: 'validando', error_summary: null }))
    const { wrapper } = await mountView()

    wrapper.unmount()
    wrappers.pop()
    getUserImport.mockClear()

    await tick(EXPORT_POLL_TIMEOUT_MS)
    expect(getUserImport).not.toHaveBeenCalled()
  })

  it('no consulta si el lote ya está validado, fallido o completado', async () => {
    for (const status of ['validado', 'fallido', 'completado']) {
      getUserImport.mockReset().mockResolvedValue(lot({ status }))
      const { wrapper } = await mountView()

      await tick(EXPORT_POLL_TIMEOUT_MS)
      expect(getUserImport, status).toHaveBeenCalledTimes(1)

      wrapper.unmount()
      wrappers.pop()
    }
  })
})

describe('CA-CORE-235 (RN-CORE-73, INV-011): ejecución con confirmación e Idempotency-Key', () => {
  it('la confirmación dice cuántas filas se crearán, que las erróneas se omiten, las invitaciones y que no se deshace', async () => {
    await mountView()

    await click(button('Ejecutar'))

    const text = document.querySelector('[role="alertdialog"]')?.textContent ?? ''

    expect(text).toContain('Usuarios que se crearán: 3')
    expect(text).toContain('Las filas con error se omiten')
    expect(text).toContain('Se enviarán invitaciones')
    expect(text).toContain('Una importación no se deshace')
    expect(executeUserImport).not.toHaveBeenCalled()
  })

  it('si el lote se subió sin invitaciones (send_invitations de la API), la confirmación lo dice', async () => {
    getUserImport.mockResolvedValue(lot({ send_invitations: false }))
    await mountView()

    await click(button('Ejecutar'))

    const text = document.querySelector('[role="alertdialog"]')?.textContent ?? ''

    expect(text).toContain('No se enviarán invitaciones')
    expect(text).not.toContain('Se enviarán invitaciones')
  })

  it('al confirmar sale una petición con una Idempotency-Key ULID; si falla por red y se reintenta, lleva la misma; una confirmación nueva lleva otra', async () => {
    executeUserImport.mockRejectedValueOnce(new ApiError('Network', 0, null))
    await mountView()

    await click(button('Ejecutar'))
    expect(executeUserImport).not.toHaveBeenCalled()
    await click(dialogButton('Ejecutar la importación de personal.csv'))

    expect(executeUserImport).toHaveBeenCalledTimes(1)

    const firstKey = executeUserImport.mock.calls[0]![1] as string

    expect(firstKey).toMatch(ULID)
    expect(executeUserImport.mock.calls[0]![0]).toBe('IMP1')
    expect(document.body.querySelector('[role="alert"]')).not.toBeNull()

    // Reintento de la misma confirmación: misma clave, sin nuevo diálogo.
    executeUserImport.mockRejectedValueOnce(problem(503))
    await click(button('Reintentar la ejecución'))
    expect(executeUserImport).toHaveBeenCalledTimes(2)
    expect(executeUserImport.mock.calls[1]![1]).toBe(firstKey)
    expect(document.querySelector('[role="alertdialog"]')).toBeNull()

    // Una confirmación posterior lleva una clave distinta.
    await click(button('Ejecutar'))
    await click(dialogButton('Ejecutar la importación de personal.csv'))
    expect(executeUserImport).toHaveBeenCalledTimes(3)

    const thirdKey = executeUserImport.mock.calls[2]![1] as string

    expect(thirdKey).toMatch(ULID)
    expect(thirdKey).not.toBe(firstKey)
  })

  it('cancelar la confirmación no envía nada', async () => {
    await mountView()

    await click(button('Ejecutar'))
    await click(dialogButton('Cancelar'))

    expect(executeUserImport).not.toHaveBeenCalled()
  })

  it('«Ejecutar» solo existe con el lote validado', async () => {
    for (const status of ['subido', 'validando', 'fallido', 'ejecutando', 'completado']) {
      getUserImport.mockReset().mockResolvedValue(lot({ status, error_summary: null }))
      const { wrapper } = await mountView()

      expect(button('Ejecutar'), status).toBeUndefined()

      wrapper.unmount()
      wrappers.pop()
    }
  })
})

describe('CA-CORE-236 (RN-CORE-73): Idempotency-Replayed y 409', () => {
  it('una respuesta 202 (también con Idempotency-Replayed) se trata como éxito y pasa a seguir el estado', async () => {
    getUserImport
      .mockResolvedValueOnce(lot())
      .mockResolvedValue(lot({ status: 'completado', created_count: 3 }))
    await mountView()

    await click(button('Ejecutar'))
    await click(dialogButton('Ejecutar la importación de personal.csv'))

    expect(statusText()).toContain('Ejecutando')
    expect(document.body.textContent).toContain('Importación en marcha')
    expect(button('Reintentar la ejecución')).toBeUndefined()

    // Sigue el estado hasta completado.
    await tick(EXPORT_POLL_INITIAL_MS)
    expect(statusText()).toContain('Completado')
  })

  it('un 409 muestra el detail del servidor, no ofrece reintentar y refresca el lote', async () => {
    executeUserImport.mockRejectedValue(
      problem(409, { detail: 'El lote debe estar validado antes de ejecutarse.' }),
    )
    await mountView()
    getUserImport.mockClear()

    await click(button('Ejecutar'))
    await click(dialogButton('Ejecutar la importación de personal.csv'))

    expect(document.body.querySelector('[role="alert"]')?.textContent).toContain(
      'El lote debe estar validado antes de ejecutarse.',
    )
    expect(button('Reintentar la ejecución')).toBeUndefined()
    expect(executeUserImport).toHaveBeenCalledTimes(1)
    expect(getUserImport).toHaveBeenCalledTimes(1)
  })

  it('un 429 muestra los segundos de Retry-After', async () => {
    executeUserImport.mockRejectedValue(problem(429, {}, new Headers({ 'Retry-After': '17' })))
    await mountView()

    await click(button('Ejecutar'))
    await click(dialogButton('Ejecutar la importación de personal.csv'))

    expect(document.body.querySelector('[role="alert"]')?.textContent).toContain('17')
  })
})

describe('CA-CORE-237 (RN-CORE-74, RN-CORE-53): incidencias', () => {
  function manyErrors(count: number) {
    return Array.from({ length: count }, (_, index) => ({
      line: index + 2,
      column: 'email',
      code: 'formato_invalido',
      message: `Motivo ${index + 2}`,
    }))
  }

  it('con error_count = 60 y 50 entradas pinta las incidencias con el componente de tablas, el aviso de las 50 primeras y el enlace al informe', async () => {
    getUserImport.mockResolvedValue(
      lot({ error_count: 60, row_count: 80, error_summary: manyErrors(50) }),
    )
    await mountView()

    const table = document.body.querySelector('table')

    expect(table).not.toBeNull()
    expect(table!.querySelectorAll('tbody tr')).toHaveLength(50)

    const headers = [...table!.querySelectorAll('thead th')].map((th) => th.textContent?.trim())

    expect(headers).toEqual(['Línea', 'Columna', 'Motivo'])
    expect(table!.querySelector('tbody tr')!.textContent).toContain('Motivo 2')

    expect(document.body.querySelector('[role="note"]')?.textContent).toContain('50')
    expect(document.body.querySelector('[role="note"]')?.textContent).toContain('60')

    const report = [...document.body.querySelectorAll('a')].find(
      (anchor) => anchor.textContent?.trim() === 'Descargar el informe completo',
    )!

    expect(report.getAttribute('href')).toBe('https://files.example.com/report.csv?sig=abc')
  })

  it('30 filas con 2 errores cada una: 50 entradas con error_count = 30 también muestran el aviso de las 50 primeras (RN-CORE-74)', async () => {
    getUserImport.mockResolvedValue(
      lot({ error_count: 30, row_count: 80, error_summary: manyErrors(50) }),
    )
    await mountView()

    expect(document.body.querySelector('[role="note"]')).not.toBeNull()
  })

  it('con menos de 50 entradas y error_count igual a las entradas no hay aviso', async () => {
    getUserImport.mockResolvedValue(
      lot({ error_count: 49, row_count: 80, error_summary: manyErrors(49) }),
    )
    await mountView()

    expect(document.body.querySelector('[role="note"]')).toBeNull()
  })

  it('sin truncar no hay aviso de las 50 primeras', async () => {
    await mountView()

    expect(document.body.querySelector('[role="note"]')).toBeNull()
    expect(document.body.querySelectorAll('table tbody tr')).toHaveLength(2)
  })

  it('el enlace del informe solo admite http(s)', async () => {
    getUserImport.mockResolvedValue(lot({ report_url: 'javascript:alert(1)' }))
    await mountView()

    expect(
      [...document.body.querySelectorAll('a')].some(
        (anchor) => anchor.textContent?.trim() === 'Descargar el informe completo',
      ),
    ).toBe(false)
  })

  it('un lote fallido por cabecera muestra el motivo y la cabecera esperada, sin «Ejecutar» y con «Descartar»', async () => {
    getUserImport.mockResolvedValue(
      lot({
        status: 'fallido',
        row_count: 0,
        error_count: 1,
        error_summary: [
          {
            line: 1,
            column: 'header',
            code: 'cabecera_desconocida',
            message: 'La cabecera del fichero no coincide con el formato esperado.',
          },
        ],
      }),
    )
    await mountView()

    expect(button('Ejecutar')).toBeUndefined()
    expect(button('Descartar')).toBeDefined()
    expect(document.body.textContent).toContain('La cabecera del fichero no coincide')
    expect(document.body.textContent).toContain(USER_IMPORT_HEADER)
  })

  it('el motivo es message tal como llega, sea cual sea su idioma', async () => {
    getUserImport.mockResolvedValue(
      lot({
        error_summary: [
          {
            line: 2,
            column: 'email',
            code: 'duplicado_en_fichero',
            message: "La valeur apparaît plus d'une fois.",
          },
        ],
        error_count: 1,
      }),
    )
    await mountView()

    expect(document.body.querySelector('table tbody')?.textContent).toContain(
      "La valeur apparaît plus d'une fois.",
    )
  })
})

describe('descartar (§14.6.2)', () => {
  it('solo en subido, validando, validado y fallido, con confirmación; al terminar vuelve al listado con un mensaje', async () => {
    const { router } = await mountView()

    await click(button('Descartar'))
    expect(deleteUserImport).not.toHaveBeenCalled()
    await click(dialogButton('Descartar la importación de personal.csv'))

    expect(deleteUserImport).toHaveBeenCalledWith('IMP1')
    expect(router.currentRoute.value.name).toBe('core-user-imports')
    expect(takeFlash()?.key).toBe('core.userImports.flash.discarded')
  })

  it('no se ofrece en un lote ejecutando o completado', async () => {
    for (const status of ['ejecutando', 'completado']) {
      getUserImport.mockReset().mockResolvedValue(lot({ status, error_summary: null }))
      const { wrapper } = await mountView()

      expect(button('Descartar'), status).toBeUndefined()

      wrapper.unmount()
      wrappers.pop()
    }
  })

  it('un 409 muestra el detail y refresca el lote', async () => {
    deleteUserImport.mockRejectedValue(
      problem(409, {
        detail: 'Este lote ya se ha ejecutado o se está ejecutando y no se puede descartar.',
      }),
    )
    const { router } = await mountView()
    getUserImport.mockClear()

    await click(button('Descartar'))
    await click(dialogButton('Descartar la importación de personal.csv'))

    expect(document.body.querySelector('[role="alert"]')?.textContent).toContain(
      'ya se ha ejecutado',
    )
    expect(router.currentRoute.value.name).toBe('core-user-import-detail')
    expect(getUserImport).toHaveBeenCalledTimes(1)
  })
})

describe('informe caducado (§14.6.2)', () => {
  it('si han pasado más de 10 min desde la última respuesta, vuelve a pedir el detalle', async () => {
    await mountView()
    getUserImport.mockClear()

    await tick(9 * 60 * 1000)
    expect(getUserImport).not.toHaveBeenCalled()

    await tick(2 * 60 * 1000)
    expect(getUserImport).toHaveBeenCalledTimes(1)
  })

  it('«Actualizar el enlace» vuelve a pedir el detalle y sin informe no se renueva nada', async () => {
    await mountView()
    getUserImport.mockClear()

    await click(button('Actualizar el enlace'))
    expect(getUserImport).toHaveBeenCalledTimes(1)

    getUserImport.mockReset().mockResolvedValue(lot({ report_url: null }))
    await click(button('Actualizar el enlace'))
    getUserImport.mockClear()
    await tick(30 * 60 * 1000)
    expect(getUserImport).not.toHaveBeenCalled()
  })
})

describe('errores de carga (CA-CORE-263)', () => {
  it('un 404 pinta «no encontrado»', async () => {
    getUserImport.mockRejectedValue(problem(404))
    await mountView('OTRO')

    expect(document.body.querySelector('[role="alert"]')?.textContent).toContain(
      'Página no encontrada',
    )
    expect(button('Ejecutar')).toBeUndefined()
  })

  it('un 403 pinta «sin acceso»', async () => {
    getUserImport.mockRejectedValue(problem(403))
    await mountView('OTRO')

    expect(document.body.querySelector('[role="alert"]')?.textContent).toContain('Sin acceso')
  })
})
