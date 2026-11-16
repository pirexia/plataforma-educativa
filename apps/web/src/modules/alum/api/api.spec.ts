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

describe('REQ-ALUM-003 (api.md §2 y §6): matrícula y exportación', () => {
  it('GET /enrollments y /enrollments/{id}: los vacíos se omiten y el id se codifica', async () => {
    await api.listEnrollments({ academic_year: 'Y1', student: 'S1', group: '', page: 2 })
    expect(last()[0]).toBe('/enrollments?academic_year=Y1&student=S1&page=2')

    await api.getEnrollment(HOSTILE)
    expect(last()[0]).toBe(`/enrollments/${ENCODED}`)
  })

  it('POST /enrollments, PATCH, withdrawal y DELETE sin Idempotency-Key (RN-ALUM-27)', async () => {
    await api.createEnrollment({
      student: 'S1',
      academic_year: 'Y1',
      course: 'C1',
      group: 'G1',
      enrolled_on: '2026-09-10',
    })
    expect(last()[0]).toBe('/enrollments')
    expect(last()[1]?.method).toBe('POST')
    expect(last()[1]?.headers).toBeUndefined()

    await api.updateEnrollment('E1', { group: 'G2' })
    expect(last()[0]).toBe('/enrollments/E1')
    expect(last()[1]?.method).toBe('PATCH')

    await api.withdrawEnrollment('E1', {
      withdrawn_on: '2026-10-01',
      withdrawal_reason: 'Traslado',
    })
    expect(last()[0]).toBe('/enrollments/E1/withdrawal')
    expect(JSON.parse(last()[1]!.body as string)).toEqual({
      withdrawn_on: '2026-10-01',
      withdrawal_reason: 'Traslado',
    })

    await api.deleteEnrollment('E1')
    expect(last()).toEqual(['/enrollments/E1', { method: 'DELETE' }])
  })

  it('POST /enrollments/exports lleva los filtros', async () => {
    await api.exportEnrollments({ academic_year: 'Y1', group: 'G1' })
    expect(last()[0]).toBe('/enrollments/exports')
    expect(last()[1]?.method).toBe('POST')
    expect(JSON.parse(last()[1]!.body as string)).toEqual({ academic_year: 'Y1', group: 'G1' })
  })
})

describe('REQ-ALUM-003 (api.md §3): solicitudes de cambio de grupo', () => {
  it('GET /group-change-requests y /{id}', async () => {
    await api.listGroupChangeRequests({ academic_year: 'Y1', status: 'pendiente' })
    expect(last()[0]).toBe('/group-change-requests?academic_year=Y1&status=pendiente')

    await api.getGroupChangeRequest(HOSTILE)
    expect(last()[0]).toBe(`/group-change-requests/${ENCODED}`)
  })

  it('POST /group-change-requests sin Idempotency-Key', async () => {
    await api.createGroupChangeRequest({ enrollment: 'E1', to_group: 'G2', reason: 'Motivo' })
    expect(last()[0]).toBe('/group-change-requests')
    expect(last()[1]?.method).toBe('POST')
    expect(last()[1]?.headers).toBeUndefined()
  })

  it('approval, rejection y cancellation son POST con decision_note', async () => {
    await api.approveGroupChangeRequest('R1')
    expect(last()[0]).toBe('/group-change-requests/R1/approval')

    await api.rejectGroupChangeRequest('R1', { decision_note: 'No procede' })
    expect(last()[0]).toBe('/group-change-requests/R1/rejection')
    expect(JSON.parse(last()[1]!.body as string)).toEqual({ decision_note: 'No procede' })

    await api.cancelGroupChangeRequest('R1')
    expect(last()[0]).toBe('/group-change-requests/R1/cancellation')
    expect(last()[1]?.method).toBe('POST')
  })
})
