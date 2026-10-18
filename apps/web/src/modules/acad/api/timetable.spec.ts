/**
 * `docs/modulos/REQ-ACAD/api.md §12` (1.12-a), `CA-ACAD-117`/`CA-ACAD-119`: rutas,
 * métodos y cuerpos del cliente de los diez endpoints de `/facilities` y
 * `/timetable-frames`; el identificador de ruta se codifica (#385), los filtros
 * múltiples viajan separados por comas (`ADR-038 §5.2`) y las franjas viajan
 * solo dentro del marco (`RN-ACAD-113`). `REQ-ACAD-002`.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const apiFetch = vi.fn()

vi.mock('@/api/client', () => ({
  apiFetch: (...args: unknown[]) => apiFetch(...args),
}))

const api = await import('./timetable')

const HOSTILE = '01ABC/../x?y=1#z'
const ENCODED = encodeURIComponent(HOSTILE)

beforeEach(() => {
  apiFetch.mockReset().mockResolvedValue({})
})

function last(): [string, { method?: string; body?: string } | undefined] {
  return apiFetch.mock.calls.at(-1) as [string, { method?: string; body?: string } | undefined]
}

describe('REQ-ACAD-002 (api.md §12.2, §12.4): espacios', () => {
  it('GET /facilities con filtro múltiple, orden y paginación; los vacíos se omiten', async () => {
    await api.listFacilities({
      facility_type: ['aula', 'recurso'],
      sort: '-code',
      page: 2,
      per_page: 50,
    })
    expect(last()[0]).toBe('/facilities?facility_type=aula%2Crecurso&sort=-code&page=2&per_page=50')

    await api.listFacilities({ facility_type: [] })
    expect(last()[0]).toBe('/facilities')
  })

  it('el identificador de ruta se codifica (#385)', async () => {
    await api.getFacility(HOSTILE)
    expect(last()[0]).toBe(`/facilities/${ENCODED}`)

    await api.updateFacility(HOSTILE, { name: 'Otro' })
    expect(last()[0]).toBe(`/facilities/${ENCODED}`)
    expect(last()[1]?.method).toBe('PATCH')

    await api.deleteFacility(HOSTILE)
    expect(last()[0]).toBe(`/facilities/${ENCODED}`)
    expect(last()[1]?.method).toBe('DELETE')
  })

  it('POST envía el cuerpo tal cual y PATCH solo lo modificado', async () => {
    await api.createFacility({ facility_type: 'aula', code: 'A21', name: 'Aula 2.1' })

    expect(last()[0]).toBe('/facilities')
    expect(last()[1]?.method).toBe('POST')
    expect(JSON.parse(last()[1]!.body!)).toEqual({
      facility_type: 'aula',
      code: 'A21',
      name: 'Aula 2.1',
    })

    await api.updateFacility('F1', { code: 'A22' })
    expect(JSON.parse(last()[1]!.body!)).toEqual({ code: 'A22' })
  })
})

describe('REQ-ACAD-002 (api.md §12.2, §12.3): marcos horarios', () => {
  it('CA-ACAD-103: el listado de la ficha de etapa lleva academic_year y education_level', async () => {
    await api.listTimetableFrames({ academic_year: 'Y3', education_level: ['L1'], per_page: 100 })

    expect(last()[0]).toBe('/timetable-frames?academic_year=Y3&education_level=L1&per_page=100')
    expect(last()[1]).toBeUndefined()
  })

  it('el identificador de ruta se codifica (#385)', async () => {
    await api.getTimetableFrame(HOSTILE)
    expect(last()[0]).toBe(`/timetable-frames/${ENCODED}`)

    await api.updateTimetableFrame(HOSTILE, { slots: [] })
    expect(last()[0]).toBe(`/timetable-frames/${ENCODED}`)
    expect(last()[1]?.method).toBe('PATCH')

    await api.deleteTimetableFrame(HOSTILE)
    expect(last()[0]).toBe(`/timetable-frames/${ENCODED}`)
    expect(last()[1]?.method).toBe('DELETE')
  })

  it('POST no envía academic_year; las franjas viajan dentro del marco', async () => {
    await api.createTimetableFrame({
      education_level: 'L1',
      frame_type: 'ordinaria',
      name_translations: { 'es-ES': 'Horario general' },
      slots: [{ weekday: 1, starts_at: '08:30', ends_at: '09:25' }],
    })

    const body = JSON.parse(last()[1]!.body!)

    expect(last()[0]).toBe('/timetable-frames')
    expect(last()[1]?.method).toBe('POST')
    expect(body).not.toHaveProperty('academic_year')
    expect(body.slots).toEqual([{ weekday: 1, starts_at: '08:30', ends_at: '09:25' }])
  })

  it('PATCH envía el conjunto de franjas con public_id en las existentes', async () => {
    await api.updateTimetableFrame('M1', {
      name_translations: { en: 'General timetable' },
      slots: [
        { public_id: 'T1', weekday: 1, starts_at: '08:45', ends_at: '09:40' },
        { weekday: 2, starts_at: '08:30', ends_at: '09:25' },
      ],
    })

    expect(JSON.parse(last()[1]!.body!)).toEqual({
      name_translations: { en: 'General timetable' },
      slots: [
        { public_id: 'T1', weekday: 1, starts_at: '08:45', ends_at: '09:40' },
        { weekday: 2, starts_at: '08:30', ends_at: '09:25' },
      ],
    })
  })
})
