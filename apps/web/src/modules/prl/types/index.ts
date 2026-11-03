/**
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §23.1` y `docs/modulos/REQ-PRL/api.md §8.4`-`§8.7` (`1.14b-c2`, `REQ-PRL-004`).
 * Formas que consume la SPA de `apps/api/openapi/components.yaml` (`DoorStudent`, `DoorPickupList`, `PickupListEntry`,
 * `PickupAlert`). `prl` no importa nada de `fam-unit` (`AR-11`): las formas de la lista de puerta se definen aquí.
 * Los enumerados son extensibles en la lectura (`ADR-038 §7.3`): un código desconocido se muestra en crudo.
 */
import type { CursorPaginated, Paginated, PublicId } from '@/modules/core/types'

export type { CursorPaginated, Paginated, PublicId }

/** `GET /door/students?q=`: un alumno del buscador (sin fotografía, §23.6). */
export interface DoorStudent {
  student_person: PublicId
  display_name: string
  family_unit_code: string | null
}

export interface DoorStudentList {
  data: DoorStudent[]
}

export interface DoorMaskedDocument {
  type: string
  masked: string
}

export interface DoorPickupBasis {
  basis: 'tutor' | 'tercero' | 'puntual' | string
  relationship?: string
  guardian_link?: PublicId
  authorization?: PublicId
  valid_from?: string | null
  valid_until?: string | null
}

/** `PickupListEntry`: una persona de la lista de puerta; sin `public_id` propio. */
export interface PickupListEntry {
  person: PublicId
  display_name: string
  bases: DoorPickupBasis[]
  has_photo: boolean
  /** URL firmada de 5 minutos: solo en memoria del componente (UI-3). */
  photo_url: string | null
  photo_url_expires_at: string | null
  contact_phone: string | null
  /** Enmascarado siempre (`RN-FAM-UNIT-90`). */
  document: DoorMaskedDocument | null
}

/** `DoorPickupList`. */
export interface DoorPickupList {
  data: PickupListEntry[]
  meta: { as_of: string; protocol_available: boolean }
}

export type PickupAlertStatus = 'abierta' | 'cerrada'

export type PickupAlertResolution =
  'no_entregado' | 'recogido_por_autorizado' | 'autorizacion_puntual_registrada' | 'otro'

/** `protocol` y `contacts` solo en el alta y en el detalle mientras la alerta está abierta (`DP-5`). */
export interface PickupAlert {
  public_id: PublicId
  student_person: PublicId
  /** Nombre visible (1.14b-c2); vacío si la persona está borrada. Opcional por compatibilidad hacia atrás. */
  student_display_name?: string
  kind: string
  presenter_description: string
  status: PickupAlertStatus | string
  raised_by: PublicId
  raised_by_display_name?: string
  raised_at: string
  principal_contacted_at: string | null
  resolution: PickupAlertResolution | string | null
  resolution_notes: string | null
  resolved_by: PublicId | null
  resolved_by_display_name?: string | null
  resolved_at: string | null
  protocol?: string
  contacts?: { principal_phone: string | null; emergency_phone: string | null }
}

export type MovementDirection = 'salida' | 'entrada'

/** `StudentMovement`: solo anexar (`RN-PRL-04`). `basis` y `verification_method` son `null` en una entrada. */
export interface StudentMovement {
  public_id: PublicId
  student_person: PublicId
  direction: MovementDirection | string
  off_schedule: boolean
  off_schedule_reason: string | null
  notes: string | null
  picked_up_by: { person: PublicId; display_name: string } | null
  basis: string | null
  verification_method: string | null
  delivered_by: PublicId
  recorded_by: PublicId
  corrects: PublicId | null
  correction_reason: string | null
  occurred_at: string
}
