/**
 * REQ-ACAD/api.md §12 (paso 1.12-a; las reservas, de 1.12-b, en `bookings.ts`): los diez endpoints de `/facilities` (permisos
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
  StaffSummary,
  TeachingAssignment,
  TimetableFrame,
  TimetableSession,
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
  /** Solo en un marco `especial` (`RN-ACAD-111`): fechas civiles inclusivas `AAAA-MM-DD`. */
  valid_from?: string
  valid_to?: string
}

export function createTimetableFrame(payload: TimetableFrameCreate): Promise<TimetableFrame> {
  return apiFetch<TimetableFrame>(framePath(), { method: 'POST', body: JSON.stringify(payload) })
}

/**
 * `name_translations` se fusiona por idioma; `slots` es el conjunto completo
 * (`RN-ACAD-113`); `valid_from`/`valid_to` solo en un marco `especial` (`RN-ACAD-111`).
 */
export interface TimetableFrameUpdate {
  name_translations?: Record<string, string | null>
  slots?: TimeSlotInput[]
  valid_from?: string
  valid_to?: string
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

// --- Asignación docente y tutorías (`RN-ACAD-92` a `-99`) ------------------------

/** `api.md §12.4`: filtros múltiples (`public_id` separados por coma) y orden `id`/`-id`. */
export interface ListTeachingAssignmentsParams {
  academic_year?: PublicId
  group?: PublicId[]
  subject?: PublicId[]
  teacher?: PublicId[]
  sort?: string
  page?: number
  per_page?: number
}

function assignmentPath(publicId?: PublicId): string {
  return publicId === undefined
    ? '/teaching-assignments'
    : `/teaching-assignments/${encodeURIComponent(publicId)}`
}

export function listTeachingAssignments(
  params: ListTeachingAssignmentsParams = {},
): Promise<Paginated<TeachingAssignment>> {
  return apiFetch<Paginated<TeachingAssignment>>(`${assignmentPath()}${query(params)}`)
}

/** `RN-ACAD-93`: sin `academic_year` (es el del grupo, `RN-ACAD-91`). */
export interface TeachingAssignmentCreate {
  teacher: PublicId
  group: PublicId
  subject: PublicId
  subgroup?: PublicId | null
}

export function createTeachingAssignment(
  payload: TeachingAssignmentCreate,
): Promise<TeachingAssignment> {
  return apiFetch<TeachingAssignment>(assignmentPath(), {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

/** `RN-ACAD-95`: sin `PATCH`; cambiar de profesor es borrar y crear. */
export function deleteTeachingAssignment(publicId: PublicId): Promise<void> {
  return apiFetch<void>(assignmentPath(publicId), { method: 'DELETE' })
}

function tutorsPath(groupPublicId: PublicId): string {
  return `/groups/${encodeURIComponent(groupPublicId)}/tutors`
}

export function listGroupTutors(groupPublicId: PublicId): Promise<{ data: StaffSummary[] }> {
  return apiFetch<{ data: StaffSummary[] }>(tutorsPath(groupPublicId))
}

/** `RN-ACAD-97`: sustituye el conjunto completo; lista vacía = sin tutores. */
export function replaceGroupTutors(
  groupPublicId: PublicId,
  tutors: PublicId[],
): Promise<{ data: StaffSummary[] }> {
  return apiFetch<{ data: StaffSummary[] }>(tutorsPath(groupPublicId), {
    method: 'PUT',
    body: JSON.stringify({ tutors }),
  })
}

// --- Sesiones y vistas semanales (`RN-ACAD-120` a `-128`) -------------------------

/** `RN-ACAD-127`: al menos uno de `teacher`, `facility`, `group`, `subject`. */
export interface ListTimetableSessionsParams {
  academic_year?: PublicId
  teacher?: PublicId
  facility?: PublicId
  group?: PublicId
  subject?: PublicId
  frame?: PublicId
}

function sessionPath(publicId?: PublicId): string {
  return publicId === undefined
    ? '/timetable-sessions'
    : `/timetable-sessions/${encodeURIComponent(publicId)}`
}

export function listTimetableSessions(
  params: ListTimetableSessionsParams,
): Promise<{ data: TimetableSession[] }> {
  return apiFetch<{ data: TimetableSession[] }>(`${sessionPath()}${query(params)}`)
}

export function getTimetableSession(publicId: PublicId): Promise<TimetableSession> {
  return apiFetch<TimetableSession>(sessionPath(publicId))
}

export interface TimetableSessionCreate {
  time_slot: PublicId
  teaching_assignment: PublicId
  facility?: PublicId | null
}

export function createTimetableSession(payload: TimetableSessionCreate): Promise<TimetableSession> {
  return apiFetch<TimetableSession>(sessionPath(), {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

/** `RN-ACAD-122`: `time_slot` y `facility` (`null` quita el aula). */
export interface TimetableSessionUpdate {
  time_slot?: PublicId
  facility?: PublicId | null
}

export function updateTimetableSession(
  publicId: PublicId,
  payload: TimetableSessionUpdate,
): Promise<TimetableSession> {
  return apiFetch<TimetableSession>(sessionPath(publicId), {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
}

export function deleteTimetableSession(publicId: PublicId): Promise<void> {
  return apiFetch<void>(sessionPath(publicId), { method: 'DELETE' })
}
