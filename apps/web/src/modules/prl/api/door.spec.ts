/**
 * `docs/modulos/REQ-PRL/api.md §8.4`-`§8.7` (`1.14b-c2`, `REQ-PRL-004`): rutas, métodos y parámetros del cliente de la
 * puerta. El identificador de ruta se codifica; las lecturas con datos de personas van sin caché del navegador (UI-3).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const apiFetch = vi.fn()

vi.mock('@/api/client', () => ({
  apiFetch: (...args: unknown[]) => apiFetch(...args),
}))

const api = await import('./index')

const HOSTILE = '01ABC/../x?y=1#z'
const ENCODED = encodeURIComponent(HOSTILE)

beforeEach(() => {
  apiFetch.mockReset().mockResolvedValue({})
})

function last(): [string, RequestInit | undefined] {
  return apiFetch.mock.calls.at(-1) as [string, RequestInit | undefined]
}

describe('REQ-PRL-004 (api.md §8.4): cliente de lectura de la puerta', () => {
  it('busca alumnos con q codificado, sin caché y con la señal de cancelación', async () => {
    const controller = new AbortController()

    await api.searchDoorStudents('Lu & ñ', controller.signal)

    expect(last()[0]).toBe('/door/students?q=Lu+%26+%C3%B1')
    expect(last()[1]).toMatchObject({ cache: 'no-store', signal: controller.signal })
  })

  it('lee la lista de puerta con el id codificado y sin caché', async () => {
    await api.getDoorPickupList(HOSTILE)

    expect(last()[0]).toBe(`/door/students/${ENCODED}/pickup-list`)
    expect(last()[1]).toMatchObject({ cache: 'no-store' })
  })

  it('lista alertas con filtros y paginación, sin claves vacías', async () => {
    await api.listPickupAlerts({ status: 'abierta', page: 2, per_page: 25, date: undefined })

    expect(last()[0]).toBe('/pickup-alerts?status=abierta&page=2&per_page=25')

    await api.listPickupAlerts()

    expect(last()[0]).toBe('/pickup-alerts')
  })

  it('lee una alerta y un movimiento con el id codificado', async () => {
    await api.getPickupAlert(HOSTILE)
    expect(last()[0]).toBe(`/pickup-alerts/${ENCODED}`)

    await api.getStudentMovement(HOSTILE)
    expect(last()[0]).toBe(`/student-movements/${ENCODED}`)
  })

  it('lista movimientos con cursor y filtros', async () => {
    await api.listStudentMovements({
      student_person: 'P1',
      direction: 'salida',
      off_schedule: false,
      cursor: 'c',
      limit: 50,
    })

    expect(last()[0]).toBe(
      '/student-movements?student_person=P1&direction=salida&off_schedule=false&cursor=c&limit=50',
    )
  })
})
