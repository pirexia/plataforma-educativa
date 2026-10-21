/**
 * `docs/modulos/REQ-CURSO/api.md §2.1` (1.12b), issue #385: el cliente de la apertura del curso siguiente codifica los
 * identificadores de la ruta y arma la simulación con los parámetros del contrato.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const apiFetch = vi.fn()

vi.mock('@/api/client', () => ({
  apiFetch: (...args: unknown[]) => apiFetch(...args),
}))

const {
  cancelRollover,
  getRollover,
  listRolloverElements,
  listRollovers,
  previewRollover,
  requestRollover,
  revertRollover,
} = await import('./rollovers')

const HOSTILE = '01ABC/../x?y=1#z'
const ENCODED = encodeURIComponent(HOSTILE)

beforeEach(() => {
  apiFetch.mockReset().mockResolvedValue({})
})

describe('REQ-CURSO-002 #385: rollovers codifica el identificador de la ruta', () => {
  it('getRollover, revertRollover y cancelRollover', async () => {
    await getRollover(HOSTILE)
    await revertRollover(HOSTILE)
    await cancelRollover(HOSTILE)

    expect(apiFetch.mock.calls[0]![0]).toBe(`/academic-year-rollovers/${ENCODED}`)
    expect(apiFetch.mock.calls[1]![0]).toBe(`/academic-year-rollovers/${ENCODED}/revert`)
    expect(apiFetch.mock.calls[1]![1]).toMatchObject({ method: 'POST' })
    expect(apiFetch.mock.calls[2]![0]).toBe(`/academic-year-rollovers/${ENCODED}/cancel`)
    expect(apiFetch.mock.calls[2]![1]).toMatchObject({ method: 'POST' })
  })
})

describe('CA-CURSO-153 RN-CURSO-82: la casilla viaja como booleano en la simulación y en la solicitud', () => {
  const selection = {
    academic_year: 'DEST',
    source: 'ORIG',
    elements: ['acad.education_levels', 'acad.courses'],
    copy_teaching_assignments: true,
  }

  it('previewRollover: parámetros planos, elementos separados por comas y copy_teaching_assignments true/false', async () => {
    await previewRollover(selection)
    await previewRollover({ ...selection, copy_teaching_assignments: false })

    const first = new URL(`http://x${apiFetch.mock.calls[0]![0]}`)
    const second = new URL(`http://x${apiFetch.mock.calls[1]![0]}`)

    expect(first.pathname).toBe('/academic-year-rollovers/preview')
    expect(first.searchParams.get('academic_year')).toBe('DEST')
    expect(first.searchParams.get('source')).toBe('ORIG')
    expect(first.searchParams.get('elements')).toBe('acad.education_levels,acad.courses')
    expect(first.searchParams.get('copy_teaching_assignments')).toBe('true')
    expect(second.searchParams.get('copy_teaching_assignments')).toBe('false')
  })

  it('requestRollover: POST con el cuerpo del contrato', async () => {
    await requestRollover(selection)

    expect(apiFetch.mock.calls[0]![0]).toBe('/academic-year-rollovers')
    expect(apiFetch.mock.calls[0]![1]).toMatchObject({ method: 'POST' })
    expect(JSON.parse(apiFetch.mock.calls[0]![1].body)).toEqual(selection)
  })
})

describe('REQ-CURSO-002: listados', () => {
  it('listRolloverElements', async () => {
    await listRolloverElements()

    expect(apiFetch.mock.calls[0]![0]).toBe('/academic-year-rollovers/elements')
  })

  it('listRollovers: destino, origen y varios estados separados por comas', async () => {
    await listRollovers({
      academic_year: 'DEST',
      source: 'ORIG',
      status: ['completado', 'fallido'],
    })

    const url = new URL(`http://x${apiFetch.mock.calls[0]![0]}`)

    expect(url.pathname).toBe('/academic-year-rollovers')
    expect(url.searchParams.get('academic_year')).toBe('DEST')
    expect(url.searchParams.get('source')).toBe('ORIG')
    expect(url.searchParams.get('status')).toBe('completado,fallido')
  })
})
