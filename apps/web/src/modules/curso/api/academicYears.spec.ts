/**
 * Issue #385: los identificadores que entran en una ruta se codifican
 * (`encodeURIComponent`), también los de `REQ-CURSO-001`.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const apiFetch = vi.fn()

vi.mock('@/api/client', () => ({
  apiFetch: (...args: unknown[]) => apiFetch(...args),
}))

const { getAcademicYear, transitionAcademicYear, updateAcademicYear } =
  await import('./academicYears')

const HOSTILE = '01ABC/../x?y=1#z'
const ENCODED = encodeURIComponent(HOSTILE)

beforeEach(() => {
  apiFetch.mockReset().mockResolvedValue({})
})

describe('REQ-CURSO-001 #385: academicYears codifica el identificador de la ruta', () => {
  it('getAcademicYear', async () => {
    await getAcademicYear(HOSTILE)

    expect(apiFetch.mock.calls[0]![0]).toBe(`/academic-years/${ENCODED}`)
  })

  it('updateAcademicYear', async () => {
    await updateAcademicYear(HOSTILE, { code: '2026-2027' })

    expect(apiFetch.mock.calls[0]![0]).toBe(`/academic-years/${ENCODED}`)
  })

  it('transitionAcademicYear', async () => {
    await transitionAcademicYear(HOSTILE, 'activo')

    expect(apiFetch.mock.calls[0]![0]).toBe(`/academic-years/${ENCODED}/status`)
  })

  it('un ULID normal no cambia', async () => {
    await getAcademicYear('01JD7XXXXXXXXXXXXXXXXXXXXX')

    expect(apiFetch.mock.calls[0]![0]).toBe('/academic-years/01JD7XXXXXXXXXXXXXXXXXXXXX')
  })
})
