/**
 * `docs/modulos/REQ-FAM-UNIT/api.md §8.1` a `§8.3` (`1.14b-c1`, `REQ-FAM-UNIT-005`): rutas, métodos y cuerpos de los 13
 * *endpoints* de personas autorizadas a recoger. El identificador de ruta se codifica; la subida de fotografía es
 * `multipart/form-data` con el campo `file` y sin `Content-Type` escrito a mano.
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

describe('REQ-FAM-UNIT-005 (api.md §8.2): personas y fotografías', () => {
  it('busca por documento, da de alta, lee, corrige y gestiona la fotografía', async () => {
    await api.findPickupPerson('dni', '00000000T')
    expect(last()[0]).toBe('/pickup-persons?document_type=dni&document_number=00000000T')

    await api.createPickupPerson({ given_name: 'Rosa', family_name_1: 'Ejemplo' })
    expect(last()[0]).toBe('/pickup-persons')
    expect(last()[1]?.method).toBe('POST')

    await api.getPickupPerson(HOSTILE)
    expect(last()[0]).toBe(`/pickup-persons/${ENCODED}`)

    await api.updatePickupPerson(HOSTILE, { contact_phone: '600000001' })
    expect(last()[0]).toBe(`/pickup-persons/${ENCODED}`)
    expect(last()[1]).toMatchObject({ method: 'PATCH', body: '{"contact_phone":"600000001"}' })

    await api.getPickupPhotoUrl(HOSTILE)
    expect(last()[0]).toBe(`/pickup-persons/${ENCODED}/photo`)

    await api.deletePickupPersonPhoto(HOSTILE)
    expect(last()[0]).toBe(`/pickup-persons/${ENCODED}/photo`)
    expect(last()[1]?.method).toBe('DELETE')
  })

  it('PUT .../photo envía multipart con el campo file y sin Content-Type propio', async () => {
    const file = new File(['x'], 'foto.png', { type: 'image/png' })

    await api.putPickupPersonPhoto(HOSTILE, file)

    const [path, init] = last()

    expect(path).toBe(`/pickup-persons/${ENCODED}/photo`)
    expect(init?.method).toBe('PUT')
    expect(init?.body).toBeInstanceOf(FormData)
    expect((init?.body as FormData).get('file')).toBeInstanceOf(File)
    expect(init?.headers).toBeUndefined()
  })
})

describe('REQ-FAM-UNIT-005 (api.md §8.3): autorizaciones y lista vigente', () => {
  it('lista con filtros, orden y paginación; los vacíos se omiten', async () => {
    await api.listPickupAuthorizations({
      student_person: 'P1',
      effective: true,
      sort: '-valid_from',
      page: 2,
      per_page: 25,
    })
    expect(last()[0]).toBe(
      '/pickup-authorizations?student_person=P1&effective=true&sort=-valid_from&page=2&per_page=25',
    )

    await api.listPickupAuthorizations()
    expect(last()[0]).toBe('/pickup-authorizations')
  })

  it('lee, crea, cambia la fecha de fin y retira', async () => {
    await api.getPickupAuthorization(HOSTILE)
    expect(last()[0]).toBe(`/pickup-authorizations/${ENCODED}`)

    await api.updatePickupAuthorizationValidity(HOSTILE, null)
    expect(last()[1]).toMatchObject({ method: 'PATCH', body: '{"valid_until":null}' })

    await api.withdrawPickupAuthorization(HOSTILE)
    expect(last()[0]).toBe(`/pickup-authorizations/${ENCODED}/withdrawal`)
    expect(last()[1]?.method).toBe('POST')

    await api.createPickupAuthorization({
      student_person: 'P1',
      person: 'PP1',
      relationship: 'abuelo',
      kind: 'permanente',
      valid_from: '2026-11-02',
      authorized_by: 'G1',
      terms_version: 'V1',
      channel: 'presencial',
    })
    expect(last()[0]).toBe('/pickup-authorizations')
    expect(last()[1]?.method).toBe('POST')
  })

  it('GET /pickup-lists/{alumno}', async () => {
    await api.getPickupList(HOSTILE)
    expect(last()[0]).toBe(`/pickup-lists/${ENCODED}`)
  })
})

describe('REQ-FAM-UNIT-005 (UI-3): lecturas con datos personales sin caché HTTP del navegador', () => {
  it('lista vigente, ficha, búsqueda por documento y URL firmada llevan cache: no-store', async () => {
    await api.getPickupList('P1')
    expect(last()[1]).toMatchObject({ cache: 'no-store' })

    await api.getPickupPerson('PP1')
    expect(last()[1]).toMatchObject({ cache: 'no-store' })

    await api.findPickupPerson('dni', '00000000T')
    expect(last()[1]).toMatchObject({ cache: 'no-store' })

    await api.getPickupPhotoUrl('PP1')
    expect(last()[1]).toMatchObject({ cache: 'no-store' })
  })
})
