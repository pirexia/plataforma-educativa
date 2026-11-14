/**
 * `docs/modulos/REQ-ACAD/api.md §2` (1.11-b): rutas, métodos y cuerpos del
 * cliente de los 27 endpoints; el identificador de ruta se codifica (#385) y los
 * filtros múltiples viajan separados por comas (`ADR-038 §5.2`).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const apiFetch = vi.fn()

vi.mock('@/api/client', () => ({
  apiFetch: (...args: unknown[]) => apiFetch(...args),
}))

const api = await import('./structure')

const HOSTILE = '01ABC/../x?y=1#z'
const ENCODED = encodeURIComponent(HOSTILE)

beforeEach(() => {
  apiFetch.mockReset().mockResolvedValue({})
})

function last(): [string, { method?: string; body?: string } | undefined] {
  return apiFetch.mock.calls.at(-1) as [string, { method?: string; body?: string } | undefined]
}

describe('REQ-ACAD-001: el identificador de ruta se codifica (#385)', () => {
  it.each([
    ['getEducationLevel', () => api.getEducationLevel(HOSTILE), `/education-levels/${ENCODED}`],
    [
      'deleteEducationLevel',
      () => api.deleteEducationLevel(HOSTILE),
      `/education-levels/${ENCODED}`,
    ],
    ['updateCourse', () => api.updateCourse(HOSTILE, {}), `/courses/${ENCODED}`],
    ['getGroup', () => api.getGroup(HOSTILE), `/groups/${ENCODED}`],
    ['listSubgroups', () => api.listSubgroups(HOSTILE), `/groups/${ENCODED}/subgroups`],
    [
      'createSubgroup',
      () => api.createSubgroup(HOSTILE, { code: 'D1', name: 'x' }),
      `/groups/${ENCODED}/subgroups`,
    ],
    ['getSubgroup', () => api.getSubgroup(HOSTILE), `/subgroups/${ENCODED}`],
    ['getSubject', () => api.getSubject(HOSTILE), `/subjects/${ENCODED}`],
    ['listGroupSubjects', () => api.listGroupSubjects(HOSTILE), `/groups/${ENCODED}/subjects`],
    [
      'replaceGroupSubjects',
      () => api.replaceGroupSubjects(HOSTILE, []),
      `/groups/${ENCODED}/subjects`,
    ],
  ])('%s', async (_name, call, expected) => {
    await call()

    expect(last()[0]).toBe(expected)
  })
})

describe('REQ-ACAD-001: métodos y cuerpos', () => {
  it('POST /education-levels lleva academic_year y name_translations', async () => {
    await api.createEducationLevel({
      academic_year: 'Y1',
      code: 'ESO',
      name_translations: { 'es-ES': 'ESO' },
      stage: 'eso',
      regime: 'concertado',
    })

    expect(last()[0]).toBe('/education-levels')
    expect(last()[1]!.method).toBe('POST')
    expect(JSON.parse(last()[1]!.body!)).toMatchObject({ academic_year: 'Y1', stage: 'eso' })
  })

  it('PATCH envía solo lo que se le pasa, con null para quitar un idioma', async () => {
    await api.updateSubject('M1', { name_translations: { fr: 'Maths', de: null } })

    expect(last()[1]!.method).toBe('PATCH')
    expect(JSON.parse(last()[1]!.body!)).toEqual({ name_translations: { fr: 'Maths', de: null } })
  })

  it('PUT /groups/{id}/subjects envía {subjects: […]}', async () => {
    await api.replaceGroupSubjects('G1', ['M1', 'M2'])

    expect(last()[1]!.method).toBe('PUT')
    expect(JSON.parse(last()[1]!.body!)).toEqual({ subjects: ['M1', 'M2'] })
  })

  it('DELETE usa el método DELETE', async () => {
    await api.deleteSubgroup('S1')

    expect(last()).toEqual(['/subgroups/S1', { method: 'DELETE' }])
  })
})

describe('REQ-ACAD-001: filtros y orden (api.md §3)', () => {
  it('los filtros múltiples viajan separados por comas y se omiten los vacíos', async () => {
    await api.listEducationLevels({
      academic_year: 'Y1',
      stage: ['eso', 'primaria'],
      regime: [],
      sort: '-code',
      page: 2,
      per_page: 50,
    })

    const url = new URL(last()[0], 'http://x')

    expect(url.pathname).toBe('/education-levels')
    expect(url.searchParams.get('stage')).toBe('eso,primaria')
    expect(url.searchParams.has('regime')).toBe(false)
    expect(url.searchParams.get('academic_year')).toBe('Y1')
    expect(url.searchParams.get('sort')).toBe('-code')
  })

  it('sin parámetros no hay query', async () => {
    await api.listGroups()

    expect(last()[0]).toBe('/groups')
  })

  it('listados por padre', async () => {
    await api.listSubjects({ course: ['C1', 'C2'] })

    expect(last()[0]).toBe('/subjects?course=C1%2CC2')
  })
})
