/**
 * `docs/modulos/REQ-ACAD/api.md §11` (1.11d-b), `CA-ACAD-099`: rutas, métodos y
 * cuerpos del cliente de los cinco endpoints de `/grading-scales`; el
 * identificador de ruta se codifica y los filtros múltiples viajan separados
 * por comas (`ADR-038 §5.2`). Los decimales salen como cadena. `REQ-ACAD-001`.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const apiFetch = vi.fn()

vi.mock('@/api/client', () => ({
  apiFetch: (...args: unknown[]) => apiFetch(...args),
}))

const api = await import('./scales')

const HOSTILE = '01ABC/../x?y=1#z'
const ENCODED = encodeURIComponent(HOSTILE)

beforeEach(() => {
  apiFetch.mockReset().mockResolvedValue({})
})

function last(): [string, { method?: string; body?: string } | undefined] {
  return apiFetch.mock.calls.at(-1) as [string, { method?: string; body?: string } | undefined]
}

describe('CA-ACAD-099: cliente de /grading-scales', () => {
  it('GET con curso, tipos múltiples, orden y paginación', async () => {
    await api.listGradingScales({
      academic_year: 'Y2',
      scale_type: ['numerica', 'mixta'],
      sort: '-code',
      page: 2,
      per_page: 50,
    })

    expect(last()[0]).toBe(
      '/grading-scales?academic_year=Y2&scale_type=numerica%2Cmixta&sort=-code&page=2&per_page=50',
    )
    expect(last()[1]).toBeUndefined()
  })

  it('GET sin parámetros: sin querystring (curso activo en el servidor)', async () => {
    await api.listGradingScales()

    expect(last()[0]).toBe('/grading-scales')
  })

  it('GET, PATCH y DELETE codifican el public_id', async () => {
    await api.getGradingScale(HOSTILE)
    expect(last()[0]).toBe(`/grading-scales/${ENCODED}`)

    await api.updateGradingScale(HOSTILE, { code: 'X' })
    expect(last()[0]).toBe(`/grading-scales/${ENCODED}`)
    expect(last()[1]?.method).toBe('PATCH')

    await api.deleteGradingScale(HOSTILE)
    expect(last()[0]).toBe(`/grading-scales/${ENCODED}`)
    expect(last()[1]?.method).toBe('DELETE')
  })

  it('POST envía el cuerpo tal cual, con decimales como cadena', async () => {
    await api.createGradingScale({
      academic_year: 'Y2',
      code: 'NUM10',
      name_translations: { 'es-ES': 'De 0 a 10' },
      scale_type: 'numerica',
      min_value: '0',
      max_value: '10',
      decimals: 1,
      passing_min_value: '5',
    })

    expect(last()[0]).toBe('/grading-scales')
    expect(last()[1]?.method).toBe('POST')
    expect(JSON.parse(last()[1]!.body!)).toMatchObject({ min_value: '0', passing_min_value: '5' })
  })
})
