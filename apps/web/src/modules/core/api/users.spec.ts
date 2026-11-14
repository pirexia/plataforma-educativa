/**
 * `docs/modulos/REQ-CORE/funcional.md §14.4.1`, `RN-CORE-69`, `ADR-054 §8.2`:
 * la función de solicitud de la exportación de usuarios traduce la forma de
 * *query string* (`status=a,b`) al *array* JSON del cuerpo, y nunca envía
 * `q`, `sort`, `page` ni `per_page` (`CA-CORE-222`).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const apiFetch = vi.fn()

vi.mock('@/api/client', () => ({
  apiFetch: (...args: unknown[]) => apiFetch(...args),
}))

const { exportUsers, getUser, listUsers } = await import('./users')

beforeEach(() => {
  apiFetch.mockReset().mockResolvedValue({ public_id: 'EXP1' })
})

describe('CA-CORE-222 (RN-CORE-69): exportUsers', () => {
  it('traduce las listas por comas a arrays y no envía nada más', async () => {
    await exportUsers({
      status: 'activo,inactivo',
      role: 'R1',
      locale: 'es-ES,en',
      include_deleted: 'true',
      q: 'ana',
      sort: '-email',
      page: '2',
      per_page: '50',
    })

    expect(apiFetch).toHaveBeenCalledTimes(1)

    const [path, init] = apiFetch.mock.calls[0]!

    expect(path).toBe('/users/exports')
    expect(init.method).toBe('POST')
    expect(JSON.parse(init.body)).toEqual({
      status: ['activo', 'inactivo'],
      role: ['R1'],
      locale: ['es-ES', 'en'],
      include_deleted: true,
    })
  })

  it('sin filtros envía un cuerpo vacío', async () => {
    await exportUsers({})

    expect(JSON.parse(apiFetch.mock.calls[0]![1].body)).toEqual({})
  })
})

describe('listUsers y getUser', () => {
  it('listUsers envía locale por comas', async () => {
    apiFetch.mockResolvedValue({ data: [], meta: {} })
    await listUsers({ locale: ['es-ES', 'en'], sort: '-email' })

    expect(apiFetch.mock.calls[0]![0]).toBe('/users?locale=es-ES%2Cen&sort=-email')
  })

  it('getUser solo envía include_deleted cuando se pide', async () => {
    apiFetch.mockResolvedValue({})
    await getUser('U1')
    await getUser('U1', { include_deleted: false })
    await getUser('U1', { include_deleted: true })

    expect(apiFetch.mock.calls.map((call) => call[0])).toEqual([
      '/users/U1',
      '/users/U1',
      '/users/U1?include_deleted=true',
    ])
  })
})
