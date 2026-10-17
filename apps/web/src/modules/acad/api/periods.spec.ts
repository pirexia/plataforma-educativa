/**
 * `docs/modulos/REQ-ACAD/api.md §11` (1.11d-a), `CA-ACAD-103`: rutas, métodos y
 * cuerpos del cliente de los cinco endpoints de `/academic-periods`; el
 * identificador de ruta se codifica (#385) y los filtros múltiples viajan
 * separados por comas (`ADR-038 §5.2`). `REQ-ACAD-001`.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const apiFetch = vi.fn()

vi.mock('@/api/client', () => ({
  apiFetch: (...args: unknown[]) => apiFetch(...args),
}))

const api = await import('./periods')

const HOSTILE = '01ABC/../x?y=1#z'
const ENCODED = encodeURIComponent(HOSTILE)

beforeEach(() => {
  apiFetch.mockReset().mockResolvedValue({})
})

function last(): [string, { method?: string; body?: string } | undefined] {
  return apiFetch.mock.calls.at(-1) as [string, { method?: string; body?: string } | undefined]
}

describe('CA-ACAD-103: el listado de la ficha de etapa lleva academic_year y education_level', () => {
  it('GET /academic-periods con los dos filtros y per_page', async () => {
    await api.listAcademicPeriods({
      academic_year: 'Y3',
      education_level: ['L1'],
      per_page: 100,
    })

    expect(last()[0]).toBe('/academic-periods?academic_year=Y3&education_level=L1&per_page=100')
    expect(last()[1]).toBeUndefined()
  })

  it('varios filtros viajan separados por comas y los vacíos se omiten', async () => {
    await api.listAcademicPeriods({
      education_level: ['L1', 'L2'],
      period_type: ['trimestre', 'semestre'],
      sort: '-starts_on',
      page: 2,
    })

    expect(last()[0]).toBe(
      '/academic-periods?education_level=L1%2CL2&period_type=trimestre%2Csemestre&sort=-starts_on&page=2',
    )

    await api.listAcademicPeriods()

    expect(last()[0]).toBe('/academic-periods')
  })
})

describe('REQ-ACAD-001 (api.md §11.3): métodos, rutas y cuerpos', () => {
  it('el identificador de ruta se codifica (#385)', async () => {
    await api.getAcademicPeriod(HOSTILE)
    expect(last()[0]).toBe(`/academic-periods/${ENCODED}`)

    await api.updateAcademicPeriod(HOSTILE, { position: 2 })
    expect(last()[0]).toBe(`/academic-periods/${ENCODED}`)

    await api.deleteAcademicPeriod(HOSTILE)
    expect(last()[0]).toBe(`/academic-periods/${ENCODED}`)
  })

  it('POST envía el cuerpo tal cual (sin academic_year)', async () => {
    const body = {
      education_level: 'L1',
      period_type: 'trimestre' as const,
      name_translations: { 'es-ES': 'Primero' },
      starts_on: '2026-09-08',
      ends_on: '2026-12-22',
    }

    await api.createAcademicPeriod(body)

    expect(last()[0]).toBe('/academic-periods')
    expect(last()[1]?.method).toBe('POST')
    expect(JSON.parse(last()[1]!.body!)).toEqual(body)
  })

  it('PATCH y DELETE usan su verbo', async () => {
    await api.updateAcademicPeriod('P1', { ends_on: '2026-12-19' })
    expect(last()[1]?.method).toBe('PATCH')
    expect(JSON.parse(last()[1]!.body!)).toEqual({ ends_on: '2026-12-19' })

    await api.deleteAcademicPeriod('P1')
    expect(last()[1]?.method).toBe('DELETE')
  })
})
