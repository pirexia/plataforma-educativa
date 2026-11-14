/**
 * REQ-ACAD/api.md §12 (paso 1.12-b): los cuatro endpoints de `/facility-bookings`
 * (permisos `reservas.*`, `eliminar` con ámbito `todos` o `propios`,
 * `OPEN-ACAD-42`). Las reservas no se editan (`RN-ACAD-142`): no hay `PATCH`.
 * En el alta, `academic_year` **sí** viaja en el cuerpo (una reserva no tiene
 * padre con curso, `RN-ACAD-140`).
 */
import { apiFetch } from '@/api/client'
import type { FacilityBooking, Paginated, PublicId } from '../types'

function query(params: object): string {
  const search = new URLSearchParams()

  for (const [key, value] of Object.entries(params)) {
    if (Array.isArray(value)) {
      if (value.length > 0) {
        search.set(key, value.join(','))
      }
    } else if (value !== undefined && value !== '') {
      search.set(key, String(value))
    }
  }

  const text = search.toString()

  return text ? `?${text}` : ''
}

/** `api.md §12.4`: orden fijo (`booked_on`, `starts_at`, `id`); fechas `AAAA-MM-DD` inclusivas. */
export interface ListFacilityBookingsParams {
  academic_year?: PublicId
  facility?: PublicId[]
  booked_from?: string
  booked_to?: string
  page?: number
  per_page?: number
}

function bookingPath(publicId?: PublicId): string {
  return publicId === undefined
    ? '/facility-bookings'
    : `/facility-bookings/${encodeURIComponent(publicId)}`
}

export function listFacilityBookings(
  params: ListFacilityBookingsParams = {},
): Promise<Paginated<FacilityBooking>> {
  return apiFetch<Paginated<FacilityBooking>>(`${bookingPath()}${query(params)}`)
}

export function getFacilityBooking(publicId: PublicId): Promise<FacilityBooking> {
  return apiFetch<FacilityBooking>(bookingPath(publicId))
}

export interface FacilityBookingCreate {
  academic_year: PublicId
  facility: PublicId
  booked_on: string
  starts_at: string
  ends_at: string
}

export function createFacilityBooking(payload: FacilityBookingCreate): Promise<FacilityBooking> {
  return apiFetch<FacilityBooking>(bookingPath(), {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export function deleteFacilityBooking(publicId: PublicId): Promise<void> {
  return apiFetch<void>(bookingPath(publicId), { method: 'DELETE' })
}
