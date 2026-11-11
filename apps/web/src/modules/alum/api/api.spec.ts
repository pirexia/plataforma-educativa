/**
 * `docs/modulos/REQ-ALUM/api.md §1` y `§4` (1.15-d T1): rutas, métodos y cuerpos del cliente del módulo. El
 * identificador de ruta se codifica; los vacíos se omiten; `POST /students` lleva `Idempotency-Key`; la foto sube como
 * `FormData` con el campo `file` y sin `Content-Type` a mano. `REQ-ALUM-001`.
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

describe('REQ-ALUM-001 (api.md §1): alumnado', () => {
  it('GET /students con búsqueda, filtros, orden y paginación; los vacíos se omiten', async () => {
    await api.listStudents({
      q: 'Lu',
      academic_year: 'AY1',
      course: 'C1',
      group: 'G1',
      enrollment_status: 'activa',
      include_deleted: 'true',
      sort: '-created_at',
      page: 2,
      per_page: 25,
    })
    expect(last()[0]).toBe(
      '/students?q=Lu&academic_year=AY1&course=C1&group=G1&enrollment_status=activa&include_deleted=true&sort=-created_at&page=2&per_page=25',
    )

    await api.listStudents({ q: '' })
    expect(last()[0]).toBe('/students')
  })

  it('GET /students/{id} codifica el identificador', async () => {
    await api.getStudent(HOSTILE)
    expect(last()[0]).toBe(`/students/${ENCODED}`)
  })

  it('POST /students lleva la Idempotency-Key y el cuerpo', async () => {
    await api.createStudent(
      { given_name: 'Lucas', family_name_1: 'Ejemplo', birth_date: '2015-04-12' },
      '01JB0000000000000000000001',
    )

    const [url, init] = last()

    expect(url).toBe('/students')
    expect(init?.method).toBe('POST')
    expect(init?.headers).toEqual({ 'Idempotency-Key': '01JB0000000000000000000001' })
    expect(JSON.parse(init!.body as string)).toEqual({
      given_name: 'Lucas',
      family_name_1: 'Ejemplo',
      birth_date: '2015-04-12',
    })
  })

  it('PATCH /students/{id} envía solo las claves dadas', async () => {
    await api.updateStudent('S1', { family_name_2: null })

    expect(last()).toEqual([
      '/students/S1',
      { method: 'PATCH', body: JSON.stringify({ family_name_2: null }) },
    ])
  })

  it('DELETE /students/{id} y POST /students/{id}/restore', async () => {
    await api.deleteStudent('S1')
    expect(last()).toEqual(['/students/S1', { method: 'DELETE' }])

    await api.restoreStudent('S1')
    expect(last()).toEqual(['/students/S1/restore', { method: 'POST' }])
  })
})

describe('REQ-ALUM-001 (funcional.md §10): vínculos de tutor de la ficha', () => {
  it('GET /guardian-links?student_person= no se cachea', async () => {
    await api.listStudentGuardianLinks('P1', { per_page: 100 })

    expect(last()).toEqual([
      '/guardian-links?student_person=P1&per_page=100',
      { cache: 'no-store' },
    ])
  })
})

describe('REQ-ALUM-001 (api.md §4): fotografía', () => {
  it('PUT /students/{id}/photo sube un FormData con el campo file y sin Content-Type', async () => {
    const file = new File(['x'], 'foto.png', { type: 'image/png' })

    await api.putStudentPhoto('S1', file)

    const [url, init] = last()

    expect(url).toBe('/students/S1/photo')
    expect(init?.method).toBe('PUT')
    expect(init?.headers).toBeUndefined()
    expect(init?.body).toBeInstanceOf(FormData)
    expect((init!.body as FormData).get('file')).toBe(file)
  })

  it('DELETE /students/{id}/photo', async () => {
    await api.deleteStudentPhoto('S1')
    expect(last()).toEqual(['/students/S1/photo', { method: 'DELETE' }])
  })

  it('GET /students/{id}/photo no se cachea y codifica el identificador', async () => {
    await api.getStudentPhotoUrl(HOSTILE)

    expect(last()).toEqual([`/students/${ENCODED}/photo`, { cache: 'no-store' }])
  })
})
