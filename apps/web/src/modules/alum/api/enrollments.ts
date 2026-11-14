/**
 * REQ-ALUM (paso 1.15-d, T2a; `docs/modulos/REQ-ALUM/api.md §2` y `§6`). Matrícula y exportación, en la forma de
 * `apps/api/openapi/paths/alum.yaml`. Sin `Idempotency-Key` (`RN-ALUM-27`): el reintento es `422 already_enrolled` o
 * `409 already_withdrawn`, el resultado correcto.
 */
import { apiFetch } from '@/api/client'
import type {
  Enrollment,
  EnrollmentCreate,
  EnrollmentExport,
  EnrollmentExportRequest,
  EnrollmentUpdate,
  EnrollmentWithdrawal,
  Paginated,
  PublicId,
} from '../types'
import { buildQuery, encode } from './query'

export interface ListEnrollmentsParams {
  /** `public_id` del curso académico. Obligatorio en `GET /enrollments` (`422 alum.validation.required` si falta). */
  academic_year?: PublicId
  course?: PublicId
  group?: PublicId
  student?: PublicId
  status?: string
  sort?: string
  page?: number
  per_page?: number
}

/** `GET /enrollments` (`matricula.leer`, ámbito). */
export function listEnrollments(
  params: ListEnrollmentsParams = {},
): Promise<Paginated<Enrollment>> {
  return apiFetch<Paginated<Enrollment>>(`/enrollments${buildQuery({ ...params })}`)
}

/** `GET /enrollments/{id}` (`matricula.leer`, ámbito). */
export function getEnrollment(publicId: PublicId): Promise<Enrollment> {
  return apiFetch<Enrollment>(`/enrollments/${encode(publicId)}`)
}

/** `POST /enrollments` (`matricula.crear`): `201`. */
export function createEnrollment(body: EnrollmentCreate): Promise<Enrollment> {
  return apiFetch<Enrollment>('/enrollments', { method: 'POST', body: JSON.stringify(body) })
}

/** `PATCH /enrollments/{id}` (`matricula.actualizar`): fecha y primer grupo (`RN-ALUM-28`). */
export function updateEnrollment(publicId: PublicId, body: EnrollmentUpdate): Promise<Enrollment> {
  return apiFetch<Enrollment>(`/enrollments/${encode(publicId)}`, {
    method: 'PATCH',
    body: JSON.stringify(body),
  })
}

/** `POST /enrollments/{id}/withdrawal` (`matricula.actualizar`): baja con fecha y motivo. */
export function withdrawEnrollment(
  publicId: PublicId,
  body: EnrollmentWithdrawal = {},
): Promise<Enrollment> {
  return apiFetch<Enrollment>(`/enrollments/${encode(publicId)}/withdrawal`, {
    method: 'POST',
    body: JSON.stringify(body),
  })
}

/** `DELETE /enrollments/{id}` (`matricula.eliminar`): anulación, `204`. */
export function deleteEnrollment(publicId: PublicId): Promise<null> {
  return apiFetch<null>(`/enrollments/${encode(publicId)}`, { method: 'DELETE' })
}

/** `POST /enrollments/exports` (`matricula.exportar`): `202`, CSV en cola (`INV-012`). */
export function exportEnrollments(body: EnrollmentExportRequest): Promise<EnrollmentExport> {
  return apiFetch<EnrollmentExport>('/enrollments/exports', {
    method: 'POST',
    body: JSON.stringify(body),
  })
}
