/**
 * REQ-ALUM (paso 1.15-d, T1; `docs/modulos/REQ-ALUM/api.md §1` y `§4`). Alumnado y fotografía, en la forma exacta de
 * `apps/api/openapi/paths/alum.yaml`. Permisos de cada ruta: `api.md`.
 */
import { apiFetch } from '@/api/client'
import type { GuardianStudentLink } from '@/modules/fam-unit/types'
import type {
  Paginated,
  PublicId,
  Student,
  StudentCreate,
  StudentPhotoUrl,
  StudentSummary,
  StudentUpdate,
} from '../types'
import { buildQuery, encode } from './query'

const NO_STORE: RequestInit = { cache: 'no-store' }

export interface ListStudentsParams {
  q?: string
  /** `public_id` del curso académico; solo filtra combinado con `course`, `group` o `enrollment_status`. */
  academic_year?: PublicId
  /** `course`, `group` y `enrollment_status` exigen `matricula.leer` (`403 filter_forbidden` sin él). */
  course?: PublicId
  group?: PublicId
  enrollment_status?: string
  /** Exige `alumno.eliminar`. */
  include_deleted?: string
  sort?: string
  page?: number
  per_page?: number
}

/** `GET /students` (`alumno.leer`, ámbito). */
export function listStudents(params: ListStudentsParams = {}): Promise<Paginated<StudentSummary>> {
  return apiFetch<Paginated<StudentSummary>>(`/students${buildQuery({ ...params })}`)
}

/** `GET /students/{id}` (`alumno.leer`, ámbito). Un alumno dado de baja o fuera de ámbito es `404`. */
export function getStudent(publicId: PublicId): Promise<Student> {
  return apiFetch<Student>(`/students/${encode(publicId)}`)
}

/**
 * `POST /students` (`alumno.crear`, `Idempotency-Key` **obligatoria**, `RN-ALUM-27`): una clave por intento de envío,
 * reutilizada en los reintentos del mismo cuerpo. Un `201` con `Idempotency-Replayed: true` es el mismo resultado.
 */
export function createStudent(body: StudentCreate, idempotencyKey: string): Promise<Student> {
  return apiFetch<Student>('/students', {
    method: 'POST',
    headers: { 'Idempotency-Key': idempotencyKey },
    body: JSON.stringify(body),
  })
}

/** `PATCH /students/{id}` (`alumno.actualizar`): solo las claves presentes. */
export function updateStudent(publicId: PublicId, body: StudentUpdate): Promise<Student> {
  return apiFetch<Student>(`/students/${encode(publicId)}`, {
    method: 'PATCH',
    body: JSON.stringify(body),
  })
}

/** `DELETE /students/{id}` (`alumno.eliminar`): baja lógica, `204`. */
export function deleteStudent(publicId: PublicId): Promise<null> {
  return apiFetch<null>(`/students/${encode(publicId)}`, { method: 'DELETE' })
}

/** `POST /students/{id}/restore` (`alumno.eliminar`): `200` con la ficha. */
export function restoreStudent(publicId: PublicId): Promise<Student> {
  return apiFetch<Student>(`/students/${encode(publicId)}/restore`, { method: 'POST' })
}

/**
 * `PUT /students/{id}/photo` (`alumno.actualizar`): `multipart/form-data` con el campo `file`. Sin `Content-Type` a
 * mano: `apiFetch` deja que el navegador fije el `boundary` cuando el cuerpo es `FormData`.
 */
export function putStudentPhoto(publicId: PublicId, file: File): Promise<{ has_photo: true }> {
  const form = new FormData()

  form.append('file', file)

  return apiFetch<{ has_photo: true }>(`/students/${encode(publicId)}/photo`, {
    method: 'PUT',
    body: form,
  })
}

/** `DELETE /students/{id}/photo` (`alumno.actualizar`): `204`, idempotente. */
export function deleteStudentPhoto(publicId: PublicId): Promise<null> {
  return apiFetch<null>(`/students/${encode(publicId)}/photo`, { method: 'DELETE' })
}

/**
 * `GET /students/{id}/photo` (`alumno.leer`, ámbito): URL firmada de 5 minutos, **auditada** (`RN-ALUM-52`) y con cupo
 * de 300 lecturas por hora. Solo se llama por una acción explícita, nunca al montar (`frontend-spa` regla 7).
 */
export function getStudentPhotoUrl(publicId: PublicId): Promise<StudentPhotoUrl> {
  return apiFetch<StudentPhotoUrl>(`/students/${encode(publicId)}/photo`, NO_STORE)
}

/**
 * `GET /guardian-links?student_person=` (`fam_unit`, `vinculo_tutor.leer`, ámbito): los vínculos de tutor de un alumno
 * por su **persona**, para la ficha (`funcional.md §10`). **Auditada** (`read`, `RPERM-015`): solo por acción
 * explícita. La API de `fam_unit` ya admite el filtro (`openapi/paths/fam-unit.yaml`), pero el cliente de `fam-unit`
 * no lo declara en sus parámetros y un módulo no se toca desde otro (`INV-007`): la llamada vive aquí.
 */
export function listStudentGuardianLinks(
  studentPerson: PublicId,
  params: { page?: number; per_page?: number } = {},
): Promise<Paginated<GuardianStudentLink>> {
  return apiFetch<Paginated<GuardianStudentLink>>(
    `/guardian-links${buildQuery({ student_person: studentPerson, ...params })}`,
    NO_STORE,
  )
}
