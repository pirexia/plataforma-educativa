/**
 * REQ-ACAD/api.md §12 (paso 1.12-a): los diez endpoints de `/facilities` (permisos
 * `espacios.*`) y `/timetable-frames` (permisos `horarios.*`). Las franjas solo
 * viajan dentro del marco (`RN-ACAD-113`): no hay *endpoint* de franjas. `name`
 * del marco es de solo lectura (`RN-ACAD-13`): se escribe `name_translations`;
 * en el alta, `academic_year` **no** se envía (es el de la etapa, `RN-ACAD-91`).
 */
import { apiFetch } from '@/api/client'
import type {
  Facility,
  FacilityType,
  FrameType,
  Paginated,
  PublicId,
  TimetableFrame,
  Translations,
} from '../types'

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

/** `api.md §12.4`: orden `code`/`-code`; filtro múltiple `facility_type`. */
export interface ListFacilitiesParams {
  facility_type?: string[]
  sort?: string
  page?: number
  per_page?: number
}

function facilityPath(publicId?: PublicId): string {
  return publicId === undefined ? '/facilities' : `/facilities/${encodeURIComponent(publicId)}`
}

export function listFacilities(params: ListFacilitiesParams = {}): Promise<Paginated<Facility>> {
  return apiFetch<Paginated<Facility>>(`${facilityPath()}${query(params)}`)
}

export function getFacility(publicId: PublicId): Promise<Facility> {
  return apiFetch<Facility>(facilityPath(publicId))
}

export interface FacilityCreate {
  facility_type: FacilityType
  code: string
  name: string
}

export function createFacility(payload: FacilityCreate): Promise<Facility> {
  return apiFetch<Facility>(facilityPath(), { method: 'POST', body: JSON.stringify(payload) })
}

/** `RN-ACAD-100`: el tipo es inmutable; solo `code` y `name` (solo las claves modificadas, `ADR-038 §9.2`). */
export interface FacilityUpdate {
  code?: string
  name?: string
}

export function updateFacility(publicId: PublicId, payload: FacilityUpdate): Promise<Facility> {
  return apiFetch<Facility>(facilityPath(publicId), {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
}

export function deleteFacility(publicId: PublicId): Promise<void> {
  return apiFetch<void>(facilityPath(publicId), { method: 'DELETE' })
}

/** `api.md §12.4`: la SPA pide siempre `academic_year` junto a `education_level` desde la ficha de etapa. */
export interface ListTimetableFramesParams {
  academic_year?: PublicId
  education_level?: PublicId[]
  frame_type?: string[]
  sort?: string
  page?: number
  per_page?: number
}

function framePath(publicId?: PublicId): string {
  return publicId === undefined
    ? '/timetable-frames'
    : `/timetable-frames/${encodeURIComponent(publicId)}`
}

export function listTimetableFrames(
  params: ListTimetableFramesParams = {},
): Promise<Paginated<TimetableFrame>> {
  return apiFetch<Paginated<TimetableFrame>>(`${framePath()}${query(params)}`)
}

export function getTimetableFrame(publicId: PublicId): Promise<TimetableFrame> {
  return apiFetch<TimetableFrame>(framePath(publicId))
}

/** Franja nueva dentro de `slots` (sin `public_id`, `RN-ACAD-113`). */
export interface TimeSlotInput {
  /** Con `public_id` actualiza esa franja del marco; sin él, la da de alta. */
  public_id?: PublicId
  weekday: number
  starts_at: string
  ends_at: string
}

export interface TimetableFrameCreate {
  education_level: PublicId
  frame_type: FrameType
  name_translations: Translations
  slots?: TimeSlotInput[]
}

export function createTimetableFrame(payload: TimetableFrameCreate): Promise<TimetableFrame> {
  return apiFetch<TimetableFrame>(framePath(), { method: 'POST', body: JSON.stringify(payload) })
}

/** `name_translations` se fusiona por idioma; `slots` es el conjunto completo (`RN-ACAD-113`). */
export interface TimetableFrameUpdate {
  name_translations?: Record<string, string | null>
  slots?: TimeSlotInput[]
}

export function updateTimetableFrame(
  publicId: PublicId,
  payload: TimetableFrameUpdate,
): Promise<TimetableFrame> {
  return apiFetch<TimetableFrame>(framePath(publicId), {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
}

export function deleteTimetableFrame(publicId: PublicId): Promise<void> {
  return apiFetch<void>(framePath(publicId), { method: 'DELETE' })
}
