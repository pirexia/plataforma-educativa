/**
 * `docs/modulos/REQ-ACAD/api.md §12` (1.12-b), `CA-ACAD-138`/`CA-ACAD-139`: rutas,
 * métodos y cuerpos del cliente de los cuatro endpoints de `/facility-bookings`;
 * el identificador de ruta se codifica (#385), el filtro múltiple de espacios viaja
 * separado por comas (`ADR-038 §5.2`), las reservas no se editan (sin `PATCH`,
 * `RN-ACAD-142`) y el alta lleva `academic_year` en el cuerpo (`RN-ACAD-140`).
 * `REQ-ACAD-002`.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const apiFetch = vi.fn()

vi.mock('@/api/client', () => ({
  apiFetch: (...args: unknown[]) => apiFetch(...args),
}))

const api = await import('./bookings')

const HOSTILE = '01ABC/../x?y=1#z'
const ENCODED = encodeURIComponent(HOSTILE)

beforeEach(() => {
  apiFetch.mockReset().mockResolvedValue({})
})

function last(): [string, { method?: string; body?: string } | undefined] {
  return apiFetch.mock.calls.at(-1) as [string, { method?: string; body?: string } | undefined]
}

describe('REQ-ACAD-002 (api.md §12.2, §12.4): reservas de espacios', () => {
  it('GET /facility-bookings con filtros, fechas y paginación; los vacíos se omiten', async () => {
    await api.listFacilityBookings({
      academic_year: 'Y2',
      facility: ['F1', 'F2'],
      booked_from: '2026-11-01',
      booked_to: '2026-11-30',
      page: 2,
      per_page: 50,
    })
    expect(last()[0]).toBe(
      '/facility-bookings?academic_year=Y2&facility=F1%2CF2&booked_from=2026-11-01&booked_to=2026-11-30&page=2&per_page=50',
    )

    await api.listFacilityBookings({ facility: [], booked_from: '' })
    expect(last()[0]).toBe('/facility-bookings')
  })

  it('el identificador de ruta se codifica en el detalle y en la cancelación (#385)', async () => {
    await api.getFacilityBooking(HOSTILE)
    expect(last()[0]).toBe(`/facility-bookings/${ENCODED}`)

    await api.deleteFacilityBooking(HOSTILE)
    expect(last()[0]).toBe(`/facility-bookings/${ENCODED}`)
    expect(last()[1]?.method).toBe('DELETE')
  })

  it('POST envía el cuerpo tal cual, con el curso académico', async () => {
    const body = {
      academic_year: 'Y2',
      facility: 'F2',
      booked_on: '2026-11-12',
      starts_at: '10:00',
      ends_at: '11:00',
    }

    await api.createFacilityBooking(body)

    expect(last()[0]).toBe('/facility-bookings')
    expect(last()[1]?.method).toBe('POST')
    expect(JSON.parse(last()[1]!.body!)).toEqual(body)
  })

  it('no hay función de edición: las reservas se cancelan y se vuelven a hacer (RN-ACAD-142)', () => {
    expect(Object.keys(api).sort()).toEqual([
      'createFacilityBooking',
      'deleteFacilityBooking',
      'getFacilityBooking',
      'listFacilityBookings',
    ])
  })
})
