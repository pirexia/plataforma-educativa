/**
 * REQ-ALUM (paso 1.15-d, T1). Tipos del módulo en la forma exacta en que la API los entrega (`ADR-038 §3`: snake_case,
 * sin mapeo a camelCase). Coherentes con `apps/api/openapi/paths/alum.yaml` y `components.yaml` (`StudentSummary`,
 * `Student`, `Enrollment`, `StudentPhotoUrl`, `StudentPhotoStored`); contrato en `docs/modulos/REQ-ALUM/api.md §1` y `§4`.
 */
import type { Paginated, PublicId } from '@/modules/core/types'

export type { Paginated, PublicId }

/** `api.md §2`: enumerado extensible (`ADR-038 §7.3`). */
export type EnrollmentStatus = 'activa' | 'baja' | (string & {})

/** `Enrollment` (`api.md §2`), forma de `StudentSummary.enrollment` y de cada elemento de `Student.enrollments[]`. */
export interface Enrollment {
  public_id: PublicId
  student: { public_id: PublicId; display_name: string }
  academic_year: { public_id: PublicId; code: string }
  course: { public_id: PublicId; code: string; name_translations: Record<string, string> }
  group: { public_id: PublicId; code: string; name: string } | null
  status: EnrollmentStatus
  enrolled_on: string
  withdrawn_on: string | null
  withdrawal_reason: string | null
  created_at: string
  updated_at: string
}

/** `GET /students` (`api.md §1`). `enrollment` es `null` sin filtro de curso o año, sin matrícula o sin `matricula.leer`. */
export interface StudentSummary {
  public_id: PublicId
  person: { public_id: PublicId }
  display_name: string
  birth_date: string | null
  enrollment: Enrollment | null
  created_at: string
  updated_at: string
  /** Solo en una baja listada con `include_deleted`. */
  deleted_at?: string
}

/**
 * Ficha del alumno (`api.md §1`). `document_number` llega **enmascarado** (`•••••1234`): nunca el documento completo.
 * `has_photo` es solo el booleano: la URL firmada solo sale de `GET /students/{id}/photo`.
 */
export interface Student {
  public_id: PublicId
  person: { public_id: PublicId }
  display_name: string
  given_name: string
  family_name_1: string
  family_name_2: string | null
  birth_date: string | null
  locale: string | null
  document_type: string | null
  document_number: string | null
  has_photo: boolean
  enrollments: Enrollment[]
  created_at: string
  updated_at: string
  deleted_at?: string
}

/** `POST /students` (`api.md §1`). */
export interface StudentCreate {
  given_name: string
  family_name_1: string
  birth_date: string
  family_name_2?: string | null
  document_type?: string | null
  document_number?: string | null
  locale?: string | null
  family_unit?: PublicId | null
}

/** `PATCH /students/{id}` (`api.md §1`): solo las claves modificadas; `family_unit` no se admite. */
export interface StudentUpdate {
  given_name?: string
  family_name_1?: string
  family_name_2?: string | null
  birth_date?: string
  document_type?: string | null
  document_number?: string | null
  locale?: string
}

/** `GET /students/{id}/photo` (`api.md §4`): URL firmada de caducidad corta. */
export interface StudentPhotoUrl {
  url: string
  expires_at: string
}

/** `POST /enrollments` (`api.md §2`). `group` es opcional (matrícula sin grupo, `RN-ALUM-28`). */
export interface EnrollmentCreate {
  student: PublicId
  academic_year: PublicId
  course: PublicId
  group?: PublicId | null
  enrolled_on: string
}

/** `PATCH /enrollments/{id}` (`api.md §2`, `RN-ALUM-28`): fecha y primer grupo; el resto es inmutable. */
export interface EnrollmentUpdate {
  enrolled_on?: string
  group?: PublicId
}

/** `POST /enrollments/{id}/withdrawal` (`api.md §2`). */
export interface EnrollmentWithdrawal {
  withdrawn_on?: string
  withdrawal_reason?: string
}

/** Filtros de `POST /enrollments/exports` (`academic_year` obligatorio; sin `q`, `api.md §6`). */
export interface EnrollmentExportRequest {
  academic_year: PublicId
  course?: PublicId
  group?: PublicId
  status?: string
}

/** `202` de `POST /enrollments/exports`: seguimiento en `GET /data-exports/{public_id}`. */
export interface EnrollmentExport {
  public_id: PublicId
  status: string
}

/** `api.md §3`: enumerado extensible (`ADR-038 §7.3`). */
export type GroupChangeStatus = 'pendiente' | 'aprobada' | 'rechazada' | 'cancelada' | (string & {})

interface GroupView {
  public_id: PublicId
  code: string
  name: string
}

interface PersonView {
  public_id: PublicId
  display_name: string
}

/**
 * `GroupChangeRequest` (`api.md §3`, forma [DERIVADA] del recurso `GroupChangeRequestResource`). `from_group` y
 * `to_group` pueden ser `null` si el grupo ya no es legible; `requested_by`/`decided_by`, igual.
 */
export interface GroupChangeRequest {
  public_id: PublicId
  enrollment: { public_id: PublicId; student: { public_id: PublicId; display_name: string } }
  academic_year: { public_id: PublicId; code: string }
  from_group: GroupView | null
  to_group: GroupView | null
  reason: string
  status: GroupChangeStatus
  requested_by: PersonView | null
  decided_by: PersonView | null
  decided_at: string | null
  decision_note: string | null
  created_at: string
  updated_at: string
}

/** `POST /group-change-requests` (`api.md §3`): `reason` 1-500. */
export interface GroupChangeCreate {
  enrollment: PublicId
  to_group: PublicId
  reason: string
}

/** Cuerpo de `approval`, `rejection` y `cancellation` (`decision_note` 1-500; obligatoria solo en `rejection`). */
export interface GroupChangeDecision {
  decision_note?: string
}
